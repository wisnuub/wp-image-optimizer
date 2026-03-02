<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Collects and caches conversion stats for the dashboard.
 */
class WPIO_Stats {

    const CACHE_KEY     = 'wpio_stats_cache';
    const CACHE_EXPIRE  = 300; // 5 minutes

    /**
     * Get stats, from cache or freshly computed.
     *
     * @return array
     */
    public static function get() {
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) return $cached;
        return self::compute();
    }

    /**
     * Force recompute and cache.
     *
     * @return array
     */
    public static function compute() {
        $format     = get_option( 'wpio_format', 'webp' );
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        $total        = 0;
        $converted    = 0;
        $orig_bytes   = 0;
        $conv_bytes   = 0;
        $largest_save = array( 'file' => '', 'saved' => 0, 'pct' => 0 );

        if ( ! is_dir( $base_dir ) ) {
            return self::empty_stats();
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isDir() ) continue;
            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;

            $total++;
            $orig_size  = $file->getSize();
            $orig_bytes += $orig_size;

            $conv_path = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $file->getPathname() );
            if ( file_exists( $conv_path ) ) {
                $converted++;
                $c_size      = filesize( $conv_path );
                $conv_bytes += $c_size;
                $saved       = $orig_size - $c_size;
                if ( $saved > $largest_save['saved'] && $orig_size > 0 ) {
                    $largest_save = array(
                        'file'  => $file->getFilename(),
                        'saved' => $saved,
                        'pct'   => round( ( $saved / $orig_size ) * 100 ),
                    );
                }
            } else {
                $conv_bytes += $orig_size; // Not converted, count original
            }
        }

        $saved_bytes  = max( 0, $orig_bytes - $conv_bytes );
        $pending      = $total - $converted;
        $saving_pct   = $orig_bytes > 0 ? round( ( $saved_bytes / $orig_bytes ) * 100, 1 ) : 0;
        $backup_size  = WPIO_Backup::total_backup_size();

        $stats = array(
            'format'        => strtoupper( $format ),
            'total'         => $total,
            'converted'     => $converted,
            'pending'       => $pending,
            'orig_bytes'    => $orig_bytes,
            'conv_bytes'    => $conv_bytes,
            'saved_bytes'   => $saved_bytes,
            'saved_kb'      => round( $saved_bytes / 1024, 1 ),
            'saved_mb'      => round( $saved_bytes / 1048576, 2 ),
            'saving_pct'    => $saving_pct,
            'largest_save'  => $largest_save,
            'backup_bytes'  => $backup_size,
            'backup_mb'     => round( $backup_size / 1048576, 2 ),
            'progress_pct'  => $total > 0 ? round( ( $converted / $total ) * 100 ) : 0,
        );

        set_transient( self::CACHE_KEY, $stats, self::CACHE_EXPIRE );
        return $stats;
    }

    public static function bust_cache() {
        delete_transient( self::CACHE_KEY );
    }

    private static function empty_stats() {
        return array(
            'format' => strtoupper( get_option( 'wpio_format', 'webp' ) ),
            'total' => 0, 'converted' => 0, 'pending' => 0,
            'orig_bytes' => 0, 'conv_bytes' => 0, 'saved_bytes' => 0,
            'saved_kb' => 0, 'saved_mb' => 0, 'saving_pct' => 0,
            'largest_save' => array( 'file' => '', 'saved' => 0, 'pct' => 0 ),
            'backup_bytes' => 0, 'backup_mb' => 0, 'progress_pct' => 0,
        );
    }
}
