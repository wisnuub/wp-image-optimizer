<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Custom folder scanner.
 *
 * Scans one or more configured directories for images,
 * supporting both the default /uploads folder and any
 * custom absolute or relative-to-ABSPATH paths.
 */
class WPIO_Folder_Scanner {

    /**
     * Get the list of configured folders to scan.
     * Stored as newline-separated paths in option 'wpio_custom_folders'.
     *
     * @return array  Array of absolute directory paths.
     */
    public static function get_folders() {
        $upload_dir  = wp_upload_dir();
        $default     = $upload_dir['basedir'];
        $custom_raw  = get_option( 'wpio_custom_folders', '' );
        $folders     = array( $default );

        if ( ! empty( $custom_raw ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $custom_raw ) ) );
            foreach ( $lines as $line ) {
                // Support relative paths (relative to ABSPATH)
                if ( ! path_is_absolute( $line ) ) {
                    $line = rtrim( ABSPATH, '/' ) . '/' . ltrim( $line, '/' );
                }
                $real = realpath( $line );
                if ( $real && is_dir( $real ) && ! in_array( $real, $folders ) ) {
                    $folders[] = $real;
                }
            }
        }

        return $folders;
    }

    /**
     * Scan all configured folders and return list of unconverted image paths.
     *
     * @param string $format  Target format to check against.
     * @return array
     */
    public static function get_pending_images( $format = 'webp' ) {
        $files   = array();
        $folders = self::get_folders();

        foreach ( $folders as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( strpos( $path, 'wpio-backups' ) !== false ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;
                $conv = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $path );
                if ( file_exists( $conv ) ) continue;
                $files[] = $path;
            }
        }

        return array_unique( $files );
    }

    /**
     * Get all images (converted + unconverted) across all folders.
     *
     * @param string $format
     * @return array  Array with 'total', 'converted', 'pending' counts.
     */
    public static function get_counts( $format = 'webp' ) {
        $total = $converted = 0;
        foreach ( self::get_folders() as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( strpos( $path, 'wpio-backups' ) !== false ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;
                $total++;
                $conv = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $path );
                if ( file_exists( $conv ) ) $converted++;
            }
        }
        return array( 'total' => $total, 'converted' => $converted, 'pending' => $total - $converted );
    }
}
