<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cleanup for the backup folder created by version 1.4 and earlier.
 *
 * Those versions copied every original to /uploads/wpio-backups/ before
 * converting, but conversion never modifies originals — the copies only
 * doubled disk usage. New versions don't create backups; this lets existing
 * sites see and reclaim that space.
 */
class WPIO_Backup {

    public static function backup_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/wpio-backups';
    }

    /**
     * Total size of the legacy backup folder in bytes.
     */
    public static function total_backup_size() {
        $dir = self::backup_dir();
        if ( ! is_dir( $dir ) ) return 0;
        $size = 0;
        $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
        foreach ( $iter as $file ) {
            if ( $file->isFile() ) $size += $file->getSize();
        }
        return $size;
    }

    /**
     * Delete the legacy backup folder.
     */
    public static function purge() {
        $dir = self::backup_dir();
        if ( ! is_dir( $dir ) ) return;

        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( WP_Filesystem() && $wp_filesystem ) {
            $wp_filesystem->delete( $dir, true );
        }
    }
}
