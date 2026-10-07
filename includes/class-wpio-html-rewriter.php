<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HTML-based image delivery for managed hosting (WP Engine, Kinsta, etc.).
 *
 * Wraps <img> tags in <picture> with WebP/AVIF <source> elements. Each source
 * mirrors the image's own srcset/sizes, so the browser still picks a
 * correctly sized file — just in a lighter format. Browsers without WebP/AVIF
 * support fall back to the original <img>.
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
        add_filter( 'widget_block_content',         array( __CLASS__, 'rewrite_content' ), 999 );
        add_filter( 'wp_get_attachment_image',      array( __CLASS__, 'rewrite_content' ), 999 );
        add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'rewrite_content' ), 999 );
    }

    /**
     * Rewrite <img> tags in HTML to <picture> elements.
     *
     * @param string $content HTML content.
     * @return string Modified HTML.
     */
    public static function rewrite_content( $content ) {
        if ( empty( $content ) || is_admin() || wp_doing_ajax() || is_feed() || stripos( $content, '<img' ) === false ) {
            return $content;
        }

        // Leave existing <picture> elements alone — a theme's, or ours from an
        // earlier filter (post_thumbnail_html wraps wp_get_attachment_image output).
        $parts = preg_split( '#(<picture\b.*?</picture>)#is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
        foreach ( $parts as $i => $part ) {
            if ( $i % 2 === 0 && stripos( $part, '<img' ) !== false ) {
                $parts[ $i ] = preg_replace_callback( '/<img\b[^>]*>/i', array( __CLASS__, 'replace_img_tag' ), $part );
            }
        }
        return implode( '', $parts );
    }

    /**
     * Replace a single <img> with a <picture> element, if converted copies exist.
     */
    private static function replace_img_tag( $matches ) {
        $tag = $matches[0];
        if ( strpos( $tag, 'data-wpio-skip' ) !== false ) {
            return $tag;
        }

        $src = self::get_attr( $tag, 'src' );
        if ( ! $src || ! preg_match( '/\.(jpe?g|png)(\?.*)?$/i', $src ) ) {
            return $tag;
        }
        $srcset = self::get_attr( $tag, 'srcset' );
        $sizes  = self::get_attr( $tag, 'sizes' );

        $sources = '';
        // AVIF first: the browser takes the first <source> it supports.
        foreach ( array_reverse( WPIO_Converter::get_formats( get_option( 'wpio_format', 'webp' ) ) ) as $fmt ) {
            $set = self::converted_srcset( $srcset ?: $src, $fmt );
            if ( $set === '' ) continue;
            $sources .= '<source type="image/' . esc_attr( $fmt ) . '" srcset="' . esc_attr( $set ) . '"'
                . ( $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
        }

        return $sources ? '<picture>' . $sources . $tag . '</picture>' : $tag;
    }

    /**
     * Rebuild a srcset (or a single URL) pointing at converted copies.
     *
     * Candidates without a converted copy (e.g. a size where WebP wasn't
     * smaller) keep their original URL, so the browser still has every width
     * to choose from — dropping them would make it upscale a smaller file.
     * Returns '' when no candidate is converted.
     */
    private static function converted_srcset( $srcset, $fmt ) {
        $out       = array();
        $converted = 0;
        foreach ( explode( ',', html_entity_decode( $srcset ) ) as $candidate ) {
            $candidate = trim( $candidate );
            if ( $candidate === '' ) continue;

            $bits = preg_split( '/\s+/', $candidate, 2 );
            $url  = $bits[0];
            $desc = isset( $bits[1] ) ? ' ' . $bits[1] : '';

            $path = self::url_to_path( $url );
            if ( $path && file_exists( $path ) && WPIO_Converter::is_converted( $path, $fmt ) ) {
                $out[] = preg_replace( '/\?.*$/', '', $url ) . '.' . $fmt . $desc;
                $converted++;
            } else {
                $out[] = $url . $desc;
            }
        }
        return $converted ? implode( ', ', $out ) : '';
    }

    private static function get_attr( $tag, $name ) {
        if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
            return $m[2];
        }
        return '';
    }

    /**
     * Map an image URL on this site to its file path, or false.
     */
    private static function url_to_path( $url ) {
        $url = preg_replace( '/[?#].*$/', '', $url );
        if ( strpos( $url, '//' ) === 0 ) {
            $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
        }

        $upload_dir = wp_upload_dir();
        $candidates = array(
            set_url_scheme( $upload_dir['baseurl'], 'http' )  => $upload_dir['basedir'],
            set_url_scheme( $upload_dir['baseurl'], 'https' ) => $upload_dir['basedir'],
            set_url_scheme( content_url(), 'http' )           => WP_CONTENT_DIR,
            set_url_scheme( content_url(), 'https' )          => WP_CONTENT_DIR,
            set_url_scheme( site_url(), 'http' )              => untrailingslashit( ABSPATH ),
            set_url_scheme( site_url(), 'https' )             => untrailingslashit( ABSPATH ),
        );

        if ( strpos( $url, '/' ) === 0 ) {
            // Root-relative: resolve against the site's host.
            $home = wp_parse_url( home_url() );
            $url  = 'http://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
        }

        foreach ( $candidates as $base => $dir ) {
            if ( strpos( $url, $base . '/' ) === 0 ) {
                $path = $dir . substr( rawurldecode( $url ), strlen( $base ) );
                return strpos( $path, '..' ) === false ? $path : false;
            }
        }
        return false;
    }
}
