<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Chunked background queue processor.
 *
 * Processes images in small batches using Action Scheduler (if available)
 * or a WP Cron fallback. Prevents timeout and memory overload.
 *
 * Settings used:
 *   wpio_batch_size   (default 5)   — images per chunk
 *   wpio_sleep_time   (default 500) — ms sleep between chunks (microseconds * 1000)
 */
class WPIO_Queue {

    const OPTION_QUEUE    = 'wpio_queue_list';
    const OPTION_RUNNING  = 'wpio_queue_running';
    const OPTION_PROGRESS = 'wpio_queue_progress';
    const CRON_HOOK       = 'wpio_process_queue_chunk';

    /**
     * Build the queue from all unprocessed uploads images.
     */
    public static function build() {
        $format     = get_option( 'wpio_format', 'webp' );
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];
        $iterator   = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );
        $queue = array();
        foreach ( $iterator as $file ) {
            if ( $file->isDir() ) continue;
            $path = $file->getPathname();
            if ( strpos( $path, 'wpio-backups' ) !== false ) continue;
            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;
            $conv = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $path );
            if ( file_exists( $conv ) ) continue; // Already done
            $queue[] = $path;
        }

        update_option( self::OPTION_QUEUE, $queue, false );
        update_option( self::OPTION_PROGRESS, array( 'total' => count( $queue ), 'done' => 0, 'errors' => 0 ), false );
        update_option( self::OPTION_RUNNING, 1 );
        WPIO_Stats::bust_cache();
        return count( $queue );
    }

    /**
     * Process one chunk. Called via AJAX or WP-Cron.
     *
     * @return array  status info
     */
    public static function process_chunk() {
        if ( ! get_option( self::OPTION_RUNNING ) ) {
            return array( 'status' => 'idle' );
        }

        $queue      = get_option( self::OPTION_QUEUE, array() );
        $progress   = get_option( self::OPTION_PROGRESS, array( 'total' => 0, 'done' => 0, 'errors' => 0 ) );
        $batch_size = max( 1, (int) get_option( 'wpio_batch_size', 5 ) );
        $sleep_ms   = max( 0, (int) get_option( 'wpio_sleep_time', 500 ) );
        $format     = get_option( 'wpio_format', 'webp' );
        $quality    = (int) get_option( 'wpio_quality', 82 );

        // Raise PHP limits for this request
        self::raise_limits();

        $chunk = array_splice( $queue, 0, $batch_size );

        foreach ( $chunk as $file ) {
            if ( get_option( 'wpio_backup_enabled', '1' ) === '1' ) {
                WPIO_Backup::backup( $file );
            }
            $result = WPIO_Converter::convert( $file, $format, $quality );
            if ( is_wp_error( $result ) ) {
                $progress['errors']++;
            } else {
                $progress['done']++;
            }
        }

        update_option( self::OPTION_QUEUE, $queue, false );
        update_option( self::OPTION_PROGRESS, $progress, false );

        if ( empty( $queue ) ) {
            update_option( self::OPTION_RUNNING, 0 );
            WPIO_Stats::bust_cache();
            return array( 'status' => 'done', 'progress' => $progress );
        }

        // Throttle: sleep between chunks to reduce server load
        if ( $sleep_ms > 0 ) {
            usleep( $sleep_ms * 1000 );
        }

        return array( 'status' => 'running', 'progress' => $progress, 'remaining' => count( $queue ) );
    }

    /**
     * Get current queue progress.
     *
     * @return array
     */
    public static function get_progress() {
        return array(
            'running'   => (bool) get_option( self::OPTION_RUNNING, 0 ),
            'progress'  => get_option( self::OPTION_PROGRESS, array( 'total' => 0, 'done' => 0, 'errors' => 0 ) ),
            'remaining' => count( get_option( self::OPTION_QUEUE, array() ) ),
        );
    }

    /**
     * Cancel a running queue.
     */
    public static function cancel() {
        update_option( self::OPTION_RUNNING, 0 );
        update_option( self::OPTION_QUEUE, array() );
    }

    /**
     * Temporarily raise PHP limits for conversion requests.
     * Won't override server hard limits, but helps on shared hosting.
     */
    public static function raise_limits() {
        $memory = get_option( 'wpio_memory_limit', '256M' );
        $time   = (int) get_option( 'wpio_exec_time', 120 );

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            // Use WP's built-in raiser for image context
            add_filter( 'image_memory_limit', function() use ( $memory ) { return $memory; } );
            wp_raise_memory_limit( 'image' );
        }
        // Fallback direct ini_set (may be ignored on strict hosts)
        @ini_set( 'memory_limit', $memory );
        @set_time_limit( $time );
    }
}
