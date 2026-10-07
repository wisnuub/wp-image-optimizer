<?php
/**
 * W Image Converter — remove settings and scheduled jobs.
 *
 * Converted copies (photo.jpg.webp) are left on disk: deleting thousands of
 * files here could time out half-way. Use "Delete converted files" on the
 * Help tab before uninstalling to remove them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

function wpio_uninstall() {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removing this plugin's own options.
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpio\\_%' OR option_name LIKE '\\_transient\\_wpio\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpio\\_%'" );

    foreach ( array( 'wpio_bg_process_chunk', 'wpio_check_rewrite_rules' ) as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
}

wpio_uninstall();
