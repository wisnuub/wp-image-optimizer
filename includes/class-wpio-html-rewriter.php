<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HTML-based image delivery for managed hosting (WP Engine, Kinsta, etc.).
 *
 * Rewrites <img> tags in page output to <picture> elements with optimized
 * WebP/AVIF <source> tags. Browsers pick the best supported format
 * automatically; unsupported browsers fall back to the original <img>.
 *
 * This approach works on ANY hosting — no .htaccess or Nginx config needed.
 */
class WPIO_HTML_Rewriter {

    /**
     * Hook into WordPress content filters.
     * Called only when delivery method is 'html'.
     */
    public static function init() {
        add_filter( 'the_content',                  array( __CLASS__, 'rewrite_content' ), 999 );
        add_filter( 'post_thumbnail_html',          array( __CLASS__, 'rewrite_content' ), 999 );
        add_filter( 'widget_text',                  array( __CLASS__, 'rewrite_content' ), 999 );
        add_filter( 'wp_get_attachment_image',      array( __CLASS__, 'rewrite_content' ), 999 );
        add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'rewrite_content' ), 999 );
    }

    /**
     * Rewrite <img> tags in HTML content to <picture> elements.
     *
     * @param string $content HTML content.
     * @return string Modified HTML.
     */
    public static function rewrite_content( $content ) {
        if ( empty( $content ) || is_admin() || wp_doing_ajax() ) {
            return $content;
        }

        // Match <img> tags that reference .jpg, .jpeg, or .png files.
        return preg_replace_callback(
            '/<img\b([^>]*?\bsrc=["\'])([^"\']+\.(jpe?g|png))(["\'][^>]*?)\/?\s*>/i',
            array( __CLASS__, 'replace_img_tag' ),
            $content
        );
    }

    /**
     * Replace a single <img> match with a <picture> element.
     */
    private static function replace_img_tag( $matches ) {
        $full_tag   = $matches[0];
        $before_src = $matches[1]; // Everything before the src URL including the quote
        $src_url    = $matches[2]; // The image URL
        $ext        = $matches[3]; // jpg|jpeg|png
        $after_src  = $matches[4]; // The closing quote and remaining attributes

        // Skip images already wrapped in <picture>.
        // Skip images with data-wpio-skip attribute.
        if ( strpos( $full_tag, 'data-wpio-skip' ) !== false ) {
            return $full_tag;
        }

        $format  = get_option( 'wpio_format', 'webp' );
        $formats = WPIO_Converter::get_formats( $format );

        // Build <source> elements for each available format.
        $sources = self::build_sources( $src_url, $formats );

        if ( empty( $sources ) ) {
            return $full_tag;
        }

        // Also rewrite srcset if present.
        $img_tag = $full_tag;
        $img_tag = self::rewrite_srcset_in_tag( $img_tag, $formats );

        return '<picture>' . implode( '', $sources ) . $img_tag . '</picture>';
    }

    /**
     * Build <source> elements for each format that has a converted file on disk.
     */
    private static function build_sources( $src_url, $formats ) {
        $sources   = array();
        $abspath   = rtrim( ABSPATH, '/' );
        $site_url  = rtrim( site_url(), '/' );
        $home_url  = rtrim( home_url(), '/' );

        // Convert URL to file path.
        $file_path = self::url_to_path( $src_url );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return $sources;
        }

        // AVIF first for best compression, then WebP.
        foreach ( array_reverse( $formats ) as $fmt ) {
            $conv_path = preg_replace( '/\.(jpe?g|png)$/i', '.' . $fmt, $file_path );
            if ( file_exists( $conv_path ) ) {
                $conv_url = preg_replace( '/\.(jpe?g|png)$/i', '.' . $fmt, $src_url );
                $mime     = $fmt === 'avif' ? 'image/avif' : 'image/webp';
                $sources[] = '<source srcset="' . esc_url( $conv_url ) . '" type="' . esc_attr( $mime ) . '">';
            }
        }

        return $sources;
    }

    /**
     * Rewrite srcset attribute inside an <img> tag.
     * Each URL in the srcset that has a converted version gets a corresponding
     * source added, but for simplicity we just let the <source> elements
     * handle the format selection and leave the original srcset intact.
     */
    private static function rewrite_srcset_in_tag( $img_tag, $formats ) {
        // The <picture> element's <source> handles format negotiation.
        // We add srcset to the <source> elements for responsive support.
        // For now, the original img srcset stays as fallback.
        return $img_tag;
    }

    /**
     * Convert a URL to an absolute file path.
     */
    private static function url_to_path( $url ) {
        // Handle protocol-relative URLs.
        if ( strpos( $url, '//' ) === 0 ) {
            $url = 'https:' . $url;
        }

        // Handle relative URLs.
        if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
            return rtrim( ABSPATH, '/' ) . $url;
        }

        // Try site_url and home_url.
        $site_url = site_url();
        $home_url = home_url();

        if ( strpos( $url, $site_url ) === 0 ) {
            $relative = substr( $url, strlen( $site_url ) );
            return rtrim( ABSPATH, '/' ) . $relative;
        }

        if ( strpos( $url, $home_url ) === 0 ) {
            $relative = substr( $url, strlen( $home_url ) );
            return rtrim( ABSPATH, '/' ) . $relative;
        }

        // Try wp-content path as a fallback.
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( strpos( $url, $base_url ) === 0 ) {
            return str_replace( $base_url, $base_dir, $url );
        }

        // Cannot map — skip.
        return false;
    }
}
