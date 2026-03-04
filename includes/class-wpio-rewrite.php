<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages .htaccess rewrite rules so old .jpg/.png URLs transparently
 * serve the converted WebP/AVIF file — no link changes needed.
 *
 * Also detects when the plugin's rules have been removed or modified
 * and shows a dismissible admin notice.
 */
class WPIO_Rewrite {

    const MARKER       = 'WP Image Optimizer';
    const TAMPER_KEY   = 'wpio_rewrite_tampered';
    const DISMISS_KEY  = 'wpio_rewrite_notice_dismissed';
    const CRON_HOOK    = 'wpio_check_rewrite_rules';

    /* -------------------------------------------------------
       Activation / Deactivation
    ------------------------------------------------------- */

    public static function activate() {
        $format = get_option( 'wpio_format', 'webp' );
        self::insert_rules( $format );
        self::schedule_check();
    }

    public static function deactivate() {
        self::remove_rules();
        self::unschedule_check();
        flush_rewrite_rules();
    }

    /* -------------------------------------------------------
       Cron scheduling
    ------------------------------------------------------- */

    public static function schedule_check() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule_check() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::CRON_HOOK );
    }

    /* -------------------------------------------------------
       Rule insertion / removal
    ------------------------------------------------------- */

    public static function insert_rules( $format = 'webp' ) {
        $htaccess = self::htaccess_path();
        $rules    = self::build_rules( $format );
        insert_with_markers( $htaccess, self::MARKER, $rules );
        // Rules just (re)written — clear any tamper flag.
        delete_option( self::TAMPER_KEY );
        delete_transient( self::DISMISS_KEY );
    }

    public static function remove_rules() {
        $htaccess = self::htaccess_path();
        insert_with_markers( $htaccess, self::MARKER, array() );
    }

    /* -------------------------------------------------------
       Tamper detection
    ------------------------------------------------------- */

    /**
     * Checks whether the plugin's marker block still exists in .htaccess.
     * Called daily via cron and also on every admin_init.
     */
    public static function check_rules() {
        // Only relevant on Apache — skip Nginx silently.
        if ( WPIO_Nginx::is_nginx() ) {
            delete_option( self::TAMPER_KEY );
            return;
        }

        $htaccess = self::htaccess_path();
        if ( ! file_exists( $htaccess ) ) {
            update_option( self::TAMPER_KEY, 'missing_file' );
            return;
        }

        $content = file_get_contents( $htaccess );
        $marker  = '# BEGIN ' . self::MARKER;

        if ( strpos( $content, $marker ) === false ) {
            update_option( self::TAMPER_KEY, 'rules_missing' );
        } else {
            // Rules are present — clear any stale flag.
            delete_option( self::TAMPER_KEY );
        }
    }

    /* -------------------------------------------------------
       Admin notice
    ------------------------------------------------------- */

    /**
     * Renders the dismissible admin notice when tampering is detected.
     * Hooked to admin_notices.
     */
    public static function maybe_show_notice() {
        // Don't show on frontend or if dismissed recently.
        if ( ! is_admin() ) return;
        if ( get_transient( self::DISMISS_KEY ) ) return;

        $tamper = get_option( self::TAMPER_KEY );
        if ( ! $tamper ) return;

        $msg = $tamper === 'missing_file'
            ? 'WP Image Optimizer could not find your <code>.htaccess</code> file. Images may not be served in WebP/AVIF format.'
            : 'WP Image Optimizer\'s rewrite rules have been removed from <code>.htaccess</code>. Images may be falling back to their original format.';

        $delivery_url = admin_url( 'upload.php?page=wp-image-optimizer&tab=delivery' );
        $dismiss_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=wpio_dismiss_rewrite_notice' ),
            'wpio_dismiss_rewrite_notice'
        );
        $repair_url   = wp_nonce_url(
            admin_url( 'admin-post.php?action=wpio_repair_rewrite_rules' ),
            'wpio_repair_rewrite_rules'
        );
        ?>
        <div class="wpio-admin-notice" id="wpio-rewrite-notice">
            <span class="wpio-notice-icon">⚠️</span>
            <div class="wpio-notice-body">
                <strong>Image Optimizer — Rewrite Rules Modified</strong>
                <span class="wpio-notice-sep">·</span>
                <span><?php echo wp_kses( $msg, array( 'code' => array() ) ); ?></span>
            </div>
            <div class="wpio-notice-actions">
                <a href="<?php echo esc_url( $repair_url ); ?>" class="wpio-notice-btn wpio-notice-btn-primary">🔧 Repair Rules</a>
                <a href="<?php echo esc_url( $delivery_url ); ?>" class="wpio-notice-btn wpio-notice-btn-secondary">Learn More</a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="wpio-notice-dismiss" title="Dismiss for 7 days">✕</a>
            </div>
            <style>
                .wpio-admin-notice {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    background: #fff8e1;
                    border-left: 4px solid #f59e0b;
                    border-radius: 4px;
                    padding: 12px 16px;
                    margin: 8px 0 16px;
                    box-shadow: 0 1px 4px rgba(0,0,0,.07);
                    font-size: 13px;
                    flex-wrap: wrap;
                }
                .wpio-notice-icon { font-size: 18px; flex-shrink: 0; }
                .wpio-notice-body { flex: 1; min-width: 200px; line-height: 1.5; }
                .wpio-notice-sep  { margin: 0 4px; color: #ccc; }
                .wpio-notice-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
                .wpio-notice-btn {
                    display: inline-block;
                    padding: 5px 12px;
                    border-radius: 4px;
                    font-size: 12px;
                    font-weight: 600;
                    text-decoration: none;
                    white-space: nowrap;
                }
                .wpio-notice-btn-primary  { background: #FF2462; color: #fff; }
                .wpio-notice-btn-primary:hover { background: #e01f56; color: #fff; }
                .wpio-notice-btn-secondary { background: #f0f0f1; color: #3c434a; }
                .wpio-notice-btn-secondary:hover { background: #dcdcde; color: #1d2327; }
                .wpio-notice-dismiss {
                    font-size: 16px;
                    color: #999;
                    text-decoration: none;
                    padding: 2px 6px;
                    border-radius: 50%;
                    line-height: 1;
                    flex-shrink: 0;
                }
                .wpio-notice-dismiss:hover { background: #f0f0f1; color: #333; }
            </style>
        </div>
        <?php
    }

    /* -------------------------------------------------------
       admin-post handlers (dismiss + repair)
    ------------------------------------------------------- */

    public static function handle_dismiss() {
        check_admin_referer( 'wpio_dismiss_rewrite_notice' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        // Suppress for 7 days.
        set_transient( self::DISMISS_KEY, '1', 7 * DAY_IN_SECONDS );
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }

    public static function handle_repair() {
        check_admin_referer( 'wpio_repair_rewrite_rules' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $format = get_option( 'wpio_format', 'webp' );
        self::insert_rules( $format );
        wp_safe_redirect( add_query_arg( 'wpio_repaired', '1', wp_get_referer() ?: admin_url() ) );
        exit;
    }

    /* -------------------------------------------------------
       Helpers
    ------------------------------------------------------- */

    /**
     * Returns the path to the root .htaccess file.
     *
     * Using ABSPATH (site root) instead of uploads/.htaccess so that rules
     * work reliably on all Apache/LiteSpeed setups. Many hardened servers
     * (and security plugins like Wordfence) block .htaccess execution inside
     * /uploads/ specifically to prevent malicious upload exploits — which
     * would silently break our rewrite rules if placed there.
     */
    private static function htaccess_path() {
        return rtrim( ABSPATH, '/' ) . '/.htaccess';
    }

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
