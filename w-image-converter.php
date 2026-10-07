<?php
/**
 * Plugin Name:       W Image Converter
 * Plugin URI:        https://github.com/wisnuub/wp-image-optimizer
 * Description:       Convert JPG and PNG images to WebP or AVIF and serve them automatically, without changing image URLs or touching your originals.
 * Version:           1.5.0
 * Author:            Wisnu A. Kurniawan
 * Author URI:        https://github.com/wisnuub
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       w-image-converter
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPIO_VERSION', '1.5.0' );
define( 'WPIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPIO_URL', plugin_dir_url( __FILE__ ) );

require_once WPIO_PATH . 'includes/class-wpio-environment.php';
require_once WPIO_PATH . 'includes/class-wpio-backup.php';
require_once WPIO_PATH . 'includes/class-wpio-converter.php';
require_once WPIO_PATH . 'includes/class-wpio-folder-scanner.php';
require_once WPIO_PATH . 'includes/class-wpio-folder-tree.php';
require_once WPIO_PATH . 'includes/class-wpio-stats.php';
require_once WPIO_PATH . 'includes/class-wpio-queue.php';
require_once WPIO_PATH . 'includes/class-wpio-rewrite.php';
require_once WPIO_PATH . 'includes/class-wpio-nginx.php';
require_once WPIO_PATH . 'includes/class-wpio-media-column.php';
require_once WPIO_PATH . 'includes/class-wpio-html-rewriter.php';
require_once WPIO_PATH . 'includes/class-wpio-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once WPIO_PATH . 'includes/class-wpio-cli.php';
}

WPIO_Queue::register_cron();

register_activation_hook( __FILE__, array( 'WPIO_Rewrite', 'activate' ) );
register_deactivation_hook( __FILE__, 'wpio_deactivate' );

/**
 * Remove rewrite rules and stop background jobs.
 */
function wpio_deactivate() {
    WPIO_Rewrite::deactivate();
    WPIO_Queue::cancel();
}

add_action( 'plugins_loaded', function() {
    new WPIO_Admin();
} );

// Activate HTML rewriter on frontend when delivery method is 'html'.
add_action( 'template_redirect', function() {
    if ( get_option( 'wpio_delivery_method', 'rewrite' ) === 'html' ) {
        WPIO_HTML_Rewriter::init();
    }
} );

/**
 * When WordPress deletes an image file (deleting media, regenerating sizes),
 * delete its converted copies too so they don't pile up as orphans.
 *
 * @param string $file Path of the file being deleted.
 * @return string
 */
function wpio_delete_converted_with_original( $file ) {
    if ( is_string( $file ) && preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
        foreach ( array( 'webp', 'avif' ) as $fmt ) {
            $converted = $file . '.' . $fmt;
            if ( file_exists( $converted ) ) {
                @unlink( $converted ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- wp_delete_file() would re-enter this filter.
            }
        }
    }
    return $file;
}
add_filter( 'wp_delete_file', 'wpio_delete_converted_with_original' );
