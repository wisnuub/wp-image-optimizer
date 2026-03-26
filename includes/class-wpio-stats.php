<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIO_Stats {

    const CACHE_PREFIX = 'wpio_stats_cache';
    const CACHE_EXPIRE = 300;

    private static function cache_key() {
        return self::CACHE_PREFIX . '_' . get_current_blog_id();
    }

    public static function get() {
        $cached = get_transient( self::cache_key() );
        if ( $cached !== false ) return $cached;
        return self::compute();
    }

    public static function compute() {
        $format      = get_option( 'wpio_format', 'webp' );
        $formats     = WPIO_Converter::get_formats( $format );
        $folders     = WPIO_Folder_Scanner::get_folders();
        $total       = 0;
        $converted   = 0;
        $restored    = 0;
        $orig_bytes  = 0;
        $conv_bytes  = 0;
        $largest     = array( 'file' => '', 'saved' => 0, 'pct' => 0 );

        // Build a set of all backup files once to avoid per-file has_backup() calls.
        $backup_index = self::build_backup_index();

        foreach ( $folders as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iter as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( strpos( $path, 'wpio-backups' ) !== false ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;
                $total++;
                $orig_size   = $file->getSize();
                $orig_bytes += $orig_size;

                // For 'both', count as converted only if ALL formats exist.
                // For savings, use the smallest converted file.
                $all_exist    = true;
                $best_c_size  = PHP_INT_MAX;
                foreach ( $formats as $fmt ) {
                    $conv_path = preg_replace( '/\.(jpe?g|png)$/i', '.' . $fmt, $path );
                    if ( file_exists( $conv_path ) ) {
                        $sz = filesize( $conv_path );
                        if ( $sz < $best_c_size ) $best_c_size = $sz;
                    } else {
                        $all_exist = false;
                    }
                }

                if ( $all_exist ) {
                    $converted++;
                    $conv_bytes += $best_c_size;
                    $saved       = $orig_size - $best_c_size;
                    if ( $saved > $largest['saved'] && $orig_size > 0 ) {
                        $largest = array(
                            'file'  => $file->getFilename(),
                            'saved' => $saved,
                            'pct'   => round( ( $saved / $orig_size ) * 100 ),
                        );
                    }
                } else {
                    $conv_bytes += $orig_size;
                    // Count as "restored" if a backup exists (was once converted, now reverted).
                    $relative = self::relative_path( $path );
                    if ( $relative !== false && isset( $backup_index[ $relative ] ) ) {
                        $restored++;
                    }
                }
            }
        }

        $saved_bytes = max( 0, $orig_bytes - $conv_bytes );
        $backup_size = WPIO_Backup::total_backup_size();
        $stats = array(
            'format'       => strtoupper( $format ),
            'total'        => $total,
            'converted'    => $converted,
            'pending'      => $total - $converted,
            'restored'     => $restored,
            'orig_bytes'   => $orig_bytes,
            'saved_bytes'  => $saved_bytes,
            'saved_kb'     => round( $saved_bytes / 1024, 1 ),
            'saved_mb'     => round( $saved_bytes / 1048576, 2 ),
            'saving_pct'   => $orig_bytes > 0 ? round( ( $saved_bytes / $orig_bytes ) * 100, 1 ) : 0,
            'largest_save' => $largest,
            'backup_bytes' => $backup_size,
            'backup_mb'    => round( $backup_size / 1048576, 2 ),
            'progress_pct' => $total > 0 ? round( ( $converted / $total ) * 100 ) : 0,
            'folders'      => WPIO_Folder_Scanner::get_folders(),
        );

        set_transient( self::cache_key(), $stats, self::CACHE_EXPIRE );
        return $stats;
    }

    public static function bust_cache() {
        delete_transient( self::cache_key() );
    }

    /**
     * Build an index of all backup files (relative paths) for fast lookup.
     */
    private static function build_backup_index() {
        $index      = array();
        $backup_dir = WPIO_Backup::backup_dir();
        if ( ! is_dir( $backup_dir ) ) return $index;

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $iter as $file ) {
            if ( $file->isFile() ) {
                $rel = str_replace( $backup_dir, '', $file->getPathname() );
                $index[ $rel ] = true;
            }
        }
        return $index;
    }

    /**
     * Get the relative path portion used for backup lookup.
     */
    private static function relative_path( $source_path ) {
        $upload_dir = wp_upload_dir();
        $base       = $upload_dir['basedir'];
        if ( strpos( $source_path, $base ) === 0 ) {
            return str_replace( $base, '', $source_path );
        }
        return false;
    }
}
