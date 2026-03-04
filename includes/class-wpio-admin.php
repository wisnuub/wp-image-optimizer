<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIO_Admin {

    const ACCENT = '#FF2462';

    public function __construct() {
        add_action( 'admin_menu',                  array( $this, 'add_menu' ) );
        add_action( 'admin_init',                  array( $this, 'register_settings' ) );
        add_action( 'admin_notices',               array( 'WPIO_Environment', 'admin_notice' ) );
        add_action( 'admin_enqueue_scripts',       array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wpio_queue_start',    array( $this, 'ajax_queue_start' ) );
        add_action( 'wp_ajax_wpio_queue_chunk',    array( $this, 'ajax_queue_chunk' ) );
        add_action( 'wp_ajax_wpio_queue_cancel',   array( $this, 'ajax_queue_cancel' ) );
        add_action( 'wp_ajax_wpio_queue_progress', array( $this, 'ajax_queue_progress' ) );
        add_action( 'wp_ajax_wpio_restore_image',  array( $this, 'ajax_restore_image' ) );
        add_action( 'wp_ajax_wpio_delete_backup',  array( $this, 'ajax_delete_backup' ) );
        add_action( 'add_attachment',              array( $this, 'on_upload' ) );
        new WPIO_Media_Column();
    }

    /* -- Assets --------------------------------------- */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wp-image-optimizer' ) === false ) return;
        wp_enqueue_style( 'wpio-admin', WPIO_URL . 'assets/css/admin.css', array(), WPIO_VERSION );
        wp_enqueue_script( 'wpio-admin', WPIO_URL . 'assets/js/admin.js', array( 'jquery' ), WPIO_VERSION, true );
        $q = WPIO_Queue::get_progress();
        wp_localize_script( 'wpio-admin', 'wpioData', array(
            'queueRunning' => $q['running'] ? 'true' : 'false',
            'nonceStart'   => wp_create_nonce( 'wpio_queue_start' ),
            'nonceChunk'   => wp_create_nonce( 'wpio_chunk' ),
            'nonceCancel'  => wp_create_nonce( 'wpio_queue_cancel' ),
        ) );
    }

    /* -- Menu ----------------------------------------- */
    public function add_menu() {
        add_media_page(
            __( 'Image Optimizer', 'wp-image-optimizer' ),
            __( 'Image Optimizer', 'wp-image-optimizer' ),
            'manage_options',
            'wp-image-optimizer',
            array( $this, 'render_page' )
        );
    }

    /* -- Settings ------------------------------------- */
    public function register_settings() {
        $defaults = array(
            'wpio_format'             => 'webp',
            'wpio_quality'            => 82,
            'wpio_ext_jpg'            => '1',
            'wpio_ext_png'            => '1',
            'wpio_ext_gif'            => '0',
            'wpio_excluded_dirs'      => '',
            'wpio_conversion_method'  => 'auto',
            'wpio_auto_convert'       => '1',
            'wpio_backup_enabled'     => '1',
            'wpio_strip_exif'         => '1',
            'wpio_remove_if_larger'   => '1',
            'wpio_resize_mode'        => 'none',
            'wpio_max_dimension'      => 0,
            'wpio_max_width'          => 0,
            'wpio_batch_size'         => 5,
            'wpio_sleep_time'         => 500,
            'wpio_memory_limit'       => '256M',
            'wpio_exec_time'          => 120,
            'wpio_custom_folders'     => '',
            'wpio_use_remote'         => '0',
            'wpio_remote_url'         => '',
            'wpio_remote_token'       => '',
        );
        foreach ( $defaults as $key => $default ) {
            register_setting( 'wpio_settings', $key, array( 'default' => $default ) );
        }
    }

    /* -- Tabs ----------------------------------------- */
    private function get_tabs() {
        return array(
            'general'  => array( 'icon' => '⚙️',  'label' => 'General Settings' ),
            'advanced' => array( 'icon' => '🔧',  'label' => 'Advanced Settings' ),
            'delivery' => array( 'icon' => '🔀',  'label' => 'Delivery / Rewrite' ),
            'system'   => array( 'icon' => '🖥️',  'label' => 'System Status' ),
            'help'     => array( 'icon' => '❓',  'label' => 'Help' ),
        );
    }

    /* -- Page shell ----------------------------------- */
    public function render_page() {
        $tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $base_url = admin_url( 'upload.php?page=wp-image-optimizer' );
        $banner   = apply_filters( 'wpio_banner_image_url', '' );
        ?>
        <div class="wrap wpio-wrap">
            <div class="wpio-banner">
                <?php if ( $banner ) : ?>
                    <img src="<?php echo esc_url( $banner ); ?>" alt="WP Image Optimizer" class="wpio-banner-img" />
                <?php else : ?>
                <div class="wpio-banner-placeholder">
                    <span class="wpio-banner-icon">🖼️</span>
                    <div>
                        <div class="wpio-banner-title">WP Image Optimizer</div>
                        <div class="wpio-banner-sub">Convert &amp; serve next-gen images — WebP &amp; AVIF — automatically</div>
                    </div>
                    <span class="wpio-version-badge">v<?php echo esc_html( WPIO_VERSION ); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="wpio-tabs-wrap">
                <ul class="wpio-nav-tabs">
                    <?php foreach ( $this->get_tabs() as $key => $t ) : ?>
                    <li>
                        <a href="<?php echo esc_url( $base_url . '&tab=' . $key ); ?>"
                           class="<?php echo $tab === $key ? 'active' : ''; ?>">
                            <span class="wpio-tab-icon"><?php echo esc_html( $t['icon'] ); ?></span>
                            <?php echo esc_html( $t['label'] ); ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
            switch ( $tab ) {
                case 'general':  $this->tab_general();  break;
                case 'advanced': $this->tab_advanced(); break;
                case 'delivery': $this->tab_delivery(); break;
                case 'system':   $this->tab_system();   break;
                case 'help':     $this->tab_help();     break;
            }
            ?>
        </div>
        <?php
    }

    /* ================================================
       TAB: GENERAL
    ================================================ */
    private function tab_general() {
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = (int) get_option( 'wpio_quality', 82 );
        $auto    = get_option( 'wpio_auto_convert', '1' );
        $backup  = get_option( 'wpio_backup_enabled', '1' );
        ?>
        <div class="wpio-layout">
        <div class="wpio-main">
        <form method="post" action="options.php">
        <?php settings_fields( 'wpio_settings' ); ?>
        <input type="hidden" name="wpio_format" id="wpio_format_hidden" value="<?php echo esc_attr( $format ); ?>" />
        <input type="hidden" name="wpio_quality" id="wpio_quality_hidden" value="<?php echo esc_attr( $quality ); ?>" />

        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>🖼️ Next-gen image formats</h2>
                <p>Select the format you'd like your images converted to.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-format-grid">
                    <div class="wpio-format-card <?php echo $format === 'webp' ? 'selected' : ''; ?>" data-format="webp">
                        <input type="radio" name="_wpio_format_ui" value="webp" <?php checked( $format, 'webp' ); ?> />
                        <div class="wpio-format-dot"></div>
                        <div class="wpio-format-name">WebP</div>
                        <table class="wpio-format-table">
                            <tr><th>File size</th></tr><tr><td>~25–35% smaller than JPEG/PNG</td></tr>
                            <tr><th>Quality</th></tr><tr><td>Excellent — visually lossless at 80+</td></tr>
                            <tr><th>Hosting load</th></tr><tr><td>Local conversion</td></tr>
                        </table>
                    </div>
                    <div class="wpio-format-card <?php echo $format === 'avif' ? 'selected' : ''; ?>" data-format="avif">
                        <input type="radio" name="_wpio_format_ui" value="avif" <?php checked( $format, 'avif' ); ?> />
                        <div class="wpio-format-dot"></div>
                        <div class="wpio-format-name">AVIF <span class="wpio-experimental-badge">Experimental</span></div>
                        <table class="wpio-format-table">
                            <tr><th>File size</th></tr><tr><td>~50% smaller than JPEG</td></tr>
                            <tr><th>Quality</th></tr><tr><td>Highest quality (AV1 codec)</td></tr>
                            <tr><th>Requirement</th></tr><tr><td>PHP 8.1+ &amp; libavif</td></tr>
                        </table>
                    </div>
                    <div class="wpio-format-card <?php echo $format === 'both' ? 'selected' : ''; ?>" data-format="both">
                        <input type="radio" name="_wpio_format_ui" value="both" <?php checked( $format, 'both' ); ?> />
                        <div class="wpio-format-dot"></div>
                        <div class="wpio-format-name">AVIF + WebP <span class="wpio-experimental-badge">Experimental</span></div>
                        <table class="wpio-format-table">
                            <tr><th>File size</th></tr><tr><td>Best of both formats</td></tr>
                            <tr><th>Quality</th></tr><tr><td>Browser picks best</td></tr>
                            <tr><th>Requirement</th></tr><tr><td>PHP 8.1+ &amp; libavif</td></tr>
                        </table>
                    </div>
                </div>
                <?php if ( $format === 'avif' || $format === 'both' ) : ?>
                <div class="wpio-alert warn">
                    <span class="wpio-alert-icon">⚠️</span>
                    <span>AVIF requires PHP 8.1+ and libavif. Check <a href="<?php echo esc_url( admin_url('upload.php?page=wp-image-optimizer&tab=system') ); ?>">System Status</a> before using.</span>
                </div>
                <?php endif; ?>
                <div class="wpio-field-row" style="margin-top:8px;">
                    <div class="wpio-field-label">Conversion quality<small>80–85 recommended</small></div>
                    <div class="wpio-field-input">
                        <input type="range" class="wpio-quality-slider" min="1" max="100" value="<?php echo esc_attr($quality); ?>" style="--val:<?php echo esc_attr($quality);?>%" />
                        <span class="wpio-quality-val"><?php echo esc_attr($quality); ?></span>
                        <div class="desc">Lower = smaller file, possible quality loss.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>🌐 Browser support &amp; fallback</h2>
                <p>The plugin automatically serves the best format each browser supports — without changing image URLs.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-browser-flow">
                    <div class="wpio-bf-step">
                        <span class="wpio-bf-badge avif">⬡ AVIF <span class="wpio-experimental-badge" style="margin-left:4px;">optional</span></span>
                        <div class="wpio-bf-label">Browser supports AVIF</div>
                        <div class="wpio-bf-sublabel">Chrome 85+, Firefox 93+, Safari 16+</div>
                    </div>
                    <div class="wpio-bf-arrow">↓</div>
                    <div class="wpio-bf-step">
                        <span class="wpio-bf-badge webp">◈ WebP</span>
                        <div class="wpio-bf-label">If AVIF unsupported</div>
                        <div class="wpio-bf-sublabel">All modern browsers</div>
                    </div>
                    <div class="wpio-bf-arrow">↓</div>
                    <div class="wpio-bf-step">
                        <span class="wpio-bf-badge orig">📄 Original</span>
                        <div class="wpio-bf-label">If neither supported</div>
                        <div class="wpio-bf-sublabel">JPEG / PNG fallback</div>
                    </div>
                </div>
                <div class="wpio-alert info" style="margin-top:16px;">
                    <span class="wpio-alert-icon">ℹ️</span>
                    <span>Image URLs never change. The server (via .htaccess or Nginx) decides which file to serve based on the browser's <code>Accept</code> header.</span>
                </div>
            </div>
        </div>

        <div class="wpio-card">
            <div class="wpio-card-head"><div><h2>⚡ Conversion settings</h2></div></div>
            <div class="wpio-card-body">
                <div class="wpio-toggle-row">
                    <div class="label">Auto-convert on upload
                        <small>Automatically convert new images when added to Media Library</small>
                    </div>
                    <label class="wpio-toggle">
                        <input type="checkbox" name="wpio_auto_convert" value="1" <?php checked($auto,'1'); ?> />
                        <span class="wpio-toggle-slider"></span>
                    </label>
                </div>
                <div class="wpio-toggle-row">
                    <div class="label">Keep backup of originals
                        <small>Saves original in <code>/uploads/wpio-backups/</code> — enables one-click restore</small>
                    </div>
                    <label class="wpio-toggle">
                        <input type="checkbox" name="wpio_backup_enabled" value="1" <?php checked($backup,'1'); ?> />
                        <span class="wpio-toggle-slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <?php $this->render_bulk_card(); ?>
        <?php submit_button( 'Save Settings', '', 'submit', true, array( 'class' => 'wpio-btn wpio-btn-primary wpio-btn-lg', 'style' => 'margin-top:4px;' ) ); ?>
        </form>
        </div>
        <?php $this->render_sidebar(); ?>
        </div>
        <?php
    }

    /* ================================================
       TAB: ADVANCED
    ================================================ */
    private function tab_advanced() {
        $o = array(
            'ext_jpg'           => get_option( 'wpio_ext_jpg', '1' ),
            'ext_png'           => get_option( 'wpio_ext_png', '1' ),
            'ext_gif'           => get_option( 'wpio_ext_gif', '0' ),
            'excluded_dirs'     => get_option( 'wpio_excluded_dirs', '' ),
            'conversion_method' => get_option( 'wpio_conversion_method', 'auto' ),
            'strip_exif'        => get_option( 'wpio_strip_exif', '1' ),
            'remove_larger'     => get_option( 'wpio_remove_if_larger', '1' ),
            'resize_mode'       => get_option( 'wpio_resize_mode', 'none' ),
            'max_dimension'     => get_option( 'wpio_max_dimension', 0 ),
            'max_width'         => get_option( 'wpio_max_width', 0 ),
            'batch_size'        => get_option( 'wpio_batch_size', 5 ),
            'sleep_time'        => get_option( 'wpio_sleep_time', 500 ),
            'memory_limit'      => get_option( 'wpio_memory_limit', '256M' ),
            'exec_time'         => get_option( 'wpio_exec_time', 120 ),
            'custom_folders'    => get_option( 'wpio_custom_folders', '' ),
        );

        $has_imagick = extension_loaded( 'imagick' );
        $has_gd      = extension_loaded( 'gd' );
        $excluded_preview = WPIO_Folder_Scanner::get_excluded_dirs();
        ?>
        <div class="wpio-layout full">
        <form method="post" action="options.php">
        <?php settings_fields( 'wpio_settings' ); ?>

        <!-- T1: Supported file extensions -->
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>📋 Supported file extensions</h2>
                <p>Files with these extensions will be converted during bulk and auto-convert.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-toggle-row">
                    <div class="label">.jpg / .jpeg
                        <small>Standard photo format — highest recommended</small>
                    </div>
                    <label class="wpio-toggle">
                        <input type="checkbox" name="wpio_ext_jpg" value="1" <?php checked( $o['ext_jpg'], '1' ); ?> />
                        <span class="wpio-toggle-slider"></span>
                    </label>
                </div>
                <div class="wpio-toggle-row">
                    <div class="label">.png
                        <small>Lossless images — great file size savings with WebP</small>
                    </div>
                    <label class="wpio-toggle">
                        <input type="checkbox" name="wpio_ext_png" value="1" <?php checked( $o['ext_png'], '1' ); ?> />
                        <span class="wpio-toggle-slider"></span>
                    </label>
                </div>
                <div class="wpio-toggle-row">
                    <div class="label">.gif
                        <small>Static GIFs only — animated GIFs will be skipped automatically</small>
                    </div>
                    <label class="wpio-toggle">
                        <input type="checkbox" name="wpio_ext_gif" value="1" <?php checked( $o['ext_gif'], '1' ); ?> />
                        <span class="wpio-toggle-slider"></span>
                    </label>
                </div>
                <div class="wpio-alert info" style="margin-top:4px;">
                    <span class="wpio-alert-icon">ℹ️</span>
                    <span>If all are disabled, JPG and PNG will be used as a safe fallback.</span>
                </div>
            </div>
        </div>

        <!-- T2: Excluded directories -->
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>🚫 Excluded directories</h2>
                <p>Directory name fragments separated by commas. Any path containing these strings will be skipped during scanning.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Excluded paths
                        <small>Comma-separated name fragments</small>
                    </div>
                    <div class="wpio-field-input">
                        <input type="text"
                               name="wpio_excluded_dirs"
                               value="<?php echo esc_attr( $o['excluded_dirs'] ); ?>"
                               placeholder="cache, backup-plugin, elementor/css"
                               style="max-width:420px;" />
                        <div class="desc">Example: <code>cache, .git, node_modules</code> — any file path containing one of these strings will be skipped.</div>
                    </div>
                </div>
                <?php if ( ! empty( $excluded_preview ) ) : ?>
                <div style="margin-top:10px;">
                    <strong style="font-size:12px;color:#666;">Currently excluding paths containing:</strong>
                    <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ( $excluded_preview as $fragment ) : ?>
                        <code style="background:#fff0f4;color:#FF2462;border:1px solid #ffc2d4;padding:2px 10px;border-radius:20px;font-size:12px;"><?php echo esc_html( $fragment ); ?></code>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- T3: Conversion method -->
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>⚙️ Conversion method</h2>
                <p>Choose which PHP image library handles the conversion. Auto is recommended for most setups.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-method-grid">

                    <!-- Auto -->
                    <label class="wpio-method-card <?php echo $o['conversion_method'] === 'auto' ? 'selected' : ''; ?>">
                        <input type="radio" name="wpio_conversion_method" value="auto" <?php checked( $o['conversion_method'], 'auto' ); ?> />
                        <div class="wpio-method-dot"></div>
                        <div class="wpio-method-icon">🤖</div>
                        <div class="wpio-method-name">Auto</div>
                        <div class="wpio-method-desc">Use Imagick if available, fall back to GD automatically.</div>
                        <div class="wpio-method-status">
                            <span class="wpio-pill ok">✓ Recommended</span>
                        </div>
                    </label>

                    <!-- Imagick -->
                    <label class="wpio-method-card <?php echo $o['conversion_method'] === 'imagick' ? 'selected' : ''; ?> <?php echo ! $has_imagick ? 'disabled' : ''; ?>">
                        <input type="radio" name="wpio_conversion_method" value="imagick" <?php checked( $o['conversion_method'], 'imagick' ); ?> <?php disabled( ! $has_imagick ); ?> />
                        <div class="wpio-method-dot"></div>
                        <div class="wpio-method-icon">🔮</div>
                        <div class="wpio-method-name">Imagick</div>
                        <div class="wpio-method-desc">Best quality &amp; AVIF support. Requires the <code>imagick</code> PHP extension.</div>
                        <div class="wpio-method-status">
                            <?php if ( $has_imagick ) : ?>
                                <span class="wpio-pill ok">✓ Available</span>
                            <?php else : ?>
                                <span class="wpio-pill err">✗ Not installed</span>
                            <?php endif; ?>
                        </div>
                    </label>

                    <!-- GD -->
                    <label class="wpio-method-card <?php echo $o['conversion_method'] === 'gd' ? 'selected' : ''; ?> <?php echo ! $has_gd ? 'disabled' : ''; ?>">
                        <input type="radio" name="wpio_conversion_method" value="gd" <?php checked( $o['conversion_method'], 'gd' ); ?> <?php disabled( ! $has_gd ); ?> />
                        <div class="wpio-method-dot"></div>
                        <div class="wpio-method-icon">🎨</div>
                        <div class="wpio-method-name">GD</div>
                        <div class="wpio-method-desc">Lightweight, built into most PHP installs. WebP support requires PHP 5.5+.</div>
                        <div class="wpio-method-status">
                            <?php if ( $has_gd ) : ?>
                                <span class="wpio-pill ok">✓ Available</span>
                            <?php else : ?>
                                <span class="wpio-pill err">✗ Not installed</span>
                            <?php endif; ?>
                        </div>
                    </label>

                </div>
                <?php if ( ! $has_imagick && ! $has_gd ) : ?>
                <div class="wpio-alert warn" style="margin-top:12px;">
                    <span class="wpio-alert-icon">⚠️</span>
                    <span>Neither Imagick nor GD is available on this server. Conversion will fail until one is installed. Contact your host.</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Image resizing -->
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>📐 Maximum image dimensions</h2>
                <p>Resize large images before converting. Original is backed up if backup is enabled.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Resize mode<small>Applied before conversion</small></div>
                    <div class="wpio-field-input">
                        <select name="wpio_resize_mode" id="wpio_resize_mode">
                            <option value="none" <?php selected($o['resize_mode'],'none');?>>Disabled — keep original dimensions</option>
                            <option value="max_dimension" <?php selected($o['resize_mode'],'max_dimension');?>>Limit max dimension (longest side)</option>
                            <option value="max_width" <?php selected($o['resize_mode'],'max_width');?>>Limit max width only</option>
                        </select>
                    </div>
                </div>
                <div class="wpio-field-row" id="wpio_row_maxdim" <?php echo $o['resize_mode']!=='max_dimension'?'style="display:none;"':'';?>>
                    <div class="wpio-field-label">Max dimension<small>Longest side, aspect ratio preserved</small></div>
                    <div class="wpio-field-input">
                        <input type="number" name="wpio_max_dimension" value="<?php echo esc_attr($o['max_dimension']);?>" min="0" max="9999" style="max-width:120px;" /> px
                        <div class="desc">E.g. 2048px — no side will exceed this.</div>
                    </div>
                </div>
                <div class="wpio-field-row" id="wpio_row_maxw" <?php echo $o['resize_mode']!=='max_width'?'style="display:none;"':'';?>>
                    <div class="wpio-field-label">Max width<small>Height scales proportionally</small></div>
                    <div class="wpio-field-input">
                        <input type="number" name="wpio_max_width" value="<?php echo esc_attr($o['max_width']);?>" min="0" max="9999" style="max-width:120px;" /> px
                    </div>
                </div>
            </div>
        </div>

        <!-- Extra features -->
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>🏷️ Extra features</h2>
                <p>Additional options that fine-tune conversion behaviour.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-toggle-row">
                    <div class="label">Remove if larger than original
                        <small>Delete the converted file automatically if it ends up bigger than the source</small>
                    </div>
                    <label class="wpio-toggle">
                        <input type="checkbox" name="wpio_remove_if_larger" value="1" <?php checked( $o['remove_larger'], '1' ); ?> />
                        <span class="wpio-toggle-slider"></span>
                    </label>
                </div>
                <div class="wpio-toggle-row">
                    <div class="label">Strip EXIF metadata
                        <small>Remove camera model, GPS and embedded data — saves extra KB &amp; improves privacy</small>
                    </div>
                    <label class="wpio-toggle">
                        <input type="checkbox" name="wpio_strip_exif" value="1" <?php checked( $o['strip_exif'], '1' ); ?> />
                        <span class="wpio-toggle-slider"></span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Custom folders -->
        <div class="wpio-card" id="wpio-folders">
            <div class="wpio-card-head"><div>
                <h2>📁 Custom folder optimization</h2>
                <p>By default only <code>/wp-content/uploads/</code> is scanned. Add extra directories below.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Additional folders<small>One path per line. Relative to WP root or absolute.</small></div>
                    <div class="wpio-field-input">
                        <textarea name="wpio_custom_folders" rows="5" style="max-width:100%;width:460px;font-family:monospace;font-size:12px;" placeholder="wp-content/themes/my-theme/images
/var/www/html/custom-assets"><?php echo esc_textarea($o['custom_folders']);?></textarea>
                        <div class="desc">PHP must have read/write access to these directories.</div>
                    </div>
                </div>
                <?php $folders = WPIO_Folder_Scanner::get_folders(); ?>
                <?php if ( ! empty( $folders ) ) : ?>
                <div style="margin-top:12px;">
                    <strong style="font-size:12px;color:#666;">Currently scanning:</strong>
                    <ul class="wpio-folder-list">
                        <?php foreach ( $folders as $i => $folder ) : ?>
                        <li>
                            <span>📂</span>
                            <?php echo esc_html( $folder ); ?>
                            <?php if ( $i === 0 ) echo '<span class="wpio-folder-badge">default</span>'; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Server protection -->
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>🛡️ Server protection</h2>
                <p>Keep bulk conversion gentle on shared hosting.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Images per chunk<small>Shared: 3–5 · VPS: 10–20</small></div>
                    <div class="wpio-field-input">
                        <input type="number" name="wpio_batch_size" value="<?php echo esc_attr($o['batch_size']);?>" min="1" max="50" style="max-width:80px;" />
                    </div>
                </div>
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Pause between chunks<small>ms. 500ms safe for shared hosting.</small></div>
                    <div class="wpio-field-input">
                        <input type="number" name="wpio_sleep_time" value="<?php echo esc_attr($o['sleep_time']);?>" min="0" max="5000" style="max-width:100px;" /> ms
                    </div>
                </div>
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Memory limit override</div>
                    <div class="wpio-field-input">
                        <input type="text" name="wpio_memory_limit" value="<?php echo esc_attr($o['memory_limit']);?>" style="max-width:100px;" placeholder="256M" />
                        <div class="desc">e.g. <code>256M</code>, <code>512M</code></div>
                    </div>
                </div>
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Execution time per chunk<small>Seconds</small></div>
                    <div class="wpio-field-input">
                        <input type="number" name="wpio_exec_time" value="<?php echo esc_attr($o['exec_time']);?>" min="30" max="600" style="max-width:100px;" /> s
                    </div>
                </div>
            </div>
        </div>

        <!-- Remote server COMING SOON -->
        <div class="wpio-card wpio-coming-soon-wrap">
            <div class="wpio-coming-soon-overlay">
                <span class="cs-icon">🚧</span>
                <div class="cs-title">Remote Server Conversion</div>
                <div class="cs-sub">Coming soon — offload conversion to a separate server</div>
            </div>
            <div class="wpio-card-head"><div>
                <h2>🌐 Remote server conversion <span class="wpio-coming-soon-badge">Coming soon</span></h2>
                <p>Convert images on a remote server — zero load on your hosting.</p>
            </div></div>
            <div class="wpio-card-body" style="filter:blur(2px);pointer-events:none;user-select:none;">
                <div class="wpio-field-row">
                    <div class="wpio-field-label">Remote server URL</div>
                    <div class="wpio-field-input"><input type="url" disabled placeholder="https://your-conversion-server.com" style="max-width:320px;" /></div>
                </div>
                <div class="wpio-field-row">
                    <div class="wpio-field-label">API Token</div>
                    <div class="wpio-field-input"><input type="text" disabled placeholder="Bearer token" style="max-width:320px;" /></div>
                </div>
            </div>
        </div>

        <?php submit_button( 'Save Settings', '', 'submit', true, array( 'class' => 'wpio-btn wpio-btn-primary wpio-btn-lg' ) ); ?>
        </form>
        </div>
        <?php
    }

    /* ================================================
       TAB: DELIVERY / REWRITE
    ================================================ */
    private function tab_delivery() {
        $format = get_option( 'wpio_format', 'webp' );
        ?>
        <div class="wpio-layout full">
        <?php if ( WPIO_Nginx::is_nginx() ) : ?>
        <div class="wpio-alert warn"><span class="wpio-alert-icon">⚠️</span>
            <span>Nginx detected — .htaccess rules won't work. Use the Nginx config block below.</span>
        </div>
        <?php else : ?>
        <div class="wpio-alert ok"><span class="wpio-alert-icon">✅</span>
            <span>Apache/LiteSpeed detected — .htaccess rewrite rules are active automatically on plugin activation.</span>
        </div>
        <?php endif; ?>
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>🔀 How image delivery works</h2>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-alert info">
                    <span class="wpio-alert-icon">ℹ️</span>
                    <span>When a browser requests <code>photo.jpg</code>, the server checks if <code>photo.webp</code> exists and the browser supports it. If yes, the optimized file is served. <strong>No page caching issues</strong> — URLs never change.</span>
                </div>
            </div>
        </div>
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>🔧 Nginx configuration</h2>
                <p>Paste inside your <code>server {}</code> block, then reload Nginx.</p>
            </div></div>
            <div class="wpio-card-body">
                <textarea class="large-text code" rows="18" readonly
                    style="font-family:monospace;font-size:13px;background:#1e1e2e;color:#cdd6f4;border:none;border-radius:8px;padding:16px;resize:vertical;width:100%;"><?php echo esc_textarea(WPIO_Nginx::build_rules($format));?></textarea>
                <div style="margin-top:12px;display:flex;gap:10px;align-items:center;">
                    <button class="wpio-btn wpio-btn-secondary" onclick="var t=this.parentNode.previousElementSibling;t.select();document.execCommand('copy');this.textContent='✔ Copied!';setTimeout(()=>this.textContent='📋 Copy to Clipboard',2000);return false;">📋 Copy to Clipboard</button>
                    <code style="font-size:12px;color:#666;">sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>
                </div>
            </div>
        </div>
        </div>
        <?php
    }

    /* ================================================
       TAB: SYSTEM STATUS
    ================================================ */
    private function tab_system() {
        $checks = WPIO_Environment::check();
        $icons  = array( 'ok' => '✅', 'warning' => '⚠️', 'error' => '❌', 'info' => 'ℹ️' );
        ?>
        <div class="wpio-layout full">
        <div class="wpio-card">
            <div class="wpio-card-head"><div><h2>🖥️ Server Environment</h2></div></div>
            <div class="wpio-card-body" style="padding:0;">
                <table class="wpio-sys-table">
                    <thead><tr><th>Check</th><th>Value</th><th>Status</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ( $checks as $c ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($c['label']); ?></strong></td>
                        <td style="font-family:monospace;"><?php echo esc_html($c['value']); ?></td>
                        <td><span class="wpio-dot wpio-dot-<?php echo esc_attr($c['status']); ?>"></span><?php echo $icons[$c['status']] ?? ''; ?></td>
                        <td style="color:#666;font-size:12px;"><?php echo esc_html($c['message']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="wpio-card">
            <div class="wpio-card-head"><div><h2>⚡ Runtime info</h2></div></div>
            <div class="wpio-card-body" style="padding:0;">
                <table class="wpio-sys-table"><tbody>
                    <?php
                    $ts  = wp_next_scheduled( WPIO_Queue::CRON_HOOK );
                    $ext = WPIO_Folder_Scanner::get_allowed_extensions();
                    $exc = WPIO_Folder_Scanner::get_excluded_dirs();
                    $method = get_option( 'wpio_conversion_method', 'auto' );
                    $rows = array(
                        array( 'WordPress Version',   get_bloginfo('version') ),
                        array( 'Plugin Version',      WPIO_VERSION ),
                        array( 'Active Format',       strtoupper( get_option('wpio_format','webp') ) ),
                        array( 'Conversion Method',   ucfirst( $method ) . ( $method === 'auto' ? ' (Imagick → GD)' : '' ) ),
                        array( 'Scanning Extensions', implode( ', ', array_map( 'strtoupper', $ext ) ) ),
                        array( 'Excluded Fragments',  ! empty( $exc ) ? implode( ', ', $exc ) : 'none' ),
                        array( 'Scanned Folders',     count( WPIO_Folder_Scanner::get_folders() ) ),
                        array( 'Batch Size',          get_option('wpio_batch_size',5) . ' images/chunk' ),
                        array( 'Background Cron',     $ts ? '🟢 Scheduled (next: ' . human_time_diff($ts) . ')' : '⚪ Not scheduled' ),
                        array( 'Server Software',     $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ),
                    );
                    foreach ( $rows as $row ) :
                    ?>
                    <tr>
                        <td style="width:220px;"><strong><?php echo esc_html($row[0]); ?></strong></td>
                        <td><?php echo esc_html($row[1]); ?></td>
                        <td></td><td></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
        </div>
        <?php
    }

    /* ================================================
       TAB: HELP
    ================================================ */
    private function tab_help() {
        ?>
        <div class="wpio-layout full">
        <div class="wpio-card">
            <div class="wpio-card-head"><div><h2>❓ How does the plugin work?</h2></div></div>
            <div class="wpio-card-body">
                <p>When a browser requests an image, the plugin checks if an optimized version (WebP or AVIF) exists. If so, and the browser supports that format, the optimized file is served. <strong>Image URLs never change</strong>, so there are no caching issues.</p>
                <p>Conversion happens automatically on upload, or in bulk via the <strong>General Settings</strong> tab. A background WP-Cron task continues converting even if you close the admin page.</p>
            </div>
        </div>
        <div class="wpio-card">
            <div class="wpio-card-head"><div><h2>💻 WP-CLI commands</h2></div></div>
            <div class="wpio-card-body">
                <table class="wpio-sys-table"><thead><tr><th>Command</th><th>Description</th></tr></thead><tbody>
                    <tr><td><code>wp image-optimizer bulk</code></td><td>Bulk convert all pending images</td></tr>
                    <tr><td><code>wp image-optimizer bulk --format=avif --quality=80</code></td><td>Bulk with specific settings</td></tr>
                    <tr><td><code>wp image-optimizer stats</code></td><td>Show conversion stats</td></tr>
                    <tr><td><code>wp image-optimizer restore &lt;path&gt;</code></td><td>Restore a single image</td></tr>
                    <tr><td><code>wp image-optimizer restore-all</code></td><td>Restore all images</td></tr>
                </tbody></table>
            </div>
        </div>
        <div class="wpio-card">
            <div class="wpio-card-head"><div><h2>🐛 Troubleshooting</h2></div></div>
            <div class="wpio-card-body">
                <div class="wpio-alert warn"><span class="wpio-alert-icon">⚠️</span><span><strong>Images not converting?</strong> Check System Status — GD or Imagick must support WebP/AVIF output.</span></div>
                <div class="wpio-alert warn"><span class="wpio-alert-icon">⚠️</span><span><strong>Browser still serving JPEG?</strong> On Nginx, add rewrite rules from the Delivery tab. On Apache, deactivate and reactivate the plugin.</span></div>
                <div class="wpio-alert info"><span class="wpio-alert-icon">ℹ️</span><span><strong>Bulk stuck?</strong> Add a real cron job in cPanel: <code>wget -q -O /dev/null "<?php echo esc_url(site_url('/wp-cron.php?doing_wp_cron')); ?>"</code></span></div>
            </div>
        </div>
        </div>
        <?php
    }

    /* ================================================
       BULK CARD
    ================================================ */
    private function render_bulk_card() {
        $stats = WPIO_Stats::get();
        $q     = WPIO_Queue::get_progress();
        $p     = $q['progress'];
        $pct   = $stats['total'] > 0 ? round( ($stats['converted'] / $stats['total']) * 100 ) : 0;
        ?>
        <div class="wpio-card">
            <div class="wpio-card-head"><div>
                <h2>⚡ Bulk optimization of images</h2>
                <p>Optimize all images with one click. Processing runs in the background.</p>
            </div></div>
            <div class="wpio-card-body">
                <div class="wpio-rings">
                    <div class="wpio-ring-wrap">
                        <?php $this->svg_ring( $pct, '#FF2462' ); ?>
                        <div class="wpio-ring-label"><?php echo esc_html($pct); ?>% converted</div>
                        <div class="wpio-ring-sub"><?php echo esc_html($stats['pending']); ?> images remaining</div>
                    </div>
                </div>
                <?php if ( WPIO_Nginx::is_nginx() ) : ?>
                <div class="wpio-alert warn"><span class="wpio-alert-icon">⚠️</span><span>Nginx detected. See <a href="<?php echo esc_url(admin_url('upload.php?page=wp-image-optimizer&tab=delivery'));?>">Delivery tab</a>.</span></div>
                <?php endif; ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button id="wpio-bulk-start" class="wpio-btn wpio-btn-primary wpio-btn-lg" <?php echo $q['running'] ? 'disabled' : ''; ?>>
                        <span>⚡</span> <?php echo $q['running'] ? 'Running&hellip;' : 'Start Bulk Convert'; ?>
                    </button>
                    <button id="wpio-bulk-cancel" class="wpio-btn wpio-btn-danger" style="<?php echo $q['running'] ? '' : 'display:none;'; ?>">✕ Cancel</button>
                </div>
                <div id="wpio-live-progress" style="<?php echo $q['running'] ? '' : 'display:none;'; ?>">
                    <div class="wpio-progress-wrap" style="margin-top:16px;">
                        <div class="wpio-progress-bar" id="wpio-prog-bar" style="width:<?php echo esc_attr($p['total']>0?round($p['done']/$p['total']*100):0); ?>%;">
                            <?php echo $p['total']>0 ? round($p['done']/$p['total']*100) : 0; ?>%
                        </div>
                    </div>
                    <p id="wpio-prog-text" style="color:#666;font-size:13px;"><?php echo esc_html($p['done'] . ' / ' . $p['total'] . ' images processed'); ?></p>
                    <div class="wpio-bulk-log" id="wpio-bulk-log"></div>
                </div>
                <?php if ( $stats['backup_bytes'] > 0 ) : ?>
                <div style="margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f1;">
                    <strong style="font-size:13px;">💾 Backup storage:</strong>
                    <span style="color:#666;font-size:13px;margin-left:6px;"><?php echo esc_html($stats['backup_mb']); ?> MB used</span>
                    <button class="wpio-btn wpio-btn-danger" id="wpio-purge-backups" style="margin-left:12px;" data-nonce="<?php echo wp_create_nonce('wpio_delete_backup_all'); ?>">🗑 Purge all backups</button>
                    <div style="font-size:12px;color:#999;margin-top:6px;">Only purge after confirming your site looks correct.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* -- SVG Ring ------------------------------------- */
    private function svg_ring( $pct, $color = '#FF2462', $size = 110, $stroke = 10 ) {
        $r    = ( $size - $stroke ) / 2;
        $circ = round( 2 * M_PI * $r, 2 );
        $dash = round( $circ * $pct / 100, 2 );
        $gap  = $circ - $dash;
        ?>
        <svg width="<?php echo $size;?>" height="<?php echo $size;?>" viewBox="0 0 <?php echo $size;?> <?php echo $size;?>">
            <circle cx="<?php echo $size/2;?>" cy="<?php echo $size/2;?>" r="<?php echo $r;?>" fill="none" stroke="#f0f0f1" stroke-width="<?php echo $stroke;?>"/>
            <circle cx="<?php echo $size/2;?>" cy="<?php echo $size/2;?>" r="<?php echo $r;?>" fill="none" stroke="<?php echo esc_attr($color);?>" stroke-width="<?php echo $stroke;?>"
                stroke-dasharray="<?php echo $dash.' '.$gap;?>" stroke-dashoffset="<?php echo $circ/4;?>" stroke-linecap="round" />
            <text x="50%" y="50%" text-anchor="middle" dy=".35em" font-size="<?php echo $size*.18;?>" font-weight="700" fill="<?php echo esc_attr($color);?>">
                <?php echo esc_html($pct); ?>%
            </text>
        </svg>
        <?php
    }

    /* -- Sidebar -------------------------------------- */
    private function render_sidebar() {
        $stats = WPIO_Stats::get();
        ?>
        <div class="wpio-sidebar">
            <div class="wpio-card">
                <div class="wpio-card-head"><div><h2>📊 Stats</h2></div></div>
                <div class="wpio-card-body">
                    <div class="wpio-stat-grid" style="grid-template-columns:1fr 1fr;">
                        <div class="wpio-stat-card"><div class="num"><?php echo esc_html($stats['total']);?></div><div class="lbl">Total</div></div>
                        <div class="wpio-stat-card"><div class="num ok"><?php echo esc_html($stats['converted']);?></div><div class="lbl">Done</div></div>
                        <div class="wpio-stat-card"><div class="num muted"><?php echo esc_html($stats['pending']);?></div><div class="lbl">Pending</div></div>
                        <div class="wpio-stat-card"><div class="num ok"><?php echo esc_html($stats['saved_mb']);?> MB</div><div class="lbl">Saved</div></div>
                    </div>
                    <?php if ( $stats['saving_pct'] > 0 ) : ?>
                    <div style="font-size:12px;color:#666;text-align:center;margin-top:4px;">
                        Average reduction: <strong style="color:var(--wpio-accent);"><?php echo esc_html($stats['saving_pct']); ?>%</strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wpio-card">
                <div class="wpio-card-head"><div><h2>🔗 Quick links</h2></div></div>
                <div class="wpio-card-body" style="padding:12px 16px;">
                    <ul style="margin:0;padding:0;list-style:none;font-size:13px;line-height:2;">
                        <li><a href="<?php echo esc_url(admin_url('upload.php?page=wp-image-optimizer&tab=system'));?>">🖥️ System Status</a></li>
                        <li><a href="<?php echo esc_url(admin_url('upload.php?page=wp-image-optimizer&tab=delivery'));?>">🔀 Delivery / Nginx Config</a></li>
                        <li><a href="<?php echo esc_url(admin_url('upload.php?page=wp-image-optimizer&tab=advanced'));?>#wpio-folders">📁 Manage Folders</a></li>
                        <li><a href="https://github.com/wisnuub/wp-image-optimizer" target="_blank">⭐ GitHub Repository</a></li>
                    </ul>
                </div>
            </div>
            <div class="wpio-card">
                <div class="wpio-card-head"><div><h2>🖼️ Banner image</h2></div></div>
                <div class="wpio-card-body" style="font-size:12.5px;color:#666;">
                    <p style="margin:0 0 8px;">Replace the banner with your own image:</p>
                    <code style="display:block;background:#f5f5f5;padding:10px;border-radius:6px;font-size:11.5px;line-height:1.6;">add_filter( 'wpio_banner_image_url', function() {<br>&nbsp;&nbsp;return get_template_directory_uri() . '/images/wpio-banner.jpg';<br>} );</code>
                    <p style="margin:8px 0 0;color:#aaa;">Recommended: 980×100px JPG or WebP.</p>
                </div>
            </div>
        </div>
        <?php
    }

    /* -- AJAX ----------------------------------------- */
    public function ajax_queue_start() {
        if ( ! check_ajax_referer('wpio_queue_start','_wpnonce',false) || ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized');
        wp_send_json_success( array( 'total' => WPIO_Queue::build() ) );
    }
    public function ajax_queue_chunk() {
        if ( ! check_ajax_referer('wpio_chunk','_wpnonce',false) || ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized');
        wp_send_json_success( WPIO_Queue::process_chunk() );
    }
    public function ajax_queue_cancel() {
        if ( ! check_ajax_referer('wpio_queue_cancel','_wpnonce',false) || ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized');
        WPIO_Queue::cancel(); wp_send_json_success();
    }
    public function ajax_queue_progress() {
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized');
        wp_send_json_success( WPIO_Queue::get_progress() );
    }
    public function ajax_restore_image() {
        $id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if ( ! check_ajax_referer('wpio_restore_'.$id,'_wpnonce',false) || ! current_user_can('upload_files') ) wp_send_json_error('Unauthorized');
        $result = WPIO_Backup::restore( get_attached_file($id) );
        WPIO_Stats::bust_cache();
        is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success();
    }
    public function ajax_delete_backup() {
        if ( ! check_ajax_referer('wpio_delete_backup_all','_wpnonce',false) || ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized');
        $dir = WPIO_Backup::backup_dir();
        if ( is_dir($dir) ) {
            $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST );
            foreach ($iter as $f) { $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath()); }
            rmdir($dir);
        }
        WPIO_Stats::bust_cache(); wp_send_json_success();
    }
    public function on_upload( $attachment_id ) {
        if ( get_option('wpio_auto_convert','1') !== '1' ) return;
        $file = get_attached_file($attachment_id);
        if ( ! $file ) return;
        WPIO_Converter::convert( $file, get_option('wpio_format','webp'), (int) get_option('wpio_quality',82) );
        WPIO_Stats::bust_cache();
    }
}
