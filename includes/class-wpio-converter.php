<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles local image conversion to WebP / AVIF.
 * Supported extensions are read dynamically from plugin options.
 */
class WPIO_Converter {

    /**
     * Expand a format value into an array of concrete formats.
     * 'both' becomes ['webp','avif'], everything else stays as-is.
     */
    public static function get_formats( $format ) {
        if ( $format === 'both' ) return array( 'webp', 'avif' );
        return array( $format );
    }

    /**
     * Path of the converted copy for a source image: photo.jpg -> photo.jpg.webp.
     * Keeping the original extension means photo.jpg and photo.png never share
     * an output file, and a native photo.webp upload is never mistaken for one.
     */
    public static function converted_path( $source_path, $format ) {
        return $source_path . '.' . $format;
    }

    public static function convert( $source_path, $format = 'webp', $quality = 82 ) {
        $formats = self::get_formats( $format );
        $last    = null;
        foreach ( $formats as $fmt ) {
            if ( WPIO_Remote::is_enabled() ) {
                $result = WPIO_Remote::convert( $source_path, $fmt, $quality );
                if ( is_wp_error( $result ) ) {
                    $result = self::convert_local( $source_path, $fmt, $quality );
                }
            } else {
                $result = self::convert_local( $source_path, $fmt, $quality );
            }
            if ( is_wp_error( $result ) ) return $result;
            $last = $result;
        }
        return $last;
    }

    public static function convert_local( $source_path, $format = 'webp', $quality = 82 ) {
        if ( ! file_exists( $source_path ) ) {
            return new WP_Error( 'file_not_found', 'Source image not found: ' . $source_path );
        }

        $dest_path = self::converted_path( $source_path, $format );

        if ( file_exists( $dest_path ) ) return $dest_path;

        $ext     = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
        $allowed = class_exists( 'WPIO_Folder_Scanner' )
            ? WPIO_Folder_Scanner::get_allowed_extensions()
            : array( 'jpg', 'jpeg', 'png', 'gif' );

        if ( ! in_array( $ext, $allowed ) ) {
            return new WP_Error( 'unsupported_type', 'Unsupported or disabled image type: ' . $ext );
        }

        if ( get_option( 'wpio_backup_enabled', '1' ) === '1' ) {
            WPIO_Backup::backup( $source_path );
        }

        WPIO_Queue::raise_limits();

        $method = get_option( 'wpio_conversion_method', 'auto' );

        if ( $method === 'imagick' || ( $method === 'auto' && extension_loaded( 'imagick' ) ) ) {
            return self::convert_imagick( $source_path, $dest_path, $format, $quality );
        } elseif ( $method === 'gd' || ( $method === 'auto' && extension_loaded( 'gd' ) ) ) {
            return self::convert_gd( $source_path, $dest_path, $format, $quality );
        }

        return new WP_Error( 'no_library', 'Neither GD nor Imagick is available.' );
    }

    /**
     * Returns the target resize dimensions [new_w, new_h] or null if no resize needed.
     * Respects wpio_resize_enabled, wpio_max_width, wpio_max_height, keeping aspect ratio.
     */
    private static function get_resize_dims( $w, $h ) {
        if ( get_option( 'wpio_resize_enabled', '0' ) !== '1' ) return null;

        $max_w = (int) get_option( 'wpio_max_width',  0 );
        $max_h = (int) get_option( 'wpio_max_height', 0 );

        if ( $max_w <= 0 && $max_h <= 0 ) return null;

        $ratio = 1.0;
        if ( $max_w > 0 && $w > $max_w ) $ratio = min( $ratio, $max_w / $w );
        if ( $max_h > 0 && $h > $max_h ) $ratio = min( $ratio, $max_h / $h );

        if ( $ratio >= 1.0 ) return null; // image already fits

        return array(
            (int) round( $w * $ratio ),
            (int) round( $h * $ratio ),
        );
    }

    /**
     * Check if the converted file is genuinely smaller than the source.
     */
    private static function is_size_acceptable( $src, $dest ) {
        if ( ! file_exists( $dest ) ) return false;
        return filesize( $dest ) < filesize( $src );
    }

