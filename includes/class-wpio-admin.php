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
        add_action( 'wp_ajax_wpio_test_remote',        array( $this, 'ajax_test_remote' ) );
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
            'wpio_format'          => 'webp',
            'wpio_quality'         => 82,
            'wpio_auto_convert'    => '1',
            'wpio_backup_enabled'  => '1',
            'wpio_strip_exif'      => '1',
            'wpio_resize_mode'     => 'max_dimension',
            'wpio_max_dimension'   => 0,
            'wpio_max_width'       => 0,
            'wpio_batch_size'      => 5,
            'wpio_sleep_time'      => 500,
            'wpio_memory_limit'    => '256M',
            'wpio_exec_time'       => 120,
            'wpio_custom_folders'  => '',
            'wpio_use_remote'      => '0',
            'wpio_remote_url'      => '',
            'wpio_remote_token'    => '',
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
                <span style="font-size:11px;background:#2271b1;color:#fff;padding:2px 9px;border-radius:20px;font-weight:500;letter-spacing:.5px;">v1.1</span>
                <?php if ( WPIO_Remote::is_enabled() ) : ?>
                <span style="font-size:11px;background:#00a32a;color:#fff;padding:2px 9px;border-radius:20px;font-weight:500;">🌐 Remote Active</span>
                <?php endif; ?>
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
        ?><style>
        .wpio-wrap{max-width:980px}
        .wpio-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin:0 0 24px}
        .wpio-card{background:#fff;border:1px solid #e2e4e7;border-radius:10px;padding:20px 16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05)}
        .wpio-card-num{font-size:34px;font-weight:700;line-height:1.1}
        .wpio-card-num.blue{color:#2271b1}.wpio-card-num.green{color:#00a32a}.wpio-card-num.orange{color:#dba617}.wpio-card-num.red{color:#d63638}
        .wpio-card-label{font-size:11px;color:#888;margin-top:6px;text-transform:uppercase;letter-spacing:.6px}
        .wpio-progress-wrap{background:#f0f0f1;border-radius:20px;height:24px;overflow:hidden;margin:12px 0 6px}
        .wpio-progress-bar{height:100%;background:linear-gradient(90deg,#2271b1 0%,#00a32a 100%);border-radius:20px;transition:width .5s ease;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;min-width:36px}
        .wpio-section{background:#fff;border:1px solid #e2e4e7;border-radius:10px;padding:24px 28px;margin-bottom:20px}
        .wpio-section h2{margin:0 0 16px;font-size:15px;font-weight:600}
        .wpio-tip{background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;border-radius:0 6px 6px 0;margin:16px 0;font-size:13px;line-height:1.6}
        .wpio-tip code{background:#dde8f7;padding:1px 6px;border-radius:3px;font-size:12px}
        .wpio-warn-tip{background:#fef9e7;border-left:4px solid #dba617;padding:12px 16px;border-radius:0 6px 6px 0;margin:16px 0;font-size:13px}
        .wpio-success-tip{background:#edfaef;border-left:4px solid #00a32a;padding:12px 16px;border-radius:0 6px 6px 0;margin:16px 0;font-size:13px}
        .wpio-sys-table{width:100%;border-collapse:collapse;font-size:13px}
        .wpio-sys-table th{background:#f9f9f9;padding:10px 14px;text-align:left;color:#444;font-weight:600;border-bottom:2px solid #e2e4e7}
        .wpio-sys-table td{padding:10px 14px;border-bottom:1px solid #f0f0f1;vertical-align:middle}
        .wpio-sys-table tr:last-child td{border-bottom:none}
        .wpio-status-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}
        .wpio-dot-ok{background:#00a32a}.wpio-dot-warning{background:#dba617}.wpio-dot-error{background:#d63638}.wpio-dot-info{background:#2271b1}
        #wpio-live-progress{display:none;margin-top:20px}
        .wpio-bulk-log{background:#1e1e2e;color:#cdd6f4;border-radius:6px;padding:14px;font-family:monospace;font-size:12px;max-height:200px;overflow-y:auto;margin-top:12px;line-height:1.6}
        .wpio-bulk-log .log-ok{color:#a6e3a1}.wpio-bulk-log .log-error{color:#f38ba8}.wpio-bulk-log .log-info{color:#89dceb}.wpio-bulk-log .log-warn{color:#f9e2af}
        .wpio-folder-list{list-style:none;margin:0;padding:0}
        .wpio-folder-list li{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f1;font-size:13px;font-family:monospace}
        .wpio-folder-list li:last-child{border-bottom:none}
        .wpio-remote-badge{display:inline-flex;align-items:center;gap:4px;font-size:12px;padding:3px 10px;border-radius:20px;font-weight:500}
        .wpio-remote-badge.on{background:#edfaef;color:#00a32a;border:1px solid #b8e6bf}
        .wpio-remote-badge.off{background:#f0f0f1;color:#666;border:1px solid #ddd}
        </style><?php
    }

    private function render_dashboard() {
        $stats = WPIO_Stats::get();
        $q     = WPIO_Queue::get_progress();
        $folders = WPIO_Folder_Scanner::get_folders();
        ?>
        <?php if ( $q['running'] ) : ?>
        <div class="notice notice-info inline" style="margin-bottom:20px;">
            <p>⏳ Bulk conversion is running in the background — you can close this page.
            <a href="<?php echo esc_url( admin_url( 'upload.php?page=wp-image-optimizer&tab=bulk' ) ); ?>">View progress &rarr;</a></p>
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
                <div class="wpio-progress-bar" style="width:<?php echo esc_attr( $stats['progress_pct'] ); ?>%;"><?php echo esc_html( $stats['progress_pct'] ); ?>%</div>
            </div>
            <p style="color:#666;font-size:13px;margin:0;"><?php echo esc_html( $stats['converted'] . ' of ' . $stats['total'] . ' images converted to ' . $stats['format'] ); ?></p>
        </div>

        <div class="wpio-section">
            <h2>📂 Scanned Folders</h2>
            <ul class="wpio-folder-list">
                <?php foreach ( $folders as $i => $folder ) : ?>
                <li>
                    <span style="color:#aaa;font-size:11px;"><?php echo $i === 0 ? '📦' : '📁'; ?></span>
                    <?php echo esc_html( $folder ); ?>
                    <?php if ( $i === 0 ) echo '<span style="font-size:10px;background:#e8f0fe;color:#2271b1;padding:1px 6px;border-radius:10px;font-family:sans-serif;">default</span>'; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin:12px 0 0;"><a href="<?php echo esc_url( admin_url( 'upload.php?page=wp-image-optimizer&tab=settings' ) ); ?>#wpio-folders" class="button button-small">➕ Add custom folder</a></p>
        </div>

        <?php if ( ! empty( $stats['largest_save']['file'] ) ) : ?>
        <div class="wpio-section">
            <h2>🏆 Biggest Win</h2>
            <p style="margin:0;"><strong><?php echo esc_html( $stats['largest_save']['file'] ); ?></strong> &mdash; saved <strong><?php echo esc_html( round( $stats['largest_save']['saved'] / 1024, 1 ) ); ?> KB</strong> (<?php echo esc_html( $stats['largest_save']['pct'] ); ?>% reduction)</p>
        </div>
        <?php endif; ?>

        <div class="wpio-section">
            <h2>💾 Backup Storage</h2>
            <?php if ( $stats['backup_bytes'] > 0 ) : ?>
                <p style="margin:0 0 12px;"><?php echo 'Backups using <strong>' . esc_html( $stats['backup_mb'] ) . ' MB</strong> of disk space.'; ?></p>
                <button class="button" id="wpio-purge-backups" data-nonce="<?php echo wp_create_nonce( 'wpio_delete_backup_all' ); ?>">🗑️ Purge All Backups</button>
                <span style="color:#888;font-size:12px;margin-left:8px;">Only after confirming site looks correct.</span>
            <?php else : ?>
                <p style="color:#888;margin:0;">No backups stored yet.</p>
            <?php endif; ?>
        </div>
        <script>jQuery(document).ready(function($){
            $('#wpio-purge-backups').on('click',function(){
                if(!confirm('Delete all backups? Cannot be undone.'))return;
                var btn=$(this);btn.prop('disabled',true).text('Purging...');
                $.post(ajaxurl,{action:'wpio_delete_backup',scope:'all',_wpnonce:btn.data('nonce')},function(res){
                    res.success?location.reload():(alert('Error: '+res.data),btn.prop('disabled',false).text('🗑️ Purge All Backups'));
                });
            });
        });</script>
        <?php
    }

    private function render_bulk( $format ) {
        $q = WPIO_Queue::get_progress();
        $p = $q['progress'];
        $pct = $p['total'] > 0 ? round( ( $p['done'] / $p['total'] ) * 100 ) : 0;
        ?>
        <div class="wpio-section">
            <h2>⚡ Bulk Convert</h2>
            <p style="color:#555;margin-top:0;">Images process in small chunks. <strong>You can close this page</strong> &mdash; a background task (WP-Cron) keeps converting automatically.</p>

            <?php if ( WPIO_Nginx::is_nginx() ) : ?>
            <div class="wpio-warn-tip">⚠️ Nginx detected: .htaccess rules won't work. See the Nginx Config tab.</div>
            <?php endif; ?>

            <?php if ( WPIO_Remote::is_enabled() ) : ?>
            <div class="wpio-success-tip">🌐 Remote server conversion is active. Your server won't be loaded during conversion.</div>
            <?php endif; ?>

            <div class="wpio-tip">💡 Large library? Use WP-CLI for maximum speed:<br><code>wp image-optimizer bulk --format=<?php echo esc_html( $format ); ?> --quality=<?php echo esc_html( get_option('wpio_quality',82) ); ?></code></div>

            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:4px;">
                <button id="wpio-bulk-start" class="button button-primary button-large" <?php echo $q['running'] ? 'disabled' : ''; ?>>
                    ⚡ <?php echo $q['running'] ? 'Running&hellip;' : 'Start Bulk Convert'; ?>
                </button>
                <button id="wpio-bulk-cancel" class="button button-large" style="color:#d63638;border-color:#d63638;<?php echo $q['running'] ? '' : 'display:none;'; ?>">✕ Cancel</button>
            </div>

            <div id="wpio-live-progress" <?php echo $q['running'] ? 'style="display:block;"' : ''; ?>>
                <div class="wpio-progress-wrap" style="margin-top:16px;">
                    <div class="wpio-progress-bar" id="wpio-prog-bar" style="width:<?php echo esc_attr($pct); ?>%;"><?php echo esc_html($pct); ?>%</div>
                </div>
                <p id="wpio-prog-text" style="color:#555;font-size:13px;"><?php echo esc_html( $p['done'] . ' / ' . $p['total'] . ' images processed' ); ?></p>
                <div class="wpio-bulk-log" id="wpio-bulk-log"></div>
            </div>
        </div>

        <script>jQuery(document).ready(function($){
            var running=<?php echo $q['running']?'true':'false';?>;
            var pollTimer=null;
            function addLog(msg,type){var cls=type||'log-info';var log=$('#wpio-bulk-log');log.append('<p class="'+cls+'">'+msg+'</p>');log.scrollTop(log[0].scrollHeight);}
            function updateProgress(done,total,errors){
                var pct=total>0?Math.round((done/total)*100):0;
                $('#wpio-prog-bar').css('width',pct+'%').text(pct+'%');
                $('#wpio-prog-text').text(done+' / '+total+' images processed'+(errors>0?' &middot; '+errors+' errors':''));
            }
            function processChunk(){
                $.post(ajaxurl,{action:'wpio_queue_chunk',_wpnonce:'<?php echo wp_create_nonce('wpio_chunk');?>'},function(res){
                    if(!res.success){addLog('Error: '+res.data,'log-error');stopRunning();return;}
                    var d=res.data;
                    updateProgress(d.progress.done,d.progress.total,d.progress.errors);
                    if(d.status==='done'){addLog('✔ All done! Converted: '+d.progress.done+' | Errors: '+d.progress.errors,'log-ok');stopRunning();}
                    else if(d.status==='running'){addLog('🟢 Chunk done &middot; '+d.remaining+' remaining&hellip;','log-info');pollTimer=setTimeout(processChunk,600);}
                    else stopRunning();
                }).fail(function(){addLog('⚠️ Request failed &mdash; background cron will continue. Retrying in 5s&hellip;','log-warn');pollTimer=setTimeout(processChunk,5000);});
            }
            function stopRunning(){
                running=false;clearTimeout(pollTimer);
                $('#wpio-bulk-start').prop('disabled',false).text('⚡ Start Bulk Convert');
                $('#wpio-bulk-cancel').hide();
            }
            $('#wpio-bulk-start').on('click',function(){
                if(running)return;running=true;
                $(this).prop('disabled',true).text('⏳ Building queue&hellip;');
                $('#wpio-bulk-cancel').show();
                $('#wpio-live-progress').show();
                addLog('🔍 Scanning all configured folders&hellip;','log-info');
                $.post(ajaxurl,{action:'wpio_queue_start',_wpnonce:'<?php echo wp_create_nonce('wpio_queue_start');?>'},function(res){
                    if(!res.success){addLog('Error: '+res.data,'log-error');stopRunning();return;}
                    addLog('📋 Queue built: '+res.data.total+' images queued','log-info');
                    if(res.data.total===0){addLog('✔ Nothing to convert — all images already optimized!','log-ok');stopRunning();return;}
                    $('#wpio-bulk-start').text('⚡ Running&hellip;');
                    processChunk();
                });
            });
            $('#wpio-bulk-cancel').on('click',function(){
                $.post(ajaxurl,{action:'wpio_queue_cancel',_wpnonce:'<?php echo wp_create_nonce('wpio_queue_cancel');?>'});
                addLog('✕ Cancelled. Background cron also stopped.','log-error');
                stopRunning();
            });
            if(running){addLog('⏳ Resuming from background&hellip;','log-warn');processChunk();}
        });</script>
        <?php
    }

    private function render_settings() {
        $o = array(
            'format'         => get_option('wpio_format','webp'),
            'quality'        => get_option('wpio_quality',82),
            'auto_convert'   => get_option('wpio_auto_convert','1'),
            'backup_enabled' => get_option('wpio_backup_enabled','1'),
            'strip_exif'     => get_option('wpio_strip_exif','1'),
            'resize_mode'    => get_option('wpio_resize_mode','max_dimension'),
            'max_dimension'  => get_option('wpio_max_dimension',0),
            'max_width'      => get_option('wpio_max_width',0),
            'batch_size'     => get_option('wpio_batch_size',5),
            'sleep_time'     => get_option('wpio_sleep_time',500),
            'memory_limit'   => get_option('wpio_memory_limit','256M'),
            'exec_time'      => get_option('wpio_exec_time',120),
            'custom_folders' => get_option('wpio_custom_folders',''),
            'use_remote'     => get_option('wpio_use_remote','0'),
            'remote_url'     => get_option('wpio_remote_url',''),
            'remote_token'   => get_option('wpio_remote_token',''),
        );
        ?>
        <form method="post" action="options.php">
        <?php settings_fields('wpio_settings'); ?>

        <div class="wpio-section">
            <h2>🖼️ Conversion</h2>
            <table class="form-table" role="presentation">
                <tr><th>Output Format</th><td>
                    <select name="wpio_format">
                        <option value="webp" <?php selected($o['format'],'webp');?>>WebP &mdash; recommended, widest browser support</option>
                        <option value="avif" <?php selected($o['format'],'avif');?>>AVIF &mdash; ~50% smaller, needs PHP 8.1+ &amp; libavif</option>
                    </select>
                </td></tr>
                <tr><th>Quality</th><td>
                    <input type="range" name="wpio_quality" value="<?php echo esc_attr($o['quality']);?>" min="1" max="100" style="width:220px;vertical-align:middle;" oninput="document.getElementById('wpio_qval').textContent=this.value" />
                    <span id="wpio_qval" style="font-weight:700;margin-left:8px;"><?php echo esc_html($o['quality']);?></span><span style="color:#888;">/100</span>
                    <p class="description">80&ndash;85 recommended. Lower = smaller file, possible quality loss.</p>
                </td></tr>
                <tr><th>Strip EXIF Metadata</th><td>
                    <label><input type="checkbox" name="wpio_strip_exif" value="1" <?php checked($o['strip_exif'],'1');?> /> Remove camera/GPS metadata (saves extra KB + privacy)</label>
                </td></tr>
                <tr><th>Auto-convert on Upload</th><td>
                    <label><input type="checkbox" name="wpio_auto_convert" value="1" <?php checked($o['auto_convert'],'1');?> /> Automatically convert new images when uploaded</label>
                </td></tr>
                <tr><th>Keep Backup of Originals</th><td>
                    <label><input type="checkbox" name="wpio_backup_enabled" value="1" <?php checked($o['backup_enabled'],'1');?> /> Save original in <code>/uploads/wpio-backups/</code> before converting</label>
                    <p class="description">Enables one-click restore if anything looks wrong.</p>
                </td></tr>
            </table>
        </div>

        <div class="wpio-section">
            <h2>📀 Image Resizing</h2>
            <div class="wpio-tip">💡 Resize large images before converting to save even more space. Original is backed up if backup is enabled.</div>
            <table class="form-table" role="presentation">
                <tr><th>Resize Mode</th><td>
                    <select name="wpio_resize_mode" id="wpio_resize_mode" onchange="document.getElementById('wpio_row_maxdim').style.display=this.value==='max_dimension'?'':'none';document.getElementById('wpio_row_maxw').style.display=this.value==='max_width'?'':'none';">
                        <option value="none" <?php selected($o['resize_mode'],'none');?>>Disabled &mdash; keep original dimensions</option>
                        <option value="max_dimension" <?php selected($o['resize_mode'],'max_dimension');?>>Limit max dimension (longest side)</option>
                        <option value="max_width" <?php selected($o['resize_mode'],'max_width');?>>Limit max width only</option>
                    </select>
                </td></tr>
                <tr id="wpio_row_maxdim" <?php echo $o['resize_mode']!=='max_dimension'?'style="display:none;"':'';?>>
                    <th>Max Dimension</th><td>
                        <input type="number" name="wpio_max_dimension" value="<?php echo esc_attr($o['max_dimension']);?>" min="0" max="9999" style="width:100px;" /> px
                        <p class="description">Longest side limit. E.g. 2000 means no side exceeds 2000px.</p>
                    </td></tr>
                <tr id="wpio_row_maxw" <?php echo $o['resize_mode']!=='max_width'?'style="display:none;"':'';?>>
                    <th>Max Width</th><td>
                        <input type="number" name="wpio_max_width" value="<?php echo esc_attr($o['max_width']);?>" min="0" max="9999" style="width:100px;" /> px
                        <p class="description">Width limit, height scales proportionally.</p>
                    </td></tr>
            </table>
        </div>

        <div class="wpio-section" id="wpio-folders">
            <h2>📁 Custom Folders</h2>
            <p style="color:#555;margin-top:0;">By default, only <code>/wp-content/uploads/</code> is scanned. Add extra directories below (one per line). Paths relative to WordPress root or absolute.</p>
            <textarea name="wpio_custom_folders" rows="5" class="large-text code" placeholder="/var/www/html/wp-content/themes/my-theme/images
wp-content/plugins/my-plugin/assets"><?php echo esc_textarea($o['custom_folders']);?></textarea>
            <p class="description">Custom folders are included in bulk conversion, auto-upload convert, and stats. Make sure PHP has read/write access to them.</p>
        </div>

        <div class="wpio-section">
            <h2>🌐 Remote Server Conversion</h2>
            <p style="color:#555;margin-top:0;">Convert images on a remote server instead of locally. Zero load on your server. Falls back to local if remote fails.</p>
            <table class="form-table" role="presentation">
                <tr><th>Enable Remote Conversion</th><td>
                    <label><input type="checkbox" name="wpio_use_remote" value="1" <?php checked($o['use_remote'],'1');?> id="wpio_use_remote" /> Use remote server for conversion</label>
                </td></tr>
                <tr><th>Remote Server URL</th><td>
                    <input type="url" name="wpio_remote_url" value="<?php echo esc_attr($o['remote_url']);?>" class="regular-text" placeholder="https://your-conversion-server.com" />
                    <p class="description">URL of your self-hosted or third-party conversion endpoint. Must accept <code>POST /convert</code> and <code>GET /ping</code>.</p>
                </td></tr>
                <tr><th>API Token</th><td>
                    <input type="text" name="wpio_remote_token" value="<?php echo esc_attr($o['remote_token']);?>" class="regular-text" placeholder="Bearer token for authentication" />
                </td></tr>
                <tr><th>Test Connection</th><td>
                    <button type="button" class="button" id="wpio-test-remote">🔌 Test Connection</button>
                    <span id="wpio-remote-test-result" style="margin-left:10px;font-size:13px;"></span>
                </td></tr>
            </table>
            <div class="wpio-tip">💡 <strong>Self-host your own conversion server</strong> &mdash; a Node.js/Sharp-based server spec is included in <code>/remote-server/README.md</code> in this repo. You can deploy it on any VPS, Railway, Render, or Fly.io for free.</div>
        </div>

        <div class="wpio-section">
            <h2>🛡️ Server Protection</h2>
            <div class="wpio-tip">Lower batch size = gentler on shared hosting. The WP-Cron background task processes chunks every ~30s even when no one is on the admin page.</div>
            <table class="form-table" role="presentation">
                <tr><th>Images per Chunk</th><td>
                    <input type="number" name="wpio_batch_size" value="<?php echo esc_attr($o['batch_size']);?>" min="1" max="50" style="width:80px;" />
                    <p class="description">Shared hosting: <strong>3&ndash;5</strong>. VPS/dedicated: <strong>10&ndash;20</strong>. Remote server: up to <strong>50</strong> (no local load).</p>
                </td></tr>
                <tr><th>Pause Between Chunks</th><td>
                    <input type="number" name="wpio_sleep_time" value="<?php echo esc_attr($o['sleep_time']);?>" min="0" max="5000" style="width:80px;" /> ms
                    <p class="description">500ms safe for shared hosting. 0 on VPS or when using remote server.</p>
                </td></tr>
                <tr><th>Memory Limit Override</th><td>
                    <input type="text" name="wpio_memory_limit" value="<?php echo esc_attr($o['memory_limit']);?>" style="width:100px;" placeholder="256M" />
                    <p class="description">e.g. <code>256M</code>, <code>512M</code>. Server hard limit may override.</p>
                </td></tr>
                <tr><th>Execution Time Override</th><td>
                    <input type="number" name="wpio_exec_time" value="<?php echo esc_attr($o['exec_time']);?>" min="30" max="600" style="width:80px;" /> seconds
                    <p class="description">Per-chunk timeout. <code>120s</code> recommended.</p>
                </td></tr>
            </table>
        </div>

        <?php submit_button('Save Settings','primary large'); ?>
        </form>
        <script>jQuery(document).ready(function($){
            $('#wpio-test-remote').on('click',function(){
                var btn=$(this),r=$('#wpio-remote-test-result');
                btn.prop('disabled',true).text('Testing...');
                r.text('').css('color','#888');
                $.post(ajaxurl,{action:'wpio_test_remote',_wpnonce:'<?php echo wp_create_nonce('wpio_test_remote');?>'},function(res){
                    btn.prop('disabled',false).text('🔌 Test Connection');
                    if(res.success){r.text('✅ Connected successfully!').css('color','#00a32a');}
                    else{r.text('❌ '+res.data).css('color','#d63638');}
                });
            });
        });</script>
        <?php
    }

    private function render_system() {
        $checks = WPIO_Environment::check();
        $icons  = array('ok'=>'✅','warning'=>'⚠️','error'=>'❌','info'=>'ℹ️');
        ?>
        <div class="wpio-section">
            <h2>🖥️ Server Environment</h2>
            <table class="wpio-sys-table">
                <thead><tr><th>Check</th><th>Value</th><th>Status</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach($checks as $check):?>
                <tr>
                    <td><strong><?php echo esc_html($check['label']);?></strong></td>
                    <td style="font-family:monospace;"><?php echo esc_html($check['value']);?></td>
                    <td><span class="wpio-status-dot wpio-dot-<?php echo esc_attr($check['status']);?>"></span><?php echo esc_html($icons[$check['status']]??'');?></td>
                    <td style="color:#666;font-size:12px;"><?php echo esc_html($check['message']);?></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <div class="wpio-section">
            <h2>⚡ Runtime Info</h2>
            <table class="wpio-sys-table"><tbody>
                <tr><td><strong>WordPress Version</strong></td><td><?php echo esc_html(get_bloginfo('version'));?></td><td></td><td></td></tr>
                <tr><td><strong>Active Format</strong></td><td><?php echo esc_html(strtoupper(get_option('wpio_format','webp')));?></td><td></td><td></td></tr>
                <tr><td><strong>Remote Conversion</strong></td><td><?php echo WPIO_Remote::is_enabled()?'<span style="color:#00a32a;font-weight:600;">Enabled</span>':'<span style="color:#888;">Disabled</span>';?></td><td></td><td></td></tr>
                <tr><td><strong>Scanned Folders</strong></td><td><?php echo esc_html(count(WPIO_Folder_Scanner::get_folders()));?></td><td></td><td></td></tr>
                <tr><td><strong>Batch Size</strong></td><td><?php echo esc_html(get_option('wpio_batch_size',5));?> images/chunk</td><td></td><td></td></tr>
                <tr><td><strong>Background Cron</strong></td><td><?php $ts=wp_next_scheduled(WPIO_Queue::CRON_HOOK);echo $ts?'<span style="color:#00a32a;">Scheduled (next: '.human_time_diff($ts).')</span>':'<span style="color:#888;">Not scheduled</span>';?></td><td></td><td></td></tr>
                <tr><td><strong>Server Software</strong></td><td><?php echo esc_html($_SERVER['SERVER_SOFTWARE']??'Unknown');?></td><td></td><td></td></tr>
            </tbody></table>
        </div>
        <?php
    }

    private function render_nginx( $format ) {
        ?>
        <div class="wpio-section">
            <h2>🔧 Nginx Configuration</h2>
            <p style="color:#555;margin-top:0;">Paste inside your <code>server {}</code> block, then reload Nginx.</p>
            <textarea class="large-text code" rows="18" readonly style="font-family:monospace;font-size:13px;background:#1e1e2e;color:#cdd6f4;border:none;border-radius:8px;padding:16px;resize:vertical;"><?php echo esc_textarea(WPIO_Nginx::build_rules($format));?></textarea>
            <p style="margin-top:12px;"><button class="button button-primary" onclick="var t=this.previousElementSibling.previousElementSibling;t.select();document.execCommand('copy');this.textContent='✔ Copied!';setTimeout(()=>this.textContent='📋 Copy to Clipboard',2000);return false;">📋 Copy to Clipboard</button></p>
            <div class="wpio-tip">After pasting:<br><code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code></div>
        </div>
        <?php
    }

    /* ---- AJAX ---- */
    public function ajax_queue_start() {
        if(!check_ajax_referer('wpio_queue_start','_wpnonce',false)||!current_user_can('manage_options'))wp_send_json_error('Unauthorized');
        $total=WPIO_Queue::build();
        wp_send_json_success(array('total'=>$total));
    }
    public function ajax_queue_chunk() {
        if(!check_ajax_referer('wpio_chunk','_wpnonce',false)||!current_user_can('manage_options'))wp_send_json_error('Unauthorized');
        wp_send_json_success(WPIO_Queue::process_chunk());
    }
    public function ajax_queue_cancel() {
        if(!check_ajax_referer('wpio_queue_cancel','_wpnonce',false)||!current_user_can('manage_options'))wp_send_json_error('Unauthorized');
        WPIO_Queue::cancel();wp_send_json_success();
    }
    public function ajax_queue_progress() {
        if(!current_user_can('manage_options'))wp_send_json_error('Unauthorized');
        wp_send_json_success(WPIO_Queue::get_progress());
    }
    public function ajax_test_remote() {
        if(!check_ajax_referer('wpio_test_remote','_wpnonce',false)||!current_user_can('manage_options'))wp_send_json_error('Unauthorized');
        $result=WPIO_Remote::test_connection();
        is_wp_error($result)?wp_send_json_error($result->get_error_message()):wp_send_json_success();
    }
    public function ajax_restore_image() {
        $id=isset($_POST['attachment_id'])?absint($_POST['attachment_id']):0;
        if(!check_ajax_referer('wpio_restore_'.$id,'_wpnonce',false)||!current_user_can('upload_files'))wp_send_json_error('Unauthorized');
        $result=WPIO_Backup::restore(get_attached_file($id));
        is_wp_error($result)?wp_send_json_error($result->get_error_message()):wp_send_json_success();
        WPIO_Stats::bust_cache();
    }
    public function ajax_delete_backup() {
        if(!check_ajax_referer('wpio_delete_backup_all','_wpnonce',false)||!current_user_can('manage_options'))wp_send_json_error('Unauthorized');
        $dir=WPIO_Backup::backup_dir();
        if(is_dir($dir)){
            $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($iter as $f){$f->isDir()?rmdir($f->getRealPath()):unlink($f->getRealPath());}
            rmdir($dir);
        }
        WPIO_Stats::bust_cache();wp_send_json_success();
    }
    public function on_upload($attachment_id) {
        if(get_option('wpio_auto_convert','1')!=='1')return;
        $file=get_attached_file($attachment_id);
        if(!$file)return;
        $format=get_option('wpio_format','webp');
        $quality=(int)get_option('wpio_quality',82);
        if(get_option('wpio_backup_enabled','1')==='1')WPIO_Backup::backup($file);
        WPIO_Converter::convert($file,$format,$quality);
        WPIO_Stats::bust_cache();
    }
}
