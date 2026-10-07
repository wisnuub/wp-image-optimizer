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
            'total'   => count( $queue ),
            'done'    => 0,
            'errors'  => 0,
            'skipped' => 0,
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

        // The admin page and WP-Cron can both call this; only one may work at a time.
        if ( get_transient( 'wpio_queue_lock' ) ) {
            return array(
                'status'    => 'running',
                'progress'  => get_option( self::OPTION_PROGRESS, array( 'total' => 0, 'done' => 0, 'errors' => 0, 'skipped' => 0 ) ),
                'remaining' => count( get_option( self::OPTION_QUEUE, array() ) ),
            );
        }
        set_transient( 'wpio_queue_lock', 1, 2 * MINUTE_IN_SECONDS );

        $queue      = get_option( self::OPTION_QUEUE, array() );
        $progress   = wp_parse_args( get_option( self::OPTION_PROGRESS, array() ), array( 'total' => 0, 'done' => 0, 'errors' => 0, 'skipped' => 0 ) );
        $batch_size = max( 1, (int) get_option( 'wpio_batch_size', 5 ) );
        $sleep_ms   = max( 0, (int) get_option( 'wpio_sleep_time', 500 ) );
        $format     = get_option( 'wpio_format', 'webp' );
        $quality    = (int) get_option( 'wpio_quality', 82 );

        // Security: resolve allowed base directories once to validate each queued path.
        $allowed_bases = array_filter( array_map( 'realpath', WPIO_Folder_Scanner::get_folders() ) );

        self::raise_limits();

        for ( $i = 0; $i < $batch_size && ! empty( $queue ); $i++ ) {
            $file = array_shift( $queue );

            // Persist the shortened queue before converting. If this file kills
            // the process (out of memory, timeout) the next run moves on instead
            // of retrying the same file every 30 seconds forever.
            update_option( self::OPTION_QUEUE, $queue, false );

            // Security: ensure the file path still falls within an allowed folder
            // before processing — guards against option poisoning attacks.
            $real_file = realpath( $file );
            $allowed   = false;
            foreach ( $allowed_bases as $base ) {
                if ( $real_file && strpos( $real_file . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR ) === 0 ) {
                    $allowed = true;
                    break;
                }
            }
            if ( ! $allowed || ! file_exists( $file ) ) {
                $progress['errors']++;
                continue;
            }

            $result = WPIO_Converter::convert( $file, $format, $quality );
            if ( ! is_wp_error( $result ) ) {
                $progress['done']++;
            } elseif ( 'output_larger' === $result->get_error_code() ) {
                $progress['skipped']++;
            } else {
                $progress['errors']++;
                $progress['last_error'] = wp_basename( $file ) . ': ' . $result->get_error_message();
            }
        }

        update_option( self::OPTION_PROGRESS, $progress, false );
        delete_transient( 'wpio_queue_lock' );

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
            'progress'  => wp_parse_args( get_option( self::OPTION_PROGRESS, array() ), array( 'total' => 0, 'done' => 0, 'errors' => 0, 'skipped' => 0 ) ),
            'remaining' => count( get_option( self::OPTION_QUEUE, array() ) ),
        );
    }

    public static function cancel() {
        update_option( self::OPTION_RUNNING, 0 );
        update_option( self::OPTION_QUEUE, array() );
        delete_transient( 'wpio_queue_lock' );
        self::unschedule_cron();
    }

    public static function raise_limits() {
        $memory = get_option( 'wpio_memory_limit', '256M' );
        $time   = (int) get_option( 'wpio_exec_time', 120 );

        // Only ever raise the limit — never lower one the host already set higher.
        $current = WPIO_Environment::parse_memory( (string) ini_get( 'memory_limit' ) );
        $wanted  = WPIO_Environment::parse_memory( (string) $memory );
        if ( $current !== -1 && $wanted > $current ) {
            @ini_set( 'memory_limit', $memory ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed -- image decoding needs headroom; only ever raised, never lowered.
        }
        @set_time_limit( $time ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- long-running bulk conversion chunk.
    }

    private static function unschedule_cron() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) wp_unschedule_event( $timestamp, self::CRON_HOOK );
    }
}
