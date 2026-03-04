<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds a conversion status column to the Media Library list view.
 * Shows format badge, file size comparison bar, savings, restore/re-optimize buttons.
 */
class WPIO_Media_Column {

    public function __construct() {
        add_filter( 'manage_media_columns',         array( $this, 'add_column' ) );
        add_action( 'manage_media_custom_column',   array( $this, 'render_column' ), 10, 2 );
        add_action( 'admin_enqueue_scripts',        array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wpio_convert_single',  array( $this, 'ajax_convert_single' ) );
        add_action( 'wp_ajax_wpio_restore_single',  array( $this, 'ajax_restore_single' ) );
    }

    public function add_column( $columns ) {
        $columns['wpio_status'] = __( 'Optimizer', 'wp-image-optimizer' );
        return $columns;
    }

    public function render_column( $column_name, $attachment_id ) {
        if ( $column_name !== 'wpio_status' ) return;

        $file = get_attached_file( $attachment_id );
        if ( ! $file ) { echo '<span class="wpio-na">N/A</span>'; return; }

        $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

        // Already a WebP/AVIF upload — no conversion needed.
        if ( in_array( $ext, array( 'webp', 'avif' ) ) ) {
            echo '<span class="wpio-badge wpio-native">' . strtoupper( $ext ) . ' native</span>';
            return;
        }

        if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ) ) ) {
            echo '<span class="wpio-na">—</span>';
            return;
        }

        $format       = get_option( 'wpio_format', 'webp' );
        $converted    = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $file );
        $is_converted = file_exists( $converted );
        $has_backup   = WPIO_Backup::has_backup( $file );

        echo '<div class="wpio-col-wrap">';

        if ( $is_converted ) {
            $orig_size  = @filesize( $file ) ?: 0;
            $conv_size  = @filesize( $converted ) ?: 0;
            $saving_pct = $orig_size > 0 ? round( ( 1 - $conv_size / $orig_size ) * 100 ) : 0;
            $orig_kb    = round( $orig_size / 1024, 1 );
            $conv_kb    = round( $conv_size / 1024, 1 );

            // Badge
            echo '<span class="wpio-badge wpio-done">' . strtoupper( $format ) . ' ✓</span>';

            // Size comparison bar
            echo '<div class="wpio-size-bar-wrap">';
            echo   '<div class="wpio-size-row"><span class="wpio-size-lbl">Original</span><span class="wpio-size-val">' . esc_html( $orig_kb ) . ' KB</span></div>';
            echo   '<div class="wpio-size-track"><div class="wpio-size-fill" style="width:100%"></div></div>';
            $fill = $orig_size > 0 ? round( $conv_size / $orig_size * 100 ) : 100;
            echo   '<div class="wpio-size-row"><span class="wpio-size-lbl">' . strtoupper( $format ) . '</span><span class="wpio-size-val">' . esc_html( $conv_kb ) . ' KB</span></div>';
            echo   '<div class="wpio-size-track"><div class="wpio-size-fill wpio-size-fill-conv" style="width:' . esc_attr( $fill ) . '%"></div></div>';
            if ( $saving_pct > 0 ) {
                echo '<div class="wpio-saving">↓ ' . esc_html( $saving_pct ) . '% smaller</div>';
            }
            echo '</div>';

            // Restore button (only if backup exists)
            if ( $has_backup ) {
                echo '<button class="button button-small wpio-restore-btn" '
                    . 'data-id="' . esc_attr( $attachment_id ) . '" '
                    . 'data-nonce="' . wp_create_nonce( 'wpio_restore_single_' . $attachment_id ) . '"'
                    . ' style="margin-top:5px;color:#b32d2e;border-color:#b32d2e;">'
                    . '🔄 Restore Original</button>';
                echo '<span class="wpio-spinner" style="display:none;"> ⏳</span>';
            }

        } else {
            // Not yet converted
            if ( $has_backup ) {
                // Has backup but no converted file = was restored
                echo '<span class="wpio-badge wpio-restored">🔄 Using original</span><br>';
                echo '<button class="button button-small wpio-convert-btn" '
                    . 'data-id="' . esc_attr( $attachment_id ) . '" '
                    . 'data-nonce="' . wp_create_nonce( 'wpio_single_' . $attachment_id ) . '"'
                    . ' style="margin-top:5px;">'
                    . '⚡ Re-optimize</button>';
                echo '<span class="wpio-spinner" style="display:none;"> ⏳</span>';
            } else {
                echo '<span class="wpio-badge wpio-pending">Not converted</span><br>';
                echo '<button class="button button-small wpio-convert-btn" '
                    . 'data-id="' . esc_attr( $attachment_id ) . '" '
                    . 'data-nonce="' . wp_create_nonce( 'wpio_single_' . $attachment_id ) . '"'
                    . ' style="margin-top:5px;">'
                    . 'Convert</button>';
                echo '<span class="wpio-spinner" style="display:none;"> ⏳</span>';
            }
        }

        echo '</div>';
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'upload.php' ) return;

        wp_add_inline_style( 'media', '
            .wpio-col-wrap      { font-size:12px; line-height:1.5; }
            .wpio-badge         { display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:600; }
            .wpio-done          { background:#d4edda; color:#155724; }
            .wpio-pending       { background:#fff3cd; color:#856404; }
            .wpio-restored      { background:#fce8e8; color:#b32d2e; }
            .wpio-native        { background:#e8f0fe; color:#1a56db; }
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
        jQuery(document).ready(function($){

            // Convert / Re-optimize
            $(document).on("click", ".wpio-convert-btn", function(){
                var btn = $(this), id = btn.data("id"), nonce = btn.data("nonce");
                btn.prop("disabled", true); btn.next(".wpio-spinner").show();
                $.post(ajaxurl, { action: "wpio_convert_single", attachment_id: id, _wpnonce: nonce }, function(res){
                    if ( res.success ) {
                        btn.closest(".wpio-col-wrap").html(res.data.html);
                    } else {
                        btn.next(".wpio-spinner").hide();
                        btn.prop("disabled", false);
                        alert("Error: " + res.data);
                    }
                });
            });

            // Restore original
            $(document).on("click", ".wpio-restore-btn", function(){
                if ( ! confirm("Restore the original file? The converted version will be removed.") ) return;
                var btn = $(this), id = btn.data("id"), nonce = btn.data("nonce");
                btn.prop("disabled", true); btn.next(".wpio-spinner").show();
                $.post(ajaxurl, { action: "wpio_restore_single", attachment_id: id, _wpnonce: nonce }, function(res){
                    if ( res.success ) {
                        btn.closest(".wpio-col-wrap").html(res.data.html);
                    } else {
                        btn.next(".wpio-spinner").hide();
                        btn.prop("disabled", false);
                        alert("Error: " + res.data);
                    }
                });
            });

        });
        ' );
    }

    /* -- AJAX: convert single -------------------------------- */
    public function ajax_convert_single() {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! check_ajax_referer( 'wpio_single_' . $attachment_id, '_wpnonce', false ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $file    = get_attached_file( $attachment_id );
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = (int) get_option( 'wpio_quality', 82 );

        // Delete existing converted file so converter doesn't skip it.
        $conv = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $file );
        if ( file_exists( $conv ) ) @unlink( $conv );

        $result = WPIO_Converter::convert( $file, $format, $quality );
        WPIO_Stats::bust_cache();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'html' => $this->render_cell_html( $attachment_id ) ) );
    }

    /* -- AJAX: restore single -------------------------------- */
    public function ajax_restore_single() {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! check_ajax_referer( 'wpio_restore_single_' . $attachment_id, '_wpnonce', false ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $file   = get_attached_file( $attachment_id );
        $format = get_option( 'wpio_format', 'webp' );
        $conv   = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $file );

        // Restore original from backup.
        $result = WPIO_Backup::restore( $file );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Delete converted file so it isn't served anymore.
        if ( file_exists( $conv ) ) @unlink( $conv );

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
