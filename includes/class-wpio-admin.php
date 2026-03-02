<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin UI: settings page + batch conversion trigger + Nginx snippet tab.
 */
class WPIO_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_wpio_batch_convert', array( $this, 'ajax_batch_convert' ) );
        add_action( 'add_attachment', array( $this, 'on_upload' ) );
        new WPIO_Media_Column();
    }

    public function add_menu() {
        add_media_page(
            __( 'Image Optimizer', 'wp-image-optimizer' ),
            __( 'Image Optimizer', 'wp-image-optimizer' ),
            'manage_options',
            'wp-image-optimizer',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting( 'wpio_settings', 'wpio_format',       array( 'default' => 'webp' ) );
        register_setting( 'wpio_settings', 'wpio_quality',      array( 'default' => 82 ) );
        register_setting( 'wpio_settings', 'wpio_auto_convert', array( 'default' => '1' ) );
    }

    public function render_page() {
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = get_option( 'wpio_quality', 82 );
        $auto    = get_option( 'wpio_auto_convert', '1' );
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
        $page_url = admin_url( 'upload.php?page=wp-image-optimizer' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Image Optimizer', 'wp-image-optimizer' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( $page_url . '&tab=settings' ); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'wp-image-optimizer' ); ?></a>
                <a href="<?php echo esc_url( $page_url . '&tab=bulk' ); ?>" class="nav-tab <?php echo $tab === 'bulk' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Bulk Convert', 'wp-image-optimizer' ); ?></a>
                <a href="<?php echo esc_url( $page_url . '&tab=nginx' ); ?>" class="nav-tab <?php echo $tab === 'nginx' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Nginx Config', 'wp-image-optimizer' ); ?></a>
            </nav>

            <?php if ( $tab === 'settings' ) : ?>
                <form method="post" action="options.php">
                    <?php settings_fields( 'wpio_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Output Format', 'wp-image-optimizer' ); ?></th>
                            <td>
                                <select name="wpio_format">
                                    <option value="webp" <?php selected( $format, 'webp' ); ?>>WebP</option>
                                    <option value="avif" <?php selected( $format, 'avif' ); ?>>AVIF</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Quality (1–100)', 'wp-image-optimizer' ); ?></th>
                            <td><input type="number" name="wpio_quality" value="<?php echo esc_attr( $quality ); ?>" min="1" max="100" /></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Auto-convert on Upload', 'wp-image-optimizer' ); ?></th>
                            <td><input type="checkbox" name="wpio_auto_convert" value="1" <?php checked( $auto, '1' ); ?> /></td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'wp-image-optimizer' ) ); ?>
                </form>

            <?php elseif ( $tab === 'bulk' ) : ?>
                <h2><?php esc_html_e( 'Bulk Convert Existing Images', 'wp-image-optimizer' ); ?></h2>
                <p><?php esc_html_e( 'Scans your uploads folder and converts all JPG/PNG images. Original files are kept — links continue to work via rewrite rules.', 'wp-image-optimizer' ); ?></p>
                <?php if ( WPIO_Nginx::is_nginx() ) : ?>
                    <div class="notice notice-warning"><p><?php esc_html_e( 'Nginx detected: .htaccess rewrites won\'t work. Use the Nginx Config tab to get your server block snippet.', 'wp-image-optimizer' ); ?></p></div>
                <?php endif; ?>
                <button id="wpio-bulk-btn" class="button button-primary"><?php esc_html_e( 'Start Bulk Convert', 'wp-image-optimizer' ); ?></button>
                <div id="wpio-bulk-result" style="margin-top:12px;"></div>
                <script>
                jQuery(document).ready(function($){
                    $('#wpio-bulk-btn').on('click', function(){
                        var btn = $(this);
                        btn.prop('disabled', true).text('Converting…');
                        $.post(ajaxurl, { action: 'wpio_batch_convert', _wpnonce: '<?php echo wp_create_nonce('wpio_batch'); ?>' }, function(res){
                            btn.prop('disabled', false).text('Start Bulk Convert');
                            if(res.success){
                                var d = res.data;
                                $('#wpio-bulk-result').html('<p style="color:green">✔ Converted: '+d.success+' | Errors: '+d.error+'</p>');
                            } else {
                                $('#wpio-bulk-result').html('<p style="color:red">Error: '+res.data+'</p>');
                            }
                        });
                    });
                });
                </script>

            <?php elseif ( $tab === 'nginx' ) : ?>
                <h2><?php esc_html_e( 'Nginx Configuration Snippet', 'wp-image-optimizer' ); ?></h2>
                <p><?php esc_html_e( 'Copy and paste this snippet inside your Nginx server {} block, then reload Nginx. This replaces the .htaccess approach for Nginx servers.', 'wp-image-optimizer' ); ?></p>
                <textarea class="large-text code" rows="20" readonly><?php echo esc_textarea( WPIO_Nginx::build_rules( $format ) ); ?></textarea>
                <p>
                    <button class="button" onclick="var t=this.previousElementSibling;t.select();document.execCommand('copy');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy to Clipboard',2000);return false;"><?php esc_html_e( 'Copy to Clipboard', 'wp-image-optimizer' ); ?></button>
                </p>
                <p class="description"><?php esc_html_e( 'After adding, run: sudo nginx -t && sudo systemctl reload nginx', 'wp-image-optimizer' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_batch_convert() {
        if ( ! check_ajax_referer( 'wpio_batch', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = (int) get_option( 'wpio_quality', 82 );
        $results = WPIO_Converter::batch_convert( $format, $quality );
        wp_send_json_success( array(
            'success' => count( $results['success'] ),
            'error'   => count( $results['error'] ) + count( $results['skipped'] ),
        ) );
    }

    public function on_upload( $attachment_id ) {
        if ( get_option( 'wpio_auto_convert', '1' ) !== '1' ) return;
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) return;
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = (int) get_option( 'wpio_quality', 82 );
        WPIO_Converter::convert( $file, $format, $quality );
    }
}
