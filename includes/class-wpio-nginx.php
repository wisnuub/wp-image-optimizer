<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generates Nginx rules for WebP/AVIF transparent serving.
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
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        return stripos( $software, 'nginx' ) !== false;
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

        // map {} is only valid in the http {} context, so the snippet comes in two parts.
        $output  = "# ===== W Image Converter - part 1 of 2 =====\n";
        $output .= "# Paste in the http {} block (for example /etc/nginx/conf.d/w-image-converter.conf)\n";
        foreach ( $formats as $fmt ) {
            $mime    = $fmt === 'avif' ? 'image/avif' : 'image/webp';
            $output .= "map \$http_accept \$wpio_{$fmt}_suffix {\n";
            $output .= "    default   \"\";\n";
            $output .= "    \"~*{$mime}\" \".{$fmt}\";\n";
            $output .= "}\n";
        }

        // For 'both': try AVIF first, then WebP, then original.
        // Converted files keep the original extension (photo.jpg.webp); suffix vars include the leading dot.
        $try_files = '';
        foreach ( array_reverse( $formats ) as $fmt ) {
            $try_files .= "\$uri\$wpio_{$fmt}_suffix ";
        }

        $output .= "\n# ===== W Image Converter - part 2 of 2 =====\n";
        $output .= "# Paste in your site's server {} block\n";
        $output .= "location ~* ^{$uploads_uri}/.+\\.(?:jpe?g|png)\$ {\n";
        $output .= "    add_header Vary Accept;\n";
        $output .= "    try_files {$try_files}\$uri =404;\n";
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
        return 'w-image-converter-nginx-' . $format . '.conf';
    }
}
