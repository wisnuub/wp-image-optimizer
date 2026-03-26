<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Checks server environment for plugin requirements and shows admin notices.
 */
class WPIO_Environment {

    const MIN_PHP     = '7.4';
    const MIN_MEMORY  = 64;  // MB
    const MIN_TIMEOUT = 30;  // seconds

    /**
     * Run all checks and return array of results.
     *
     * @return array
     */
    public static function check() {
        return array(
            'php_version'    => self::check_php(),
            'memory_limit'   => self::check_memory(),
            'max_exec_time'  => self::check_exec_time(),
            'gd'             => self::check_gd(),
            'imagick'        => self::check_imagick(),
            'webp_gd'        => self::check_webp_gd(),
            'avif_gd'        => self::check_avif_gd(),
            'webp_imagick'   => self::check_webp_imagick(),
            'uploads_write'  => self::check_uploads_writable(),
            'htaccess_write' => self::check_htaccess_writable(),
            'mod_rewrite'    => self::check_mod_rewrite(),
        );
    }

    public static function check_php() {
        $ok  = version_compare( PHP_VERSION, self::MIN_PHP, '>=' );
        return array(
            'label'   => 'PHP Version',
            'value'   => PHP_VERSION,
            'status'  => $ok ? 'ok' : 'error',
            'message' => $ok ? '' : 'PHP ' . self::MIN_PHP . '+ required.',
        );
    }

    public static function check_memory() {
        $limit_str = ini_get( 'memory_limit' );
        $limit_mb  = self::parse_memory( $limit_str );
        $ok        = $limit_mb === -1 || $limit_mb >= self::MIN_MEMORY;
        return array(
            'label'   => 'Memory Limit',
            'value'   => $limit_str === '-1' ? 'Unlimited' : $limit_str,
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok ? '' : 'At least ' . self::MIN_MEMORY . 'MB recommended. Large images may fail.',
        );
    }

    public static function check_exec_time() {
        $time = (int) ini_get( 'max_execution_time' );
        $ok   = $time === 0 || $time >= self::MIN_TIMEOUT;
        return array(
            'label'   => 'Max Execution Time',
            'value'   => $time === 0 ? 'Unlimited' : $time . 's',
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok ? '' : 'Low timeout detected. Bulk convert may time out — use WP-CLI instead.',
        );
    }

    public static function check_gd() {
        $ok = extension_loaded( 'gd' );
        return array(
            'label'   => 'GD Library',
            'value'   => $ok ? 'Enabled' : 'Not found',
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok ? '' : 'GD not found. Imagick will be used as fallback.',
        );
    }

    public static function check_imagick() {
        $ok = extension_loaded( 'imagick' );
        return array(
            'label'   => 'Imagick',
            'value'   => $ok ? 'Enabled' : 'Not found',
            'status'  => $ok ? 'ok' : 'info',
            'message' => $ok ? '' : 'Optional but recommended for better AVIF support.',
        );
    }

    public static function check_webp_gd() {
        $ok = function_exists( 'imagewebp' );
        return array(
            'label'   => 'WebP via GD',
            'value'   => $ok ? 'Supported' : 'Not supported',
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok ? '' : 'GD was compiled without WebP support.',
        );
    }

    public static function check_avif_gd() {
        $ok = function_exists( 'imageavif' );
        return array(
            'label'   => 'AVIF via GD',
            'value'   => $ok ? 'Supported' : 'Not supported (PHP 8.1+ + libavif needed)',
            'status'  => $ok ? 'ok' : 'info',
            'message' => '',
        );
    }

    public static function check_webp_imagick() {
        if ( ! extension_loaded( 'imagick' ) ) {
            return array( 'label' => 'WebP via Imagick', 'value' => 'N/A', 'status' => 'info', 'message' => '' );
        }
        try {
            $formats = ( new Imagick() )->queryFormats( 'WEBP' );
            $ok      = ! empty( $formats );
        } catch ( Exception $e ) {
            $ok = false;
        }
        return array(
            'label'   => 'WebP via Imagick',
            'value'   => $ok ? 'Supported' : 'Not supported',
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok ? '' : 'Imagick installed but WebP not compiled in.',
        );
    }

    public static function check_uploads_writable() {
        $upload_dir = wp_upload_dir();
        $ok         = is_writable( $upload_dir['basedir'] );
        return array(
            'label'   => 'Uploads Folder Writable',
            'value'   => $ok ? 'Writable' : 'Not writable',
            'status'  => $ok ? 'ok' : 'error',
            'message' => $ok ? '' : 'The uploads folder is not writable. Fix permissions.',
        );
    }

    public static function check_htaccess_writable() {
        $upload_dir = wp_upload_dir();
        $htaccess   = $upload_dir['basedir'] . '/.htaccess';
        $ok         = ( file_exists( $htaccess ) && is_writable( $htaccess ) ) || is_writable( $upload_dir['basedir'] );
        return array(
            'label'   => '.htaccess Writable',
            'value'   => $ok ? 'Writable' : 'Not writable',
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok ? '' : 'Cannot write .htaccess. Redirect rules won\'t be applied automatically.',
        );
    }

    public static function check_mod_rewrite() {
        $ok = isset( $_SERVER['MOD_REWRITE'] ) ||
              ( function_exists( 'apache_get_modules' ) && in_array( 'mod_rewrite', apache_get_modules() ) );
        return array(
            'label'   => 'Apache mod_rewrite',
            'value'   => $ok ? 'Active' : 'Unknown / Not detected',
            'status'  => $ok ? 'ok' : 'info',
            'message' => $ok ? '' : 'Cannot auto-detect. If on Nginx, use the Nginx Config tab.',
        );
    }

    /**
     * Parse memory string like "128M", "1G" to MB integer.
     *
     * @param string $val
     * @return int  MB, or -1 for unlimited
     */
    public static function parse_memory( $val ) {
        if ( $val === '-1' ) return -1;
        $val  = trim( $val );
        $last = strtolower( $val[ strlen( $val ) - 1 ] );
        $num  = (int) $val;
        switch ( $last ) {
            case 'g': return $num * 1024;
            case 'm': return $num;
            case 'k': return $num / 1024;
        }
        return $num / 1048576;
    }

    /**
     * Returns true if there are any error-level issues.
     *
     * @return bool
     */
    public static function has_errors() {
        foreach ( self::check() as $item ) {
            if ( $item['status'] === 'error' ) return true;
        }
        return false;
    }

    /**
     * Show admin notice if critical issues exist.
     */
    public static function admin_notice() {
        foreach ( self::check() as $item ) {
            if ( $item['status'] === 'error' ) {
                echo '<div class="notice notice-error"><p><strong>WP Image Optimizer:</strong> ' . esc_html( $item['message'] ) . '</p></div>';
            }
        }
    }
}
