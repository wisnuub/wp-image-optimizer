<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIO_Folder_Scanner {

    /**
     * Get allowed extensions based on saved options.
     */
    public static function get_allowed_extensions() {
        $exts = array();
        if ( get_option( 'wpio_ext_jpg', '1' ) === '1' ) { $exts[] = 'jpg'; $exts[] = 'jpeg'; }
        if ( get_option( 'wpio_ext_png', '1' ) === '1' ) { $exts[] = 'png'; }
        if ( get_option( 'wpio_ext_gif', '0' ) === '1' ) { $exts[] = 'gif'; }
        return ! empty( $exts ) ? $exts : array( 'jpg', 'jpeg', 'png' );
    }

    /**
     * Get excluded directory name fragments from options.
     * Stored as comma-separated values in wpio_excluded_dirs.
     */
    public static function get_excluded_dirs() {
        $raw   = get_option( 'wpio_excluded_dirs', '' );
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        // Always exclude backups dir
        $parts[] = 'wpio-backups';
        return array_unique( $parts );
    }

    /**
     * Check if a file path should be excluded.
     */
    private static function is_excluded( $path ) {
        foreach ( self::get_excluded_dirs() as $fragment ) {
            if ( $fragment !== '' && strpos( $path, $fragment ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the list of configured folders to scan.
     */
    public static function get_folders() {
        $upload_dir = wp_upload_dir();
        $default    = $upload_dir['basedir'];
        $custom_raw = get_option( 'wpio_custom_folders', '' );
        $folders    = array( $default );

        if ( ! empty( $custom_raw ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $custom_raw ) ) );
            foreach ( $lines as $line ) {
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
     */
    public static function get_pending_images( $format = 'webp' ) {
        $files   = array();
        $allowed = self::get_allowed_extensions();

        foreach ( self::get_folders() as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( self::is_excluded( $path ) ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, $allowed ) ) continue;
                $conv = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $path );
                if ( file_exists( $conv ) ) continue;
                $files[] = $path;
            }
        }

        return array_unique( $files );
    }

    /**
     * Get total/converted/pending counts across all folders.
     */
    public static function get_counts( $format = 'webp' ) {
        $total = $converted = 0;
        $allowed = self::get_allowed_extensions();

        foreach ( self::get_folders() as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( self::is_excluded( $path ) ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, $allowed ) ) continue;
                $total++;
                $conv = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $path );
                if ( file_exists( $conv ) ) $converted++;
            }
        }

        return array( 'total' => $total, 'converted' => $converted, 'pending' => $total - $converted );
    }
}
