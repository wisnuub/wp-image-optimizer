<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Chunked background queue processor.
 *
 * - Processes images in small batches via AJAX (foreground) or WP-Cron (background).
 * - WP-Cron heartbeat keeps processing even if the admin closes the browser.
 * - Fully unlimited — processes every image across all configured folders.
 * - Throttle: configurable sleep between chunks to protect shared hosting.
 */
class WPIO_Queue {

    const OPTION_QUEUE    = 'wpio_queue_list';
    const OPTION_RUNNING  = 'wpio_queue_running';
    const OPTION_PROGRESS = 'wpio_queue_progress';
    const CRON_HOOK       = 'wpio_bg_process_chunk';
    const CRON_INTERVAL   = 'wpio_every_30s';

    /**
     * Register custom WP-Cron interval and hook.
     */
    public static function register_cron() {
        add_filter( 'cron_schedules', function( $schedules ) {
            $schedules[ self::CRON_INTERVAL ] = array(
                'interval' => 30,
                'display'  => 'Every 30 Seconds (WPIO Background)',
            );
            return $schedules;
        } );
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_chunk' ) );
    }

    /**
     * Build the queue from all unprocessed images in configured folders.
     *
     * @return int  Total images queued.
     */
    public static function build() {
        $format = get_option( 'wpio_format', 'webp' );
        $queue  = WPIO_Folder_Scanner::get_pending_images( $format );

        update_option( self::OPTION_QUEUE, $queue, false );
        update_option( self::OPTION_PROGRESS, array(
            'total'  => count( $queue ),
            'done'   => 0,
            'errors' => 0,
            'method' => 'idle',
        ), false );
        update_option( self::OPTION_RUNNING, 1 );
        WPIO_Stats::bust_cache();

        // Schedule background cron if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 5, self::CRON_INTERVAL, self::CRON_HOOK );
        }

        return count( $queue );
    }

    /**
     * Process one chunk of images from the queue.
     * Called by AJAX (foreground) or WP-Cron (background).
     *
     * @return array  Status info.
     */
    public static function process_chunk() {
        if ( ! get_option( self::OPTION_RUNNING ) ) {
            self::unschedule_cron();
            return array( 'status' => 'idle' );
        }

        $queue      = get_option( self::OPTION_QUEUE, array() );
        $progress   = get_option( self::OPTION_PROGRESS, array( 'total' => 0, 'done' => 0, 'errors' => 0 ) );
        $batch_size = max( 1, (int) get_option( 'wpio_batch_size', 5 ) );
        $sleep_ms   = max( 0, (int) get_option( 'wpio_sleep_time', 500 ) );
        $format     = get_option( 'wpio_format', 'webp' );
        $quality    = (int) get_option( 'wpio_quality', 82 );
        $use_remote = WPIO_Remote::is_enabled();

        // Security: resolve allowed base directories once to validate each queued path.
        $allowed_bases = array_map( 'realpath', WPIO_Folder_Scanner::get_folders() );
        $allowed_bases = array_filter( $allowed_bases );

        self::raise_limits();

        $chunk = array_splice( $queue, 0, $batch_size );

        foreach ( $chunk as $file ) {
            // Security: ensure the file path still falls within an allowed folder
            // before processing — guards against option poisoning attacks.
            $real_file = realpath( $file );
            $allowed   = false;
            foreach ( $allowed_bases as $base ) {
                if ( strpos( $real_file . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR ) === 0 ) {
                    $allowed = true;
                    break;
                }
            }
            if ( ! $real_file || ! $allowed ) {
                $progress['errors']++;
                continue;
            }

            // Backup original if enabled
            if ( get_option( 'wpio_backup_enabled', '1' ) === '1' ) {
                WPIO_Backup::backup( $file );
            }

            // Try remote first, fallback to local
            if ( $use_remote ) {
                $result = WPIO_Remote::convert( $file, $format, $quality );
                if ( is_wp_error( $result ) ) {
                    // Fallback to local on remote failure
                    $result = WPIO_Converter::convert_local( $file, $format, $quality );
                }
            } else {
                $result = WPIO_Converter::convert_local( $file, $format, $quality );
            }

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
            self::unschedule_cron();
            WPIO_Stats::bust_cache();
            return array( 'status' => 'done', 'progress' => $progress );
        }

        if ( $sleep_ms > 0 ) usleep( $sleep_ms * 1000 );

        return array(
            'status'    => 'running',
            'progress'  => $progress,
            'remaining' => count( $queue ),
        );
    }

    public static function get_progress() {
        return array(
            'running'   => (bool) get_option( self::OPTION_RUNNING, 0 ),
            'progress'  => get_option( self::OPTION_PROGRESS, array( 'total' => 0, 'done' => 0, 'errors' => 0 ) ),
            'remaining' => count( get_option( self::OPTION_QUEUE, array() ) ),
        );
    }

    public static function cancel() {
        update_option( self::OPTION_RUNNING, 0 );
        update_option( self::OPTION_QUEUE, array() );
        self::unschedule_cron();
    }

    public static function raise_limits() {
        $memory = get_option( 'wpio_memory_limit', '256M' );
        $time   = (int) get_option( 'wpio_exec_time', 120 );
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            add_filter( 'image_memory_limit', function() use ( $memory ) { return $memory; } );
            wp_raise_memory_limit( 'image' );
        }
        @ini_set( 'memory_limit', $memory );
        @set_time_limit( $time );
    }

    private static function unschedule_cron() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) wp_unschedule_event( $timestamp, self::CRON_HOOK );
    }
}
