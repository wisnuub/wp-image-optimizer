<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles backup and restore of original images before conversion.
 * Backups stored in /wp-content/uploads/wpio-backups/ mirroring original paths.
 */
class WPIO_Backup {

    public static function backup_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/wpio-backups';
    }

    /**
     * Back up a file before conversion.
     *
     * @param string $source_path Absolute path to original file.
     * @return string|WP_Error    Path to backup or WP_Error.
     */
    public static function backup( $source_path ) {
        $upload_dir  = wp_upload_dir();
        $base        = $upload_dir['basedir'];
        $relative    = str_replace( $base, '', $source_path );
        $backup_path = self::backup_dir() . $relative;
        $backup_dir  = dirname( $backup_path );

        if ( file_exists( $backup_path ) ) {
            return $backup_path; // Already backed up
        }

        if ( ! wp_mkdir_p( $backup_dir ) ) {
            return new WP_Error( 'mkdir_failed', 'Could not create backup directory: ' . $backup_dir );
        }

        if ( ! copy( $source_path, $backup_path ) ) {
            return new WP_Error( 'backup_failed', 'Could not copy file to backup: ' . $backup_path );
        }

        return $backup_path;
    }

    /**
     * Check if a backup exists for a given file.
     *
     * @param string $source_path
     * @return bool
     */
    public static function has_backup( $source_path ) {
        $upload_dir  = wp_upload_dir();
        $relative    = str_replace( $upload_dir['basedir'], '', $source_path );
        $backup_path = self::backup_dir() . $relative;
        return file_exists( $backup_path );
    }

    /**
     * Get backup path for a file.
     *
     * @param string $source_path
     * @return string
     */
    public static function get_backup_path( $source_path ) {
        $upload_dir = wp_upload_dir();
        $relative   = str_replace( $upload_dir['basedir'], '', $source_path );
        return self::backup_dir() . $relative;
    }

    /**
     * Restore original from backup (does NOT delete converted file).
     *
     * @param string $source_path Original file path.
     * @return true|WP_Error
     */
    public static function restore( $source_path ) {
        $backup_path = self::get_backup_path( $source_path );
        if ( ! file_exists( $backup_path ) ) {
            return new WP_Error( 'no_backup', 'No backup found for: ' . basename( $source_path ) );
        }
        if ( ! copy( $backup_path, $source_path ) ) {
            return new WP_Error( 'restore_failed', 'Could not restore file.' );
        }
        return true;
    }

    /**
     * Delete backup file after confirmed successful conversion.
     *
     * @param string $source_path
     * @return bool
     */
    public static function delete_backup( $source_path ) {
        $backup_path = self::get_backup_path( $source_path );
        if ( file_exists( $backup_path ) ) {
            return unlink( $backup_path );
        }
        return true;
    }

    /**
     * Get total backup size in bytes.
     *
     * @return int
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
}
