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
        $format     = get_option( 'wpio_format', 'webp' );
        $formats    = WPIO_Converter::get_formats( $format );
        $allowed    = WPIO_Folder_Scanner::get_allowed_extensions();
        $skip_list  = get_option( WPIO_Converter::SKIP_OPTION, array() );
        $total      = 0;
        $converted  = 0;
        $skipped    = 0;
        $orig_bytes = 0;
        $conv_bytes = 0;

        foreach ( WPIO_Folder_Scanner::get_folders() as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iter as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( WPIO_Folder_Scanner::is_excluded_path( $path ) ) continue;
                if ( ! in_array( strtolower( $file->getExtension() ), $allowed, true ) ) continue;

                $total++;
                $orig_size   = $file->getSize();
                $orig_bytes += $orig_size;

                // For 'both', count as converted only if ALL formats are up to date.
                // For savings, use the smallest converted file.
                $all_exist   = true;
                $best_c_size = PHP_INT_MAX;
                foreach ( $formats as $fmt ) {
                    if ( WPIO_Converter::is_converted( $path, $fmt ) ) {
                        $best_c_size = min( $best_c_size, filesize( WPIO_Converter::converted_path( $path, $fmt ) ) );
                    } else {
                        $all_exist = false;
                    }
                }

                if ( $all_exist ) {
                    $converted++;
                    $conv_bytes += $best_c_size;
                } else {
                    $conv_bytes += $orig_size;
                    if ( $skip_list && WPIO_Converter::is_skipped( $path, $skip_list ) ) $skipped++;
                }
            }
        }

        $saved_bytes = max( 0, $orig_bytes - $conv_bytes );
        $backup_size = WPIO_Backup::total_backup_size();
        $done        = $converted + $skipped;
        $stats       = array(
            'format'       => strtoupper( $format ),
            'total'        => $total,
            'converted'    => $converted,
            'skipped'      => $skipped,
            'pending'      => $total - $done,
            'orig_bytes'   => $orig_bytes,
            'saved_bytes'  => $saved_bytes,
            'saved_kb'     => round( $saved_bytes / 1024, 1 ),
            'saved_mb'     => round( $saved_bytes / 1048576, 2 ),
            'saving_pct'   => $orig_bytes > 0 ? round( ( $saved_bytes / $orig_bytes ) * 100, 1 ) : 0,
            'backup_bytes' => $backup_size,
            'backup_mb'    => round( $backup_size / 1048576, 2 ),
            // Skipped files are finished too — the original is the best version.
            'progress_pct' => $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 0,
            'folders'      => WPIO_Folder_Scanner::get_folders(),
        );

        set_transient( self::cache_key(), $stats, self::CACHE_EXPIRE );
        return $stats;
    }

    public static function bust_cache() {
        delete_transient( self::cache_key() );
    }
}
