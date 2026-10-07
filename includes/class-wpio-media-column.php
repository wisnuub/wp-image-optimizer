<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds a conversion status column to the Media Library list view.
 * Shows format badge, file size comparison, savings and convert / use-original buttons.
 */
class WPIO_Media_Column {

    public function __construct() {
        add_filter( 'manage_media_columns',         array( $this, 'add_column' ) );
        add_action( 'manage_media_custom_column',   array( $this, 'render_column' ), 10, 2 );
        add_action( 'admin_enqueue_scripts',        array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wpio_convert_single',  array( $this, 'ajax_convert_single' ) );
        add_action( 'wp_ajax_wpio_revert_single',   array( $this, 'ajax_revert_single' ) );
    }

    public function add_column( $columns ) {
        $columns['wpio_status'] = __( 'WebP / AVIF', 'w-image-converter' );
        return $columns;
    }

    public function render_column( $column_name, $attachment_id ) {
        if ( $column_name !== 'wpio_status' ) return;

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) { echo '<span class="wpio-na">—</span>'; return; }

        $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

        // Already a WebP/AVIF upload — no conversion needed.
        if ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
            echo '<span class="wpio-badge wpio-native">' . esc_html( strtoupper( $ext ) ) . ' native</span>';
            return;
        }

        if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
            echo '<span class="wpio-na">—</span>';
            return;
        }

        $formats           = WPIO_Converter::get_formats( get_option( 'wpio_format', 'webp' ) );
        $converted_formats = array();
        $best_conv_size    = PHP_INT_MAX;
        $best_conv_label   = '';
        foreach ( $formats as $fmt ) {
            if ( WPIO_Converter::is_converted( $file, $fmt ) ) {
                $sz = filesize( WPIO_Converter::converted_path( $file, $fmt ) );
                $converted_formats[ $fmt ] = $sz;
                if ( $sz < $best_conv_size ) {
                    $best_conv_size  = $sz;
                    $best_conv_label = $fmt;
                }
            }
        }
        $is_converted = count( $converted_formats ) === count( $formats );
        $nonce        = wp_create_nonce( 'wpio_single_' . $attachment_id );

        echo '<div class="wpio-col-wrap">';

        if ( $is_converted ) {
            $orig_size  = filesize( $file );
            $saving_pct = $orig_size > 0 ? round( ( 1 - $best_conv_size / $orig_size ) * 100 ) : 0;
            $fill       = $orig_size > 0 ? round( $best_conv_size / $orig_size * 100 ) : 100;

            foreach ( array_keys( $converted_formats ) as $fmt ) {
                echo '<span class="wpio-badge wpio-done">' . esc_html( strtoupper( $fmt ) ) . ' ✓</span> ';
            }

            echo '<div class="wpio-size-bar-wrap">';
            echo   '<div class="wpio-size-row"><span class="wpio-size-lbl">Original</span><span class="wpio-size-val">' . esc_html( size_format( $orig_size, 1 ) ) . '</span></div>';
            echo   '<div class="wpio-size-track"><div class="wpio-size-fill" style="width:100%"></div></div>';
            echo   '<div class="wpio-size-row"><span class="wpio-size-lbl">' . esc_html( strtoupper( $best_conv_label ) ) . '</span><span class="wpio-size-val">' . esc_html( size_format( $best_conv_size, 1 ) ) . '</span></div>';
            echo   '<div class="wpio-size-track"><div class="wpio-size-fill wpio-size-fill-conv" style="width:' . esc_attr( $fill ) . '%"></div></div>';
            if ( $saving_pct > 0 ) {
                echo '<div class="wpio-saving">↓ ' . esc_html( $saving_pct ) . '% smaller</div>';
            }
            echo '</div>';

            echo '<button type="button" class="button button-small wpio-revert-btn" data-id="' . esc_attr( $attachment_id ) . '" data-nonce="' . esc_attr( $nonce ) . '" style="margin-top:5px;">Use original</button>';
        } elseif ( WPIO_Converter::is_skipped( $file ) ) {
            $list   = get_option( WPIO_Converter::SKIP_OPTION, array() );
            $reason = $list[ md5( $file ) ]['reason'] ?? '';
            echo '<span class="wpio-badge wpio-skipped" title="' . esc_attr( $reason ) . '">Original kept</span><br>';
            echo '<small class="wpio-reason">' . esc_html( $reason ) . '</small><br>';
            echo '<button type="button" class="button button-small wpio-convert-btn" data-id="' . esc_attr( $attachment_id ) . '" data-nonce="' . esc_attr( $nonce ) . '" style="margin-top:5px;">Try again</button>';
        } else {
            echo '<span class="wpio-badge wpio-pending">Not converted yet</span><br>';
            echo '<button type="button" class="button button-small wpio-convert-btn" data-id="' . esc_attr( $attachment_id ) . '" data-nonce="' . esc_attr( $nonce ) . '" style="margin-top:5px;">Convert</button>';
        }
        echo '<span class="wpio-spinner spinner" style="float:none;margin:5px 0 0 6px;"></span>';

        echo '</div>';
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'upload.php' ) return;

        wp_add_inline_style( 'media', '
            .wpio-col-wrap      { font-size:12px; line-height:1.5; }
            .wpio-badge         { display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:600; }
            .wpio-done          { background:#d4edda; color:#155724; }
            .wpio-pending       { background:#fff3cd; color:#856404; }
            .wpio-skipped       { background:#f0f0f1; color:#50575e; }
            .wpio-native        { background:#e8f0fe; color:#1a56db; }
            .wpio-reason        { color:#646970; }
            .wpio-na            { color:#999; }
            .wpio-saving        { color:#155724; font-size:11px; margin-top:2px; }
            .wpio-size-bar-wrap { margin-top:5px; }
            .wpio-size-row      { display:flex; justify-content:space-between; font-size:11px; color:#555; margin-bottom:1px; }
            .wpio-size-lbl      { font-weight:600; }
            .wpio-size-track    { height:5px; background:#f0f0f1; border-radius:3px; margin-bottom:4px; overflow:hidden; }
            .wpio-size-fill     { height:100%; background:#ccc; border-radius:3px; }
            .wpio-size-fill-conv{ background:#FF2462; }
        ' );

        wp_add_inline_script( 'jquery', '
        jQuery(function($){
            function run(btn, action){
                var wrap = btn.closest(".wpio-col-wrap");
                btn.prop("disabled", true);
                wrap.find(".wpio-spinner").addClass("is-active");
                $.post(ajaxurl, { action: action, attachment_id: btn.data("id"), _wpnonce: btn.data("nonce") }, function(res){
                    if (res.success) {
                        wrap.replaceWith(res.data.html);
                    } else {
                        wrap.find(".wpio-spinner").removeClass("is-active");
                        btn.prop("disabled", false);
                        window.alert(res.data);
                    }
                });
            }
            $(document).on("click", ".wpio-convert-btn", function(){ run($(this), "wpio_convert_single"); });
            $(document).on("click", ".wpio-revert-btn", function(){ run($(this), "wpio_revert_single"); });
        });
        ' );
    }

    /**
     * The original file plus every generated size of an attachment.
     */
    private function attachment_files( $attachment_id ) {
        $file  = get_attached_file( $attachment_id );
        $files = array( $file );
        $meta  = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) ) {
            $dir = dirname( $file );
            if ( ! empty( $meta['original_image'] ) ) $files[] = $dir . '/' . $meta['original_image'];
            foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
                if ( ! empty( $size['file'] ) ) $files[] = $dir . '/' . $size['file'];
            }
        }
        return array_unique( array_filter( $files, 'file_exists' ) );
    }

    /* -- AJAX: convert one attachment (all sizes), forcing a retry of skipped files -- */
    public function ajax_convert_single() {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! check_ajax_referer( 'wpio_single_' . $attachment_id, '_wpnonce', false ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $format  = get_option( 'wpio_format', 'webp' );
        $quality = (int) get_option( 'wpio_quality', 82 );
        $error   = null;

        foreach ( $this->attachment_files( $attachment_id ) as $path ) {
            WPIO_Converter::unmark_skipped( $path );
            WPIO_Converter::delete_converted( $path );
            $result = WPIO_Converter::convert( $path, $format, $quality );
            if ( is_wp_error( $result ) && ! $error && $path === get_attached_file( $attachment_id ) ) {
                $error = $result;
            }
        }
        WPIO_Stats::bust_cache();

        // A skipped main file isn't an error to report — the cell shows why.
        if ( $error && 'output_larger' !== $error->get_error_code() ) {
            wp_send_json_error( $error->get_error_message() );
        }
        wp_send_json_success( array( 'html' => $this->render_cell_html( $attachment_id ) ) );
    }

    /* -- AJAX: remove converted copies so the original is served -- */
    public function ajax_revert_single() {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! check_ajax_referer( 'wpio_single_' . $attachment_id, '_wpnonce', false ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        foreach ( $this->attachment_files( $attachment_id ) as $path ) {
            WPIO_Converter::delete_converted( $path );
        }
        WPIO_Stats::bust_cache();
        wp_send_json_success( array( 'html' => $this->render_cell_html( $attachment_id ) ) );
    }

    /* -- Shared cell renderer -------------------------------- */
    private function render_cell_html( $attachment_id ) {
        ob_start();
        $this->render_column( 'wpio_status', $attachment_id );
        return ob_get_clean();
    }
}
