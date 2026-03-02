<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generates Nginx rewrite rules for WebP/AVIF transparent serving.
 * Since Nginx doesn't use .htaccess, we output a config snippet
 * the admin can paste into their server block.
 */
class WPIO_Nginx {

    /**
     * Detect if the server is likely running Nginx.
     *
     * @return bool
     */
    public static function is_nginx() {
        return isset( $_SERVER['SERVER_SOFTWARE'] ) &&
               stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) !== false;
    }

    /**
     * Build Nginx config snippet for the given format.
     *
     * @param string $format 'webp' or 'avif'.
     * @return string
     */
    public static function build_rules( $format = 'webp' ) {
        $upload_dir  = wp_upload_dir();
        $uploads_uri = wp_make_link_relative( $upload_dir['baseurl'] );
        $mime        = $format === 'avif' ? 'image/avif' : 'image/webp';

        return <<<NGINX
# -----------------------------------------------
# WP Image Optimizer - Nginx Config Snippet
# Paste this inside your server {} block
# -----------------------------------------------

map \$http_accept \$wpio_{$format}_suffix {
    default   "";
    "~*{$mime}" ".{$format}";
}

location ~* ^({$uploads_uri}/.+)\.(jpe?g|png)\$ {
    add_header Vary Accept;
    try_files  \$1\$2.\$wpio_{$format}_suffix\$is_args\$args
               \$uri
               =404;
}
NGINX;
    }

    /**
     * Return a downloadable .conf filename.
     *
     * @param string $format
     * @return string
     */
    public static function get_filename( $format = 'webp' ) {
        return 'wpio-nginx-' . $format . '.conf';
    }
}
