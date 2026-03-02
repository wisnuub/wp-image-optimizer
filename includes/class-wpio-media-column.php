<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds a conversion status column to the Media Library list view.
 * Shows WebP/AVIF badge, file size savings, and a per-image convert button.
 */
class WPIO_Media_Column {

    public function __construct() {
        add_filter( 'manage_media_columns',          array( $this, 'add_column' ) );
        add_action( 'manage_media_custom_column',    array( $this, 'render_column' ), 10, 2 );
        add_action( 'admin_enqueue_scripts',         array( $this, 'enqueue_styles' ) );
        add_action( 'wp_ajax_wpio_convert_single',   array( $this, 'ajax_convert_single' ) );
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
        if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) {
            echo '<span class="wpio-na">—</span>';
            return;
        }

        $format       = get_option( 'wpio_format', 'webp' );
        $converted    = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $file );
        $is_converted = file_exists( $converted );

        if ( $is_converted ) {
            $orig_size  = filesize( $file );
            $conv_size  = filesize( $converted );
            $saving_pct = $orig_size > 0 ? round( ( 1 - $conv_size / $orig_size ) * 100 ) : 0;
            $saving_kb  = round( ( $orig_size - $conv_size ) / 1024, 1 );

            echo '<span class="wpio-badge wpio-done">' . strtoupper( $format ) . ' ✓</span>';
            if ( $saving_pct > 0 ) {
                echo '<br><small class="wpio-saving">↓ ' . esc_html( $saving_pct ) . '% (' . esc_html( $saving_kb ) . ' KB)</small>';
            }
        } else {
            echo '<span class="wpio-badge wpio-pending">Not converted</span><br>';
            echo '<button class="button button-small wpio-convert-btn" data-id="' . esc_attr( $attachment_id ) . '" data-nonce="' . wp_create_nonce( 'wpio_single_' . $attachment_id ) . '">';
            echo esc_html__( 'Convert', 'wp-image-optimizer' );
            echo '</button>';
            echo '<span class="wpio-spinner" style="display:none;"> ⏳</span>';
        }
    }

    public function enqueue_styles( $hook ) {
        if ( $hook !== 'upload.php' ) return;
        wp_add_inline_style( 'media', '
            .wpio-badge { display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:600; }
            .wpio-done  { background:#d4edda; color:#155724; }
            .wpio-pending { background:#fff3cd; color:#856404; }
            .wpio-na    { color:#999; }
            .wpio-saving { color:#155724; }
        ' );
        wp_add_inline_script( 'jquery', '
            jQuery(document).ready(function($){
                $(document).on("click", ".wpio-convert-btn", function(){
                    var btn     = $(this);
                    var spinner = btn.next(".wpio-spinner");
                    var id      = btn.data("id");
                    var nonce   = btn.data("nonce");
                    btn.prop("disabled", true);
                    spinner.show();
                    $.post(ajaxurl, { action: "wpio_convert_single", attachment_id: id, _wpnonce: nonce }, function(res){
                        if ( res.success ) {
                            btn.closest("td").html(res.data.html);
                        } else {
                            spinner.hide();
                            btn.prop("disabled", false);
                            alert("Error: " + res.data);
                        }
                    });
                });
            });
        ' );
    }

    public function ajax_convert_single() {
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! check_ajax_referer( 'wpio_single_' . $attachment_id, '_wpnonce', false ) || ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $file    = get_attached_file( $attachment_id );
        $format  = get_option( 'wpio_format', 'webp' );
        $quality = (int) get_option( 'wpio_quality', 82 );
        $result  = WPIO_Converter::convert( $file, $format, $quality );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $orig_size  = filesize( $file );
        $conv_size  = filesize( $result );
        $saving_pct = $orig_size > 0 ? round( ( 1 - $conv_size / $orig_size ) * 100 ) : 0;
        $saving_kb  = round( ( $orig_size - $conv_size ) / 1024, 1 );

        $html  = '<span class="wpio-badge wpio-done">' . strtoupper( $format ) . ' ✓</span>';
        if ( $saving_pct > 0 ) {
            $html .= '<br><small class="wpio-saving">↓ ' . $saving_pct . '% (' . $saving_kb . ' KB)</small>';
        }

        wp_send_json_success( array( 'html' => $html ) );
    }
}
