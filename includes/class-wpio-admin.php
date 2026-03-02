<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin UI: Settings, Bulk Convert, Nginx Config, Stats Dashboard, Backup/Restore.
 */
class WPIO_Admin {

    public function __construct() {
        add_action( 'admin_menu',                    array( $this, 'add_menu' ) );
        add_action( 'admin_init',                    array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_wpio_batch_convert',    array( $this, 'ajax_batch_convert' ) );
        add_action( 'wp_ajax_wpio_restore_image',    array( $this, 'ajax_restore_image' ) );
        add_action( 'wp_ajax_wpio_delete_backup',    array( $this, 'ajax_delete_backup' ) );
        add_action( 'add_attachment',                array( $this, 'on_upload' ) );
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
        register_setting( 'wpio_settings', 'wpio_format',         array( 'default' => 'webp' ) );
        register_setting( 'wpio_settings', 'wpio_quality',        array( 'default' => 82 ) );
        register_setting( 'wpio_settings', 'wpio_auto_convert',   array( 'default' => '1' ) );
        register_setting( 'wpio_settings', 'wpio_backup_enabled', array( 'default' => '1' ) );
    }

    private function get_tabs() {
        return array(
            'dashboard' => __( '📊 Dashboard', 'wp-image-optimizer' ),
            'bulk'      => __( '⚡ Bulk Convert', 'wp-image-optimizer' ),
            'settings'  => __( '⚙️ Settings', 'wp-image-optimizer' ),
            'nginx'     => __( '🔧 Nginx Config', 'wp-image-optimizer' ),
        );
    }

    public function render_page() {
        $tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        $page_url = admin_url( 'upload.php?page=wp-image-optimizer' );
        $format   = get_option( 'wpio_format', 'webp' );
        $quality  = get_option( 'wpio_quality', 82 );
        $auto     = get_option( 'wpio_auto_convert', '1' );
        $backup   = get_option( 'wpio_backup_enabled', '1' );
        ?>
        <div class="wrap wpio-wrap">
            <h1 style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:28px;">🖼️</span>
                <?php esc_html_e( 'WP Image Optimizer', 'wp-image-optimizer' ); ?>
                <span style="font-size:12px;background:#2271b1;color:#fff;padding:2px 8px;border-radius:20px;font-weight:400;">v1.1</span>
            </h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <?php foreach ( $this->get_tabs() as $key => $label ) : ?>
                    <a href="<?php echo esc_url( $page_url . '&tab=' . $key ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ( $tab ) {
                case 'dashboard': $this->render_dashboard(); break;
                case 'bulk':      $this->render_bulk( $format ); break;
                case 'settings':  $this->render_settings( $format, $quality, $auto, $backup ); break;
                case 'nginx':     $this->render_nginx( $format ); break;
            }
            ?>
        </div>

        <style>
        .wpio-wrap { max-width: 960px; }
        .wpio-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 20px 0; }
        .wpio-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .wpio-card .wpio-card-num { font-size: 36px; font-weight: 700; color: #2271b1; line-height: 1; }
        .wpio-card .wpio-card-num.green { color: #00a32a; }
        .wpio-card .wpio-card-num.orange { color: #dba617; }
        .wpio-card .wpio-card-label { font-size: 12px; color: #666; margin-top: 6px; text-transform: uppercase; letter-spacing: .5px; }
        .wpio-progress-wrap { background: #f0f0f0; border-radius: 20px; height: 22px; overflow: hidden; margin: 16px 0; }
        .wpio-progress-bar { height: 100%; background: linear-gradient(90deg, #2271b1, #00a32a); border-radius: 20px; transition: width .4s ease; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; font-weight: 600; min-width: 30px; }
        .wpio-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
        .wpio-section h2 { margin-top: 0; font-size: 16px; }
        .wpio-restore-table { width: 100%; border-collapse: collapse; }
        .wpio-restore-table th, .wpio-restore-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f0; text-align: left; font-size: 13px; }
        .wpio-restore-table th { background: #f9f9f9; font-weight: 600; color: #444; }
        .wpio-restore-table tr:last-child td { border-bottom: none; }
        .wpio-tip { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; border-radius: 0 6px 6px 0; margin: 16px 0; font-size: 13px; }
        .wpio-tip code { background: #dde; padding: 1px 5px; border-radius: 3px; }
        </style>
        <?php
    }

    private function render_dashboard() {
        $stats = WPIO_Stats::get();
        ?>
        <div class="wpio-cards">
            <div class="wpio-card">
                <div class="wpio-card-num"><?php echo esc_html( $stats['total'] ); ?></div>
                <div class="wpio-card-label">Total Images</div>
            </div>
            <div class="wpio-card">
                <div class="wpio-card-num green"><?php echo esc_html( $stats['converted'] ); ?></div>
                <div class="wpio-card-label">Converted</div>
            </div>
            <div class="wpio-card">
                <div class="wpio-card-num orange"><?php echo esc_html( $stats['pending'] ); ?></div>
                <div class="wpio-card-label">Pending</div>
            </div>
            <div class="wpio-card">
                <div class="wpio-card-num green"><?php echo esc_html( $stats['saved_mb'] ); ?> MB</div>
                <div class="wpio-card-label">Total Saved</div>
            </div>
            <div class="wpio-card">
                <div class="wpio-card-num green"><?php echo esc_html( $stats['saving_pct'] ); ?>%</div>
                <div class="wpio-card-label">Avg Reduction</div>
            </div>
        </div>

        <div class="wpio-section">
            <h2><?php esc_html_e( 'Conversion Progress', 'wp-image-optimizer' ); ?></h2>
            <div class="wpio-progress-wrap">
                <div class="wpio-progress-bar" style="width:<?php echo esc_attr( $stats['progress_pct'] ); ?>%;">
                    <?php echo esc_html( $stats['progress_pct'] ); ?>%
                </div>
            </div>
            <p style="color:#666;font-size:13px;">
                <?php printf(
                    esc_html__( '%1$d of %2$d images converted to %3$s', 'wp-image-optimizer' ),
                    $stats['converted'], $stats['total'], $stats['format']
                ); ?>
            </p>
        </div>

        <?php if ( ! empty( $stats['largest_save']['file'] ) ) : ?>
        <div class="wpio-section">
            <h2><?php esc_html_e( '🏆 Biggest Win', 'wp-image-optimizer' ); ?></h2>
            <p>
                <strong><?php echo esc_html( $stats['largest_save']['file'] ); ?></strong> —
                saved <strong><?php echo esc_html( round( $stats['largest_save']['saved'] / 1024, 1 ) ); ?> KB</strong>
                (<?php echo esc_html( $stats['largest_save']['pct'] ); ?>% reduction)
            </p>
        </div>
        <?php endif; ?>

        <div class="wpio-section">
            <h2><?php esc_html_e( '💾 Backup Storage', 'wp-image-optimizer' ); ?></h2>
            <?php if ( $stats['backup_bytes'] > 0 ) : ?>
                <p><?php printf(
                    esc_html__( 'Backups are using %s MB of disk space.', 'wp-image-optimizer' ),
                    '<strong>' . esc_html( $stats['backup_mb'] ) . '</strong>'
                ); ?></p>
                <p>
                    <button class="button" id="wpio-purge-backups" data-nonce="<?php echo wp_create_nonce( 'wpio_delete_backup_all' ); ?>">
                        🗑️ <?php esc_html_e( 'Purge All Backups', 'wp-image-optimizer' ); ?>
                    </button>
                    <span style="color:#666;font-size:12px;margin-left:8px;"><?php esc_html_e( 'Only do this after confirming the site looks fine.', 'wp-image-optimizer' ); ?></span>
                </p>
            <?php else : ?>
                <p style="color:#666;"><?php esc_html_e( 'No backups stored yet.', 'wp-image-optimizer' ); ?></p>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#wpio-purge-backups').on('click', function(){
                if ( ! confirm('Are you sure you want to delete all backups? This cannot be undone.') ) return;
                var btn = $(this);
                btn.prop('disabled', true).text('Purging...');
                $.post(ajaxurl, { action: 'wpio_delete_backup', scope: 'all', _wpnonce: btn.data('nonce') }, function(res){
                    if ( res.success ) {
                        location.reload();
                    } else {
                        alert('Error: ' + res.data);
                        btn.prop('disabled', false).text('🗑️ Purge All Backups');
                    }
                });
            });
        });
        </script>
        <?php
    }

    private function render_bulk( $format ) {
        ?>
        <div class="wpio-section">
            <h2><?php esc_html_e( 'Bulk Convert Existing Images', 'wp-image-optimizer' ); ?></h2>
            <p><?php esc_html_e( 'Scans your entire uploads folder and converts all JPG/PNG images. Original files are kept — existing links continue to work.', 'wp-image-optimizer' ); ?></p>

            <?php if ( WPIO_Nginx::is_nginx() ) : ?>
                <div class="notice notice-warning inline"><p>
                    ⚠️ <?php esc_html_e( 'Nginx detected: .htaccess rewrites won\'t work. Go to the Nginx Config tab.', 'wp-image-optimizer' ); ?>
                </p></div>
            <?php endif; ?>

            <div class="wpio-tip">
                💡 <?php esc_html_e( 'For large sites (1000+ images), use WP-CLI to avoid timeouts:', 'wp-image-optimizer' ); ?>
                <br><code>wp image-optimizer bulk --format=<?php echo esc_html( $format ); ?> --quality=82</code>
            </div>

            <button id="wpio-bulk-btn" class="button button-primary button-large">
                ⚡ <?php esc_html_e( 'Start Bulk Convert', 'wp-image-optimizer' ); ?>
            </button>
            <div id="wpio-bulk-result" style="margin-top:16px;"></div>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#wpio-bulk-btn').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true).text('⏳ Converting…');
                $('#wpio-bulk-result').html('<p style="color:#666;">This may take a while for large libraries…</p>');
                $.post(ajaxurl, { action: 'wpio_batch_convert', _wpnonce: '<?php echo wp_create_nonce('wpio_batch'); ?>' }, function(res){
                    btn.prop('disabled', false).text('⚡ Start Bulk Convert');
                    if(res.success){
                        var d = res.data;
                        $('#wpio-bulk-result').html(
                            '<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:16px;">'
                            + '<strong style="color:#155724;">✔ Done!</strong><br>'
                            + '<span style="color:#155724;">Converted: <strong>'+d.success+'</strong> &nbsp;|&nbsp; Errors: <strong>'+d.error+'</strong></span>'
                            + '</div>'
                        );
                    } else {
                        $('#wpio-bulk-result').html('<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:16px;"><strong style="color:#721c24;">Error:</strong> <span style="color:#721c24;">'+res.data+'</span></div>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    private function render_settings( $format, $quality, $auto, $backup ) {
        ?>
        <div class="wpio-section">
            <h2><?php esc_html_e( 'Plugin Settings', 'wp-image-optimizer' ); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'wpio_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Output Format', 'wp-image-optimizer' ); ?></th>
                        <td>
                            <select name="wpio_format" style="min-width:160px;">
                                <option value="webp" <?php selected( $format, 'webp' ); ?>>WebP (recommended)</option>
                                <option value="avif" <?php selected( $format, 'avif' ); ?>>AVIF (smaller, newer)</option>
                            </select>
                            <p class="description"><?php esc_html_e( 'WebP has wider browser support. AVIF is ~20% smaller but requires PHP 8.1+ with libavif.', 'wp-image-optimizer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Quality', 'wp-image-optimizer' ); ?></th>
                        <td>
                            <input type="range" name="wpio_quality" id="wpio_quality_range" value="<?php echo esc_attr( $quality ); ?>" min="1" max="100" style="width:200px;vertical-align:middle;" oninput="document.getElementById('wpio_quality_val').textContent=this.value" />
                            <span id="wpio_quality_val" style="font-weight:600;margin-left:8px;"><?php echo esc_html( $quality ); ?></span>/100
                            <p class="description"><?php esc_html_e( '80–85 is a good balance of quality and file size.', 'wp-image-optimizer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Auto-convert on Upload', 'wp-image-optimizer' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpio_auto_convert" value="1" <?php checked( $auto, '1' ); ?> />
                                <?php esc_html_e( 'Automatically convert new images when uploaded to the Media Library', 'wp-image-optimizer' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Keep Backup of Originals', 'wp-image-optimizer' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpio_backup_enabled" value="1" <?php checked( $backup, '1' ); ?> />
                                <?php esc_html_e( 'Save a copy of original images before conversion (stored in /uploads/wpio-backups/)', 'wp-image-optimizer' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Recommended. Allows one-click restore if anything looks wrong.', 'wp-image-optimizer' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Settings', 'wp-image-optimizer' ) ); ?>
            </form>
        </div>
        <?php
    }

    private function render_nginx( $format ) {
        ?>
        <div class="wpio-section">
            <h2><?php esc_html_e( 'Nginx Configuration Snippet', 'wp-image-optimizer' ); ?></h2>
            <p><?php esc_html_e( 'Paste this inside your Nginx server {} block, then reload Nginx. This replaces .htaccess rewrites for Nginx servers.', 'wp-image-optimizer' ); ?></p>
            <textarea class="large-text code" rows="18" readonly style="font-family:monospace;font-size:13px;background:#1e1e2e;color:#cdd6f4;border:none;border-radius:6px;padding:16px;"><?php echo esc_textarea( WPIO_Nginx::build_rules( $format ) ); ?></textarea>
            <p style="margin-top:12px;">
                <button class="button button-primary" onclick="var t=this.previousElementSibling.previousElementSibling;t.select();document.execCommand('copy');this.textContent='✔ Copied!';setTimeout(()=>this.textContent='📋 Copy to Clipboard',2000);return false;">📋 <?php esc_html_e( 'Copy to Clipboard', 'wp-image-optimizer' ); ?></button>
            </p>
            <div class="wpio-tip">
                <?php esc_html_e( 'After pasting, reload Nginx:', 'wp-image-optimizer' ); ?><br>
                <code>sudo nginx -t && sudo systemctl reload nginx</code>
            </div>
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
        WPIO_Stats::bust_cache();
        wp_send_json_success( array(
            'success' => count( $results['success'] ),
            'error'   => count( $results['error'] ) + count( $results['skipped'] ),
        ) );
    }

    public function ajax_restore_image() {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! check_ajax_referer( 'wpio_restore_' . $attachment_id, '_wpnonce', false ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $file   = get_attached_file( $attachment_id );
        $result = WPIO_Backup::restore( $file );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        WPIO_Stats::bust_cache();
        wp_send_json_success( array( 'message' => 'Restored successfully.' ) );
    }

    public function ajax_delete_backup() {
        if ( ! check_ajax_referer( 'wpio_delete_backup_all', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $backup_dir = WPIO_Backup::backup_dir();
        if ( is_dir( $backup_dir ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
            }
            rmdir( $backup_dir );
        }
        WPIO_Stats::bust_cache();
        wp_send_json_success();
    }

    public function on_upload( $attachment_id ) {
        if ( get_option( 'wpio_auto_convert', '1' ) !== '1' ) return;
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) return;
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = (int) get_option( 'wpio_quality', 82 );
        if ( get_option( 'wpio_backup_enabled', '1' ) === '1' ) {
            WPIO_Backup::backup( $file );
        }
        WPIO_Converter::convert( $file, $format, $quality );
        WPIO_Stats::bust_cache();
    }
}
