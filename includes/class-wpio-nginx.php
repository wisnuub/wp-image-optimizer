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
        $formats     = WPIO_Converter::get_formats( $format );

        $output = "# -----------------------------------------------\n";
        $output .= "# WP Image Optimizer - Nginx Config Snippet\n";
        $output .= "# Paste this inside your server {} block\n";
        $output .= "# -----------------------------------------------\n";

        foreach ( $formats as $fmt ) {
            $mime    = $fmt === 'avif' ? 'image/avif' : 'image/webp';
            $output .= "\nmap \$http_accept \$wpio_{$fmt}_suffix {\n";
            $output .= "    default   \"\";\n";
            $output .= "    \"~*{$mime}\" \".{$fmt}\";\n";
            $output .= "}\n";
        }

        // For 'both': try AVIF first, then WebP, then original.
        // $1 captures the path without extension; suffix vars include the leading dot.
        $try_files = '';
        foreach ( array_reverse( $formats ) as $fmt ) {
            $try_files .= "\$1\$wpio_{$fmt}_suffix\$is_args\$args\n               ";
        }

        $output .= "\nlocation ~* ^({$uploads_uri}/.+)\\.(?:jpe?g|png)\$ {\n";
        $output .= "    add_header Vary Accept;\n";
        $output .= "    try_files  {$try_files}\$uri\n";
        $output .= "               =404;\n";
        $output .= "}\n";

        return $output;
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