    private static function discard_if_larger( $src, $dest ) {
        if ( ! self::is_size_acceptable( $src, $dest ) ) {
            $dest_size = file_exists( $dest ) ? filesize( $dest ) : 0;
            @unlink( $dest );
            return new WP_Error(
                'output_larger',
                sprintf(
                    'Converted file (%s) is not smaller than original (%s) — skipped to preserve quality.',
                    size_format( $dest_size ),
                    size_format( filesize( $src ) )
                )
            );
        }
        return null;
    }

    /**
     * GD decodes the whole bitmap into PHP memory, so a 40MP photo needs ~200MB.
     * Running out of memory is a fatal error that can't be caught, so refuse
     * up front when the decode clearly won't fit in the current limit.
     */
    private static function gd_memory_check( $src ) {
        $size = @getimagesize( $src );
        if ( ! $size ) return new WP_Error( 'gd_unreadable', 'Could not read image dimensions: ' . basename( $src ) );

        $limit = WPIO_Environment::parse_memory( (string) ini_get( 'memory_limit' ) );
        if ( $limit === -1 ) return null;

        // 4 bytes/px truecolor bitmap, a second full copy while rotating, ~25% overhead.
        $copies = self::get_orientation( $src ) > 1 ? 2 : 1;
        $needed = $size[0] * $size[1] * 4 * $copies * 1.25;
        $dims   = self::get_resize_dims( $size[0], $size[1] );
        if ( $dims ) $needed += $dims[0] * $dims[1] * 4;

        if ( memory_get_usage( true ) + $needed > $limit * 1048576 ) {
            return new WP_Error(
                'too_large',
                sprintf(
                    '%s is %dx%d (~%dMB to decode) which exceeds the PHP memory limit (%s). Raise the memory limit in Expert settings.',
                    basename( $src ), $size[0], $size[1], (int) ( $needed / 1048576 ), ini_get( 'memory_limit' )
                )
            );
        }
        return null;
    }

    /**
     * EXIF orientation (1-8) for a JPEG, or 1 if unknown.
     */
    private static function get_orientation( $src ) {
        if ( ! function_exists( 'exif_read_data' ) ) return 1;
        $ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
        if ( $ext !== 'jpg' && $ext !== 'jpeg' ) return 1;
        $exif = @exif_read_data( $src );
        return ( $exif && ! empty( $exif['Orientation'] ) ) ? (int) $exif['Orientation'] : 1;
    }

    /**
     * The converted file carries no EXIF, so bake the orientation into the pixels
     * or the browser would show the photo rotated/mirrored.
     */
    private static function apply_orientation_gd( $image, $orientation ) {
        // [ anticlockwise rotation, flip applied after rotating ] per EXIF orientation.
        $ops = array(
            2 => array( 0,   IMG_FLIP_HORIZONTAL ),
            3 => array( 180, null ),
            4 => array( 0,   IMG_FLIP_VERTICAL ),
            5 => array( 90,  IMG_FLIP_VERTICAL ),   // transpose
            6 => array( -90, null ),
            7 => array( 90,  IMG_FLIP_HORIZONTAL ), // transverse
            8 => array( 90,  null ),
        );
        if ( ! isset( $ops[ $orientation ] ) ) return $image;
        list( $angle, $flip ) = $ops[ $orientation ];

        if ( $angle ) {
            $rotated = imagerotate( $image, $angle, 0 );
            if ( $rotated ) {
                imagedestroy( $image );
                $image = $rotated;
                imagealphablending( $image, false );
                imagesavealpha( $image, true );
            }
        }
        if ( $flip !== null ) imageflip( $image, $flip );
        return $image;
    }

    private static function convert_gd( $src, $dest, $format, $quality ) {
        $too_large = self::gd_memory_check( $src );
        if ( is_wp_error( $too_large ) ) return $too_large;

        $ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg': $image = imagecreatefromjpeg( $src ); break;
            case 'png':  $image = imagecreatefrompng( $src );  break;
            case 'gif':  $image = imagecreatefromgif( $src );  break;
            default: return new WP_Error( 'unsupported', 'Unsupported format: ' . $ext );
        }
        if ( ! $image ) return new WP_Error( 'gd_create_failed', 'GD could not open image.' );

