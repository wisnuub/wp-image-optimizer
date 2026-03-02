<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin UI: settings page + batch conversion trigger.
 */
class WPIO_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_wpio_batch_convert', array( $this, 'ajax_batch_convert' ) );
        // Auto-convert on new upload
        add_action( 'add_attachment', array( $this, 'on_upload' ) );
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
        register_setting( 'wpio_settings', 'wpio_format',  array( 'default' => 'webp' ) );
        register_setting( 'wpio_settings', 'wpio_quality', array( 'default' => 82 ) );
        register_setting( 'wpio_settings', 'wpio_auto_convert', array( 'default' => '1' ) );
    }

    public function render_page() {
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = get_option( 'wpio_quality', 82 );
        $auto    = get_option( 'wpio_auto_convert', '1' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Image Optimizer', 'wp-image-optimizer' ); ?></h1>
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

            <hr />
            <h2><?php esc_html_e( 'Bulk Convert Existing Images', 'wp-image-optimizer' ); ?></h2>
            <p><?php esc_html_e( 'This will scan your uploads folder and convert all JPG/PNG images. Original files are kept — links will continue to work via .htaccess rewrite.', 'wp-image-optimizer' ); ?></p>
            <button id="wpio-bulk-btn" class="button button-primary"><?php esc_html_e( 'Start Bulk Convert', 'wp-image-optimizer' ); ?></button>
            <div id="wpio-bulk-result" style="margin-top:12px;"></div>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('#wpio-bulk-btn').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true).text('Converting…');
                $.post(ajaxurl, { action: 'wpio_batch_convert', _wpnonce: '<?php echo wp_create_nonce('wpio_batch'); ?>' }, function(res){
                    btn.prop('disabled', false).text('Start Bulk Convert');
                    if(res.success){
                        var d = res.data;
                        $('#wpio-bulk-result').html('<p style="color:green">✔ Converted: '+d.success+' | Skipped/Error: '+d.error+'</p>');
                    } else {
                        $('#wpio-bulk-result').html('<p style="color:red">Error: '+res.data+'</p>');
                    }
                });
            });
        });
        </script>
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
