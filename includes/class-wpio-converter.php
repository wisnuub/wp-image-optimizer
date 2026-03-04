<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles local image conversion to WebP / AVIF.
 * Supported extensions are read dynamically from plugin options.
 */
class WPIO_Converter {

    public static function convert( $source_path, $format = 'webp', $quality = 82 ) {
        if ( WPIO_Remote::is_enabled() ) {
            $result = WPIO_Remote::convert( $source_path, $format, $quality );
            if ( ! is_wp_error( $result ) ) return $result;
        }
        return self::convert_local( $source_path, $format, $quality );
    }

    public static function convert_local( $source_path, $format = 'webp', $quality = 82 ) {
        if ( ! file_exists( $source_path ) ) {
            return new WP_Error( 'file_not_found', 'Source image not found: ' . $source_path );
        }

        $info      = pathinfo( $source_path );
        $dest_path = $info['dirname'] . '/' . $info['filename'] . '.' . $format;

        if ( file_exists( $dest_path ) ) return $dest_path;

        $ext     = strtolower( $info['extension'] );
        $allowed = class_exists( 'WPIO_Folder_Scanner' )
            ? WPIO_Folder_Scanner::get_allowed_extensions()
            : array( 'jpg', 'jpeg', 'png', 'gif' );

        if ( ! in_array( $ext, $allowed ) ) {
            return new WP_Error( 'unsupported_type', 'Unsupported or disabled image type: ' . $ext );
        }

        if ( get_option( 'wpio_backup_enabled', '1' ) === '1' ) {
            WPIO_Backup::backup( $source_path );
        }

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

    private static function convert_gd( $src, $dest, $format, $quality ) {
        $ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg': $image = imagecreatefromjpeg( $src ); break;
            case 'png':  $image = imagecreatefrompng( $src );  break;
            case 'gif':  $image = imagecreatefromgif( $src );  break;
            default: return new WP_Error( 'unsupported', 'Unsupported format: ' . $ext );
        }
        if ( ! $image ) return new WP_Error( 'gd_create_failed', 'GD could not open image.' );

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
        $files   = WPIO_Folder_Scanner::get_pending_images( $format );
        $results = array( 'success' => array(), 'skipped' => array(), 'error' => array() );
        foreach ( $files as $path ) {
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
