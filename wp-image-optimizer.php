<?php
/**
 * Plugin Name: WP Image Optimizer
 * Plugin URI:  https://github.com/wisnuub/wp-image-optimizer
 * Description: Convert and compress existing images to WebP/AVIF without breaking site links. Uses .htaccess or Nginx rewrite rules so old .jpg/.png URLs still work.
 * Version:     1.1
 * Author:      Wisnu A. Kurniawan
 * Author URI:  https://github.com/wisnuub
 * License:     GPL-2.0+
 * Text Domain: wp-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPIO_VERSION', '1.1' );
define( 'WPIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPIO_URL', plugin_dir_url( __FILE__ ) );

require_once WPIO_PATH . 'includes/class-wpio-converter.php';
require_once WPIO_PATH . 'includes/class-wpio-backup.php';
require_once WPIO_PATH . 'includes/class-wpio-stats.php';
require_once WPIO_PATH . 'includes/class-wpio-rewrite.php';
require_once WPIO_PATH . 'includes/class-wpio-nginx.php';
require_once WPIO_PATH . 'includes/class-wpio-media-column.php';
require_once WPIO_PATH . 'includes/class-wpio-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once WPIO_PATH . 'includes/class-wpio-cli.php';
}

register_activation_hook( __FILE__, array( 'WPIO_Rewrite', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPIO_Rewrite', 'deactivate' ) );

add_action( 'plugins_loaded', function() {
    new WPIO_Admin();
} );
