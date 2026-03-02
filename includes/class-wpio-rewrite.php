<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages .htaccess rewrite rules so old .jpg/.png URLs transparently
 * serve the converted WebP/AVIF file — no link changes needed.
 */
class WPIO_Rewrite {

    const MARKER = 'WP Image Optimizer';

    /**
     * Called on plugin activation.
     */
    public static function activate() {
        $format = get_option( 'wpio_format', 'webp' );
        self::insert_rules( $format );
    }

    /**
     * Called on plugin deactivation — clean up rules.
     */
    public static function deactivate() {
        self::remove_rules();
        flush_rewrite_rules();
    }

    /**
     * Insert .htaccess rewrite rules for the chosen format.
     *
     * @param string $format 'webp' or 'avif'.
     */
    public static function insert_rules( $format = 'webp' ) {
        $upload_dir  = wp_upload_dir();
        $htaccess    = $upload_dir['basedir'] . '/.htaccess';

        $rules = self::build_rules( $format );
        insert_with_markers( $htaccess, self::MARKER, $rules );
    }

    /**
     * Remove the plugin's rewrite rules from .htaccess.
     */
    public static function remove_rules() {
        $upload_dir = wp_upload_dir();
        $htaccess   = $upload_dir['basedir'] . '/.htaccess';
        insert_with_markers( $htaccess, self::MARKER, array() );
    }

    /**
     * Build the rewrite rule lines for .htaccess.
     *
     * @param string $format 'webp' or 'avif'.
     * @return array
     */
    public static function build_rules( $format = 'webp' ) {
        return array(
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            '# Serve ' . strtoupper( $format ) . ' if it exists and browser supports it',
            'RewriteCond %{HTTP_ACCEPT} image/' . $format,
            'RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$',
            'RewriteCond %{REQUEST_FILENAME}.' . $format . ' -f',
            'RewriteRule ^(.+)\.(jpe?g|png)$ $1.' . $format . ' [T=image/' . $format . ',L]',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            'Header append Vary Accept',
            '</IfModule>',
        );
    }
}
