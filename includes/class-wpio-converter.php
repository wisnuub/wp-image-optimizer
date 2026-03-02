<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles image conversion to WebP / AVIF using GD or Imagick.
 */
class WPIO_Converter {

    /**
     * Convert a single image file to the target format.
     *
     * @param string $source_path  Absolute path to source image.
     * @param string $format       'webp' or 'avif'.
     * @param int    $quality      Compression quality (1-100).
     * @return string|WP_Error     Path to converted file or WP_Error.
     */
    public static function convert( $source_path, $format = 'webp', $quality = 82 ) {
        if ( ! file_exists( $source_path ) ) {
            return new WP_Error( 'file_not_found', 'Source image not found: ' . $source_path );
        }

        $info = pathinfo( $source_path );
        $dest_path = $info['dirname'] . '/' . $info['filename'] . '.' . $format;

        // Skip if already converted
        if ( file_exists( $dest_path ) ) {
            return $dest_path;
        }

        $ext = strtolower( $info['extension'] );
        $allowed = array( 'jpg', 'jpeg', 'png', 'gif' );
        if ( ! in_array( $ext, $allowed ) ) {
            return new WP_Error( 'unsupported_type', 'Unsupported image type: ' . $ext );
        }

        if ( extension_loaded( 'imagick' ) ) {
            return self::convert_imagick( $source_path, $dest_path, $format, $quality );
        } elseif ( extension_loaded( 'gd' ) ) {
            return self::convert_gd( $source_path, $dest_path, $format, $quality );
        }

        return new WP_Error( 'no_library', 'Neither GD nor Imagick is available on this server.' );
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

        $result = false;
        if ( $format === 'webp' && function_exists( 'imagewebp' ) ) {
            $result = imagewebp( $image, $dest, $quality );
        } elseif ( $format === 'avif' && function_exists( 'imageavif' ) ) {
            $result = imageavif( $image, $dest, $quality );
        }
        imagedestroy( $image );
        if ( ! $result ) return new WP_Error( 'gd_convert_failed', 'GD conversion failed.' );
        return $dest;
    }

    private static function convert_imagick( $src, $dest, $format, $quality ) {
        try {
            $im = new Imagick( $src );
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

    /**
     * Batch convert all images in the WordPress uploads directory.
     *
     * @param string $format  'webp' or 'avif'.
     * @param int    $quality Quality (1-100).
     * @return array          Array of results.
     */
    public static function batch_convert( $format = 'webp', $quality = 82 ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];
        $iterator   = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        $results = array( 'success' => array(), 'skipped' => array(), 'error' => array() );

        foreach ( $iterator as $file ) {
            if ( $file->isDir() ) continue;
            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;

            $result = self::convert( $file->getPathname(), $format, $quality );
            if ( is_wp_error( $result ) ) {
                $results['error'][] = array( 'file' => $file->getFilename(), 'error' => $result->get_error_message() );
            } else {
                $results['success'][] = $file->getFilename();
            }
        }
        return $results;
    }
}
