<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles local image conversion to WebP / AVIF.
 * Routes through remote server if enabled via WPIO_Remote.
 * Features: EXIF stripping, smart resize (max-dimension or fixed-width), backup.
 */
class WPIO_Converter {

    /**
     * Main entry: route to remote or local.
     */
    public static function convert( $source_path, $format = 'webp', $quality = 82 ) {
        if ( WPIO_Remote::is_enabled() ) {
            $result = WPIO_Remote::convert( $source_path, $format, $quality );
            if ( ! is_wp_error( $result ) ) return $result;
            // Fallback to local on error
        }
        return self::convert_local( $source_path, $format, $quality );
    }

    /**
     * Local conversion only (GD or Imagick).
     */
    public static function convert_local( $source_path, $format = 'webp', $quality = 82 ) {
        if ( ! file_exists( $source_path ) ) {
            return new WP_Error( 'file_not_found', 'Source image not found: ' . $source_path );
        }

        $info      = pathinfo( $source_path );
        $dest_path = $info['dirname'] . '/' . $info['filename'] . '.' . $format;

        if ( file_exists( $dest_path ) ) return $dest_path;

        $ext = strtolower( $info['extension'] );
        if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ) ) ) {
            return new WP_Error( 'unsupported_type', 'Unsupported image type: ' . $ext );
        }

        // Backup before converting
        if ( get_option( 'wpio_backup_enabled', '1' ) === '1' ) {
            WPIO_Backup::backup( $source_path );
        }

        if ( extension_loaded( 'imagick' ) ) {
            return self::convert_imagick( $source_path, $dest_path, $format, $quality );
        } elseif ( extension_loaded( 'gd' ) ) {
            return self::convert_gd( $source_path, $dest_path, $format, $quality );
        }

        return new WP_Error( 'no_library', 'Neither GD nor Imagick is available.' );
    }

    private static function convert_gd( $src, $dest, $format, $quality ) {
        $ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg': $image = imagecreatefromjpeg( $src ); break;
            case 'png':  $image = imagecreatefrompng( $src );  break;
            case 'gif':  $image = imagecreatefromgif( $src );  break;
            default: return new WP_Error( 'unsupported', 'Unsupported format' );
        }
        if ( ! $image ) return new WP_Error( 'gd_create_failed', 'GD could not open image.' );

        $image  = self::maybe_resize_gd( $image );
        $result = false;
        if ( $format === 'webp' && function_exists( 'imagewebp' ) ) {
            $result = imagewebp( $image, $dest, $quality );
        } elseif ( $format === 'avif' && function_exists( 'imageavif' ) ) {
            $result = imageavif( $image, $dest, $quality );
        }
        imagedestroy( $image );
        if ( ! $result ) return new WP_Error( 'gd_convert_failed', 'GD conversion failed for: ' . basename( $src ) );
        return $dest;
    }

    private static function convert_imagick( $src, $dest, $format, $quality ) {
        try {
            $im = new Imagick( $src );
            if ( get_option( 'wpio_strip_exif', '1' ) === '1' ) $im->stripImage();

            // Smart resize
            $resize_mode = get_option( 'wpio_resize_mode', 'max_dimension' );
            $max_dim     = (int) get_option( 'wpio_max_dimension', 0 );
            $max_width   = (int) get_option( 'wpio_max_width', 0 );
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();

            if ( $resize_mode === 'max_dimension' && $max_dim > 0 && ( $w > $max_dim || $h > $max_dim ) ) {
                $im->resizeImage( $max_dim, $max_dim, Imagick::FILTER_LANCZOS, 1, true );
            } elseif ( $resize_mode === 'max_width' && $max_width > 0 && $w > $max_width ) {
                $new_h = (int) round( $h * ( $max_width / $w ) );
                $im->resizeImage( $max_width, $new_h, Imagick::FILTER_LANCZOS, 1, false );
            }

            $im->setImageCompressionQuality( $quality );
            $im->setFormat( strtoupper( $format ) );
            $im->writeImage( $dest );
            $im->clear();
            $im->destroy();
            return $dest;
        } catch ( Exception $e ) {
            return new WP_Error( 'imagick_failed', $e->getMessage() );
        }
    }

    private static function maybe_resize_gd( $image ) {
        $resize_mode = get_option( 'wpio_resize_mode', 'max_dimension' );
        $max_dim     = (int) get_option( 'wpio_max_dimension', 0 );
        $max_width   = (int) get_option( 'wpio_max_width', 0 );
        $w = imagesx( $image );
        $h = imagesy( $image );

        $new_w = $w; $new_h = $h;

        if ( $resize_mode === 'max_dimension' && $max_dim > 0 && ( $w > $max_dim || $h > $max_dim ) ) {
            $ratio = min( $max_dim / $w, $max_dim / $h );
            $new_w = (int) round( $w * $ratio );
            $new_h = (int) round( $h * $ratio );
        } elseif ( $resize_mode === 'max_width' && $max_width > 0 && $w > $max_width ) {
            $ratio = $max_width / $w;
            $new_w = $max_width;
            $new_h = (int) round( $h * $ratio );
        }

        if ( $new_w === $w && $new_h === $h ) return $image;

        $resized = imagecreatetruecolor( $new_w, $new_h );
        // Preserve PNG transparency
        imagealphablending( $resized, false );
        imagesavealpha( $resized, true );
        imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
        imagedestroy( $image );
        return $resized;
    }

    /**
     * Legacy batch (used by WP-CLI). For admin bulk, use WPIO_Queue.
     */
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
