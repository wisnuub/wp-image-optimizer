<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIO_Admin {

    public function __construct() {
        add_action( 'admin_menu',                      array( $this, 'add_menu' ) );
        add_action( 'admin_init',                      array( $this, 'register_settings' ) );
        add_action( 'admin_notices',                   array( 'WPIO_Environment', 'admin_notice' ) );
        add_action( 'wp_ajax_wpio_queue_start',        array( $this, 'ajax_queue_start' ) );
        add_action( 'wp_ajax_wpio_queue_chunk',        array( $this, 'ajax_queue_chunk' ) );
        add_action( 'wp_ajax_wpio_queue_cancel',       array( $this, 'ajax_queue_cancel' ) );
        add_action( 'wp_ajax_wpio_queue_progress',     array( $this, 'ajax_queue_progress' ) );
        add_action( 'wp_ajax_wpio_restore_image',      array( $this, 'ajax_restore_image' ) );
        add_action( 'wp_ajax_wpio_delete_backup',      array( $this, 'ajax_delete_backup' ) );
        add_action( 'add_attachment',                  array( $this, 'on_upload' ) );
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
        $defaults = array(
            'wpio_format'         => 'webp',
            'wpio_quality'        => 82,
            'wpio_auto_convert'   => '1',
            'wpio_backup_enabled' => '1',
            'wpio_strip_exif'     => '1',
            'wpio_max_dimension'  => 0,
            'wpio_batch_size'     => 5,
            'wpio_sleep_time'     => 500,
            'wpio_memory_limit'   => '256M',
            'wpio_exec_time'      => 120,
        );
        foreach ( $defaults as $key => $default ) {
            register_setting( 'wpio_settings', $key, array( 'default' => $default ) );
        }
    }

    private function get_tabs() {
        return array(
            'dashboard' => '📊 Dashboard',
            'bulk'      => '⚡ Bulk Convert',
            'settings'  => '⚙️ Settings',
            'nginx'     => '🔧 Nginx Config',
            'system'    => '🖥️ System Status',
        );
    }

    public function render_page() {
        $tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        $page_url = admin_url( 'upload.php?page=wp-image-optimizer' );
        $format   = get_option( 'wpio_format', 'webp' );
        ?>
        <div class="wrap wpio-wrap">
            <h1 style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:26px;">🖼️</span>
                <?php esc_html_e( 'WP Image Optimizer', 'wp-image-optimizer' ); ?>
                <span style="font-size:11px;background:#2271b1;color:#fff;padding:2px 8px;border-radius:20px;font-weight:400;letter-spacing:.5px;">v1.1</span>
            </h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:24px;">
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
                case 'settings':  $this->render_settings(); break;
                case 'nginx':     $this->render_nginx( $format ); break;
                case 'system':    $this->render_system(); break;
            }
            ?>
        </div>
        <?php $this->render_styles(); ?>
        <?php
    }

    private function render_styles() {
        ?>
        <style>
        .wpio-wrap { max-width: 980px; }
        .wpio-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin: 0 0 24px; }
        .wpio-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 10px; padding: 20px 16px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .wpio-card-num { font-size: 34px; font-weight: 700; line-height: 1.1; }
        .wpio-card-num.blue   { color: #2271b1; }
        .wpio-card-num.green  { color: #00a32a; }
        .wpio-card-num.orange { color: #dba617; }
        .wpio-card-num.red    { color: #d63638; }
        .wpio-card-label { font-size: 11px; color: #888; margin-top: 6px; text-transform: uppercase; letter-spacing: .6px; }
        .wpio-progress-wrap { background: #f0f0f1; border-radius: 20px; height: 24px; overflow: hidden; margin: 12px 0 6px; }
        .wpio-progress-bar { height: 100%; background: linear-gradient(90deg, #2271b1 0%, #00a32a 100%); border-radius: 20px; transition: width .5s ease; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; font-weight: 700; min-width: 36px; }
        .wpio-section { background: #fff; border: 1px solid #e2e4e7; border-radius: 10px; padding: 24px 28px; margin-bottom: 20px; }
        .wpio-section h2 { margin: 0 0 16px; font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .wpio-tip { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; border-radius: 0 6px 6px 0; margin: 16px 0; font-size: 13px; line-height: 1.6; }
        .wpio-tip code { background: #dde8f7; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
        .wpio-warn-tip { background: #fef9e7; border-left: 4px solid #dba617; padding: 12px 16px; border-radius: 0 6px 6px 0; margin: 16px 0; font-size: 13px; }
        /* System Status table */
        .wpio-sys-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .wpio-sys-table th { background: #f9f9f9; padding: 10px 14px; text-align: left; color: #444; font-weight: 600; border-bottom: 2px solid #e2e4e7; }
        .wpio-sys-table td { padding: 10px 14px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
        .wpio-sys-table tr:last-child td { border-bottom: none; }
        .wpio-status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
        .wpio-dot-ok      { background: #00a32a; }
        .wpio-dot-warning { background: #dba617; }
        .wpio-dot-error   { background: #d63638; }
        .wpio-dot-info    { background: #2271b1; }
        /* Bulk progress */
        #wpio-live-progress { display:none; margin-top:20px; }
        .wpio-bulk-log { background: #1e1e2e; color: #cdd6f4; border-radius: 6px; padding: 14px; font-family: monospace; font-size: 12px; max-height: 180px; overflow-y: auto; margin-top: 12px; }
        .wpio-bulk-log p { margin: 2px 0; }
        .wpio-bulk-log .log-ok    { color: #a6e3a1; }
        .wpio-bulk-log .log-error { color: #f38ba8; }
        .wpio-bulk-log .log-info  { color: #89dceb; }
        </style>
        <?php
    }

    private function render_dashboard() {
        $stats = WPIO_Stats::get();
        $q     = WPIO_Queue::get_progress();
        ?>
        <?php if ( $q['running'] ) : ?>
        <div class="notice notice-info inline" style="margin-bottom:20px;">
            <p>⏳ <?php esc_html_e( 'Bulk conversion is currently running in the background.', 'wp-image-optimizer' ); ?>
            <a href="<?php echo esc_url( admin_url( 'upload.php?page=wp-image-optimizer&tab=bulk' ) ); ?>"><?php esc_html_e( 'View progress →', 'wp-image-optimizer' ); ?></a></p>
        </div>
        <?php endif; ?>

        <div class="wpio-cards">
            <div class="wpio-card"><div class="wpio-card-num blue"><?php echo esc_html( $stats['total'] ); ?></div><div class="wpio-card-label">Total Images</div></div>
            <div class="wpio-card"><div class="wpio-card-num green"><?php echo esc_html( $stats['converted'] ); ?></div><div class="wpio-card-label">Converted</div></div>
            <div class="wpio-card"><div class="wpio-card-num orange"><?php echo esc_html( $stats['pending'] ); ?></div><div class="wpio-card-label">Pending</div></div>
            <div class="wpio-card"><div class="wpio-card-num green"><?php echo esc_html( $stats['saved_mb'] ); ?> MB</div><div class="wpio-card-label">Total Saved</div></div>
            <div class="wpio-card"><div class="wpio-card-num green"><?php echo esc_html( $stats['saving_pct'] ); ?>%</div><div class="wpio-card-label">Avg Reduction</div></div>
        </div>

        <div class="wpio-section">
            <h2>📈 Conversion Progress</h2>
            <div class="wpio-progress-wrap">
                <div class="wpio-progress-bar" style="width:<?php echo esc_attr( $stats['progress_pct'] ); ?>%;">
                    <?php echo esc_html( $stats['progress_pct'] ); ?>%
                </div>
            </div>
            <p style="color:#666;font-size:13px;margin:0;">
                <?php printf( esc_html__( '%1$d of %2$d images converted to %3$s', 'wp-image-optimizer' ), $stats['converted'], $stats['total'], $stats['format'] ); ?>
            </p>
        </div>

        <?php if ( ! empty( $stats['largest_save']['file'] ) ) : ?>
        <div class="wpio-section">
            <h2>🏆 Biggest Win</h2>
            <p style="margin:0;">
                <strong><?php echo esc_html( $stats['largest_save']['file'] ); ?></strong> —
                saved <strong><?php echo esc_html( round( $stats['largest_save']['saved'] / 1024, 1 ) ); ?> KB</strong>
                (<?php echo esc_html( $stats['largest_save']['pct'] ); ?>% reduction)
            </p>
        </div>
        <?php endif; ?>

        <div class="wpio-section">
            <h2>💾 Backup Storage</h2>
            <?php if ( $stats['backup_bytes'] > 0 ) : ?>
                <p><?php printf( esc_html__( 'Backups are using %s MB of disk space.', 'wp-image-optimizer' ), '<strong>' . esc_html( $stats['backup_mb'] ) . '</strong>' ); ?></p>
                <button class="button" id="wpio-purge-backups" data-nonce="<?php echo wp_create_nonce( 'wpio_delete_backup_all' ); ?>">🗑️ Purge All Backups</button>
                <span style="color:#888;font-size:12px;margin-left:8px;"><?php esc_html_e( 'Only do this after verifying the site looks correct.', 'wp-image-optimizer' ); ?></span>
            <?php else : ?>
                <p style="color:#888;margin:0;"><?php esc_html_e( 'No backups stored yet.', 'wp-image-optimizer' ); ?></p>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#wpio-purge-backups').on('click', function(){
                if(!confirm('Delete all backups? This cannot be undone.')) return;
                var btn=$(this); btn.prop('disabled',true).text('Purging...');
                $.post(ajaxurl,{action:'wpio_delete_backup',scope:'all',_wpnonce:btn.data('nonce')},function(res){
                    res.success ? location.reload() : (alert('Error: '+res.data), btn.prop('disabled',false).text('🗑️ Purge All Backups'));
                });
            });
        });
        </script>
        <?php
    }

    private function render_bulk( $format ) {
        $q = WPIO_Queue::get_progress();
        ?>
        <div class="wpio-section">
            <h2>⚡ Bulk Convert Existing Images</h2>
            <p style="color:#555;margin-top:0;"><?php esc_html_e( 'Images are processed in small chunks so your server is never overloaded. You can leave this page — processing continues in the background.', 'wp-image-optimizer' ); ?></p>

            <?php if ( WPIO_Nginx::is_nginx() ) : ?>
            <div class="wpio-warn-tip">⚠️ <?php esc_html_e( 'Nginx detected: .htaccess rules won\'t work. See the Nginx Config tab.', 'wp-image-optimizer' ); ?></div>
            <?php endif; ?>

            <div class="wpio-tip">💡 For large libraries (1000+ images), use WP-CLI to avoid any timeout risk:<br><code>wp image-optimizer bulk --format=<?php echo esc_html( $format ); ?> --quality=<?php echo esc_html( get_option( 'wpio_quality', 82 ) ); ?></code></div>

            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <button id="wpio-bulk-start" class="button button-primary button-large" <?php echo $q['running'] ? 'disabled' : ''; ?>>
                    ⚡ <?php echo $q['running'] ? esc_html__( 'Running…', 'wp-image-optimizer' ) : esc_html__( 'Start Bulk Convert', 'wp-image-optimizer' ); ?>
                </button>
                <?php if ( $q['running'] ) : ?>
                <button id="wpio-bulk-cancel" class="button button-large" style="color:#d63638;border-color:#d63638;">✕ Cancel</button>
                <?php endif; ?>
            </div>

            <div id="wpio-live-progress" <?php echo $q['running'] ? 'style="display:block;"' : ''; ?>>
                <div class="wpio-progress-wrap" style="margin-top:16px;">
                    <div class="wpio-progress-bar" id="wpio-prog-bar" style="width:<?php
                        $p = $q['progress'];
                        echo $p['total'] > 0 ? round(($p['done']/$p['total'])*100) : 0;
                    ?>%;">0%</div>
                </div>
                <p id="wpio-prog-text" style="color:#555;font-size:13px;">
                    <?php echo esc_html( $q['progress']['done'] . ' / ' . $q['progress']['total'] . ' images processed' ); ?>
                </p>
                <div class="wpio-bulk-log" id="wpio-bulk-log"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            var running = <?php echo $q['running'] ? 'true' : 'false'; ?>;
            var pollTimer = null;

            function addLog(msg, type) {
                var cls = type || 'log-info';
                var log = $('#wpio-bulk-log');
                log.append('<p class="'+cls+'">'+msg+'</p>');
                log.scrollTop(log[0].scrollHeight);
            }

            function updateProgress(done, total, errors) {
                var pct = total > 0 ? Math.round((done/total)*100) : 0;
                $('#wpio-prog-bar').css('width', pct+'%').text(pct+'%');
                $('#wpio-prog-text').text(done+' / '+total+' images processed' + (errors > 0 ? ' · '+errors+' errors' : ''));
            }

            function processChunk() {
                $.post(ajaxurl, { action: 'wpio_queue_chunk', _wpnonce: '<?php echo wp_create_nonce('wpio_chunk'); ?>' }, function(res) {
                    if (!res.success) { addLog('Error: '+res.data, 'log-error'); stopRunning(); return; }
                    var d = res.data;
                    updateProgress(d.progress.done, d.progress.total, d.progress.errors);
                    if (d.status === 'done') {
                        addLog('✔ All done! Converted: '+d.progress.done+' | Errors: '+d.progress.errors, 'log-ok');
                        stopRunning();
                    } else if (d.status === 'running') {
                        addLog('Chunk done · '+d.remaining+' remaining…', 'log-info');
                        pollTimer = setTimeout(processChunk, 800);
                    } else {
                        stopRunning();
                    }
                }).fail(function(){
                    addLog('Request failed — retrying in 3s…', 'log-error');
                    pollTimer = setTimeout(processChunk, 3000);
                });
            }

            function stopRunning() {
                running = false;
                clearTimeout(pollTimer);
                $('#wpio-bulk-start').prop('disabled', false).text('⚡ Start Bulk Convert');
                $('#wpio-bulk-cancel').remove();
            }

            $('#wpio-bulk-start').on('click', function(){
                if (running) return;
                running = true;
                $(this).prop('disabled', true).text('⏳ Building queue…');
                $('#wpio-live-progress').show();
                addLog('🔍 Scanning uploads folder…', 'log-info');
                $.post(ajaxurl, { action: 'wpio_queue_start', _wpnonce: '<?php echo wp_create_nonce('wpio_queue_start'); ?>' }, function(res) {
                    if (!res.success) { addLog('Error: '+res.data, 'log-error'); stopRunning(); return; }
                    addLog('📋 Queue built: '+res.data.total+' images to convert', 'log-info');
                    $('#wpio-bulk-start').text('⚡ Running…');
                    processChunk();
                });
            });

            $('#wpio-bulk-cancel').on('click', function(){
                $.post(ajaxurl, { action: 'wpio_queue_cancel', _wpnonce: '<?php echo wp_create_nonce('wpio_queue_cancel'); ?>' });
                addLog('✕ Cancelled by user.', 'log-error');
                stopRunning();
            });

            if (running) { processChunk(); }
        });
        </script>
        <?php
    }

    private function render_settings() {
        $opts = array(
            'wpio_format'         => get_option( 'wpio_format', 'webp' ),
            'wpio_quality'        => get_option( 'wpio_quality', 82 ),
            'wpio_auto_convert'   => get_option( 'wpio_auto_convert', '1' ),
            'wpio_backup_enabled' => get_option( 'wpio_backup_enabled', '1' ),
            'wpio_strip_exif'     => get_option( 'wpio_strip_exif', '1' ),
            'wpio_max_dimension'  => get_option( 'wpio_max_dimension', 0 ),
            'wpio_batch_size'     => get_option( 'wpio_batch_size', 5 ),
            'wpio_sleep_time'     => get_option( 'wpio_sleep_time', 500 ),
            'wpio_memory_limit'   => get_option( 'wpio_memory_limit', '256M' ),
            'wpio_exec_time'      => get_option( 'wpio_exec_time', 120 ),
        );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'wpio_settings' ); ?>

            <div class="wpio-section">
                <h2>🖼️ Conversion</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Output Format</th>
                        <td>
                            <select name="wpio_format">
                                <option value="webp" <?php selected( $opts['wpio_format'], 'webp' ); ?>>WebP — recommended, widest browser support</option>
                                <option value="avif" <?php selected( $opts['wpio_format'], 'avif' ); ?>>AVIF — ~50% smaller, needs PHP 8.1+ &amp; libavif</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Quality</th>
                        <td>
                            <input type="range" name="wpio_quality" value="<?php echo esc_attr( $opts['wpio_quality'] ); ?>" min="1" max="100" style="width:220px;vertical-align:middle;" oninput="document.getElementById('wpio_qval').textContent=this.value" />
                            <span id="wpio_qval" style="font-weight:700;margin-left:8px;"><?php echo esc_html( $opts['wpio_quality'] ); ?></span><span style="color:#888;">/100</span>
                            <p class="description">80–85 is recommended. Lower = smaller file but visible quality loss.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Strip EXIF Metadata</th>
                        <td>
                            <label><input type="checkbox" name="wpio_strip_exif" value="1" <?php checked( $opts['wpio_strip_exif'], '1' ); ?> /> Remove camera/GPS metadata — saves extra KB and improves privacy</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Max Image Dimension</th>
                        <td>
                            <input type="number" name="wpio_max_dimension" value="<?php echo esc_attr( $opts['wpio_max_dimension'] ); ?>" min="0" max="9999" style="width:100px;" /> px
                            <p class="description">Resize images larger than this before converting. Set to 0 to disable.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Auto-convert on Upload</th>
                        <td>
                            <label><input type="checkbox" name="wpio_auto_convert" value="1" <?php checked( $opts['wpio_auto_convert'], '1' ); ?> /> Automatically convert new images when uploaded</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Keep Backup of Originals</th>
                        <td>
                            <label><input type="checkbox" name="wpio_backup_enabled" value="1" <?php checked( $opts['wpio_backup_enabled'], '1' ); ?> /> Save originals in <code>/uploads/wpio-backups/</code> before converting</label>
                            <p class="description">Recommended. Enables one-click restore if anything looks wrong.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wpio-section">
                <h2>🛡️ Server Protection</h2>
                <div class="wpio-tip">These settings prevent the plugin from overloading your server during bulk conversion. Lower batch size = gentler on shared hosting.</div>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Images per Chunk</th>
                        <td>
                            <input type="number" name="wpio_batch_size" value="<?php echo esc_attr( $opts['wpio_batch_size'] ); ?>" min="1" max="50" style="width:80px;" />
                            <p class="description">Images processed per AJAX request. <strong>Shared hosting: 3–5</strong>. VPS/dedicated: 10–20.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Pause Between Chunks</th>
                        <td>
                            <input type="number" name="wpio_sleep_time" value="<?php echo esc_attr( $opts['wpio_sleep_time'] ); ?>" min="0" max="5000" style="width:80px;" /> ms
                            <p class="description">Milliseconds to sleep between each chunk. <strong>500ms</strong> is safe for shared hosting. Set 0 on VPS.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Memory Limit Override</th>
                        <td>
                            <input type="text" name="wpio_memory_limit" value="<?php echo esc_attr( $opts['wpio_memory_limit'] ); ?>" style="width:100px;" placeholder="256M" />
                            <p class="description">PHP memory limit to request during conversion (e.g. <code>256M</code>, <code>512M</code>). Server hard limit may override this.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Execution Time Override</th>
                        <td>
                            <input type="number" name="wpio_exec_time" value="<?php echo esc_attr( $opts['wpio_exec_time'] ); ?>" min="30" max="600" style="width:80px;" /> seconds
                            <p class="description">Max execution time per chunk request. <code>120</code> is recommended. Server hard limit may override.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( 'Save Settings', 'primary large' ); ?>
        </form>
        <?php
    }

    private function render_system() {
        $checks = WPIO_Environment::check();
        $icons  = array( 'ok' => '✅', 'warning' => '⚠️', 'error' => '❌', 'info' => 'ℹ️' );
        ?>
        <div class="wpio-section">
            <h2>🖥️ Server Environment</h2>
            <p style="color:#555;margin-top:0;">These checks verify your server can run the plugin correctly.</p>
            <table class="wpio-sys-table">
                <thead><tr><th>Check</th><th>Value</th><th>Status</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ( $checks as $check ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
                        <td style="font-family:monospace;"><?php echo esc_html( $check['value'] ); ?></td>
                        <td>
                            <span class="wpio-status-dot wpio-dot-<?php echo esc_attr( $check['status'] ); ?>"></span>
                            <?php echo esc_html( $icons[ $check['status'] ] ?? '' ); ?>
                            <span style="font-size:12px;color:#888;"><?php echo esc_html( ucfirst( $check['status'] ) ); ?></span>
                        </td>
                        <td style="color:#666;font-size:12px;"><?php echo esc_html( $check['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="wpio-section">
            <h2>⚡ Runtime Info</h2>
            <table class="wpio-sys-table">
                <tbody>
                    <tr><td><strong>WordPress Version</strong></td><td><?php echo esc_html( get_bloginfo('version') ); ?></td><td></td><td></td></tr>
                    <tr><td><strong>Active Format</strong></td><td><?php echo esc_html( strtoupper( get_option('wpio_format','webp') ) ); ?></td><td></td><td></td></tr>
                    <tr><td><strong>Batch Size</strong></td><td><?php echo esc_html( get_option('wpio_batch_size', 5) ); ?> images/chunk</td><td></td><td></td></tr>
                    <tr><td><strong>Sleep Between Chunks</strong></td><td><?php echo esc_html( get_option('wpio_sleep_time', 500) ); ?> ms</td><td></td><td></td></tr>
                    <tr><td><strong>Server Software</strong></td><td><?php echo esc_html( $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ); ?></td><td></td><td></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_nginx( $format ) {
        ?>
        <div class="wpio-section">
            <h2>🔧 Nginx Configuration Snippet</h2>
            <p style="color:#555;margin-top:0;">Paste this inside your <code>server {}</code> block, then reload Nginx. This replaces the <code>.htaccess</code> approach for Nginx servers.</p>
            <textarea class="large-text code" rows="18" readonly style="font-family:monospace;font-size:13px;background:#1e1e2e;color:#cdd6f4;border:none;border-radius:8px;padding:16px;resize:vertical;"><?php echo esc_textarea( WPIO_Nginx::build_rules( $format ) ); ?></textarea>
            <p style="margin-top:12px;">
                <button class="button button-primary" onclick="var t=this.previousElementSibling.previousElementSibling;t.select();document.execCommand('copy');this.textContent='✔ Copied!';setTimeout(()=>this.textContent='📋 Copy to Clipboard',2000);return false;">📋 Copy to Clipboard</button>
            </p>
            <div class="wpio-tip">
                After pasting, reload Nginx:<br><code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>
            </div>
        </div>
        <?php
    }

    /* ---- AJAX Handlers ---- */

    public function ajax_queue_start() {
        if ( ! check_ajax_referer( 'wpio_queue_start', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $total = WPIO_Queue::build();
        wp_send_json_success( array( 'total' => $total ) );
    }

    public function ajax_queue_chunk() {
        if ( ! check_ajax_referer( 'wpio_chunk', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $result = WPIO_Queue::process_chunk();
        wp_send_json_success( $result );
    }

    public function ajax_queue_cancel() {
        if ( ! check_ajax_referer( 'wpio_queue_cancel', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        WPIO_Queue::cancel();
        wp_send_json_success();
    }

    public function ajax_queue_progress() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        wp_send_json_success( WPIO_Queue::get_progress() );
    }

    public function ajax_restore_image() {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! check_ajax_referer( 'wpio_restore_' . $attachment_id, '_wpnonce', false ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $file   = get_attached_file( $attachment_id );
        $result = WPIO_Backup::restore( $file );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
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
            foreach ( $iter as $f ) { $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() ); }
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
        if ( get_option( 'wpio_backup_enabled', '1' ) === '1' ) WPIO_Backup::backup( $file );
        WPIO_Converter::convert( $file, $format, $quality );
        WPIO_Stats::bust_cache();
    }
}