        // WebP can't be written from palette images (8-bit PNG/GIF); keep transparency.
        if ( ! imageistruecolor( $image ) ) imagepalettetotruecolor( $image );
        imagealphablending( $image, false );
        imagesavealpha( $image, true );

        $image = self::apply_orientation_gd( $image, self::get_orientation( $src ) );
        $image = self::maybe_resize_gd( $image );

        $result = false;
        if ( $format === 'webp' && function_exists( 'imagewebp' ) ) {
            $result = imagewebp( $image, $dest, $quality );
        } elseif ( $format === 'avif' && function_exists( 'imageavif' ) ) {
            $result = imageavif( $image, $dest, $quality );
        }
        imagedestroy( $image );
        if ( ! $result ) return new WP_Error( 'gd_convert_failed', 'GD conversion failed for: ' . basename( $src ) );

        $size_check = self::discard_if_larger( $src, $dest );
        if ( is_wp_error( $size_check ) ) return $size_check;

        return $dest;
    }

    private static function convert_imagick( $src, $dest, $format, $quality ) {
        try {
            $im = new Imagick( $src );
            self::apply_orientation_imagick( $im );
            if ( get_option( 'wpio_strip_exif', '1' ) === '1' ) $im->stripImage();

            $dims = self::get_resize_dims( $im->getImageWidth(), $im->getImageHeight() );
            if ( $dims ) {
                $im->resizeImage( $dims[0], $dims[1], Imagick::FILTER_LANCZOS, 1, false );
            }

            $im->setImageCompressionQuality( $quality );
            $im->setFormat( strtoupper( $format ) );
            $im->writeImage( $dest );
            $im->clear();
            $im->destroy();

            $size_check = self::discard_if_larger( $src, $dest );
            if ( is_wp_error( $size_check ) ) return $size_check;

            return $dest;
        } catch ( Exception $e ) {
            return new WP_Error( 'imagick_failed', $e->getMessage() );
        }
    }

    private static function apply_orientation_imagick( $im ) {
        $o = $im->getImageOrientation();
        switch ( $o ) {
            case Imagick::ORIENTATION_TOPRIGHT:    $im->flopImage(); break;
            case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage( '#000', 180 ); break;
            case Imagick::ORIENTATION_BOTTOMLEFT:  $im->flipImage(); break;
            case Imagick::ORIENTATION_LEFTTOP:     $im->transposeImage(); break;
            case Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage( '#000', 90 ); break;
            case Imagick::ORIENTATION_RIGHTBOTTOM: $im->transverseImage(); break;
            case Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage( '#000', -90 ); break;
            default: return;
        }
        $im->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
    }

    private static function maybe_resize_gd( $image ) {
        $w    = imagesx( $image );
        $h    = imagesy( $image );
        $dims = self::get_resize_dims( $w, $h );

        if ( ! $dims ) return $image;

        list( $new_w, $new_h ) = $dims;
        $resized = imagecreatetruecolor( $new_w, $new_h );
        imagealphablending( $resized, false );
        imagesavealpha( $resized, true );
        imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
        imagedestroy( $image );
        return $resized;
    }

    public static function batch_convert( $format = 'webp', $quality = 82 ) {
        $formats = self::get_formats( $format );
        $all_pending = array();
        foreach ( $formats as $fmt ) {
            foreach ( WPIO_Folder_Scanner::get_pending_images( $fmt ) as $path ) {
                $all_pending[ $path ] = true;
            }
        }
        $results = array( 'success' => array(), 'skipped' => array(), 'error' => array() );
        foreach ( array_keys( $all_pending ) as $path ) {
            $result = self::convert( $path, $format, $quality );
            if ( is_wp_error( $result ) ) {
                $results['error'][] = array( 'file' => basename( $path ), 'error' => $result->get_error_message() );
            } else {
                $results['success'][] = basename( $path );
            }
        }
        return $results;
    }
}
