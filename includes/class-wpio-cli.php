<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI command: wp image-optimizer
 * Usage:
 *   wp image-optimizer bulk [--format=webp] [--quality=82] [--dry-run]
 *   wp image-optimizer status
 *   wp image-optimizer restore [--id=<attachment_id>]
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;

class WPIO_CLI extends WP_CLI_Command {

    /**
     * Bulk convert all JPG/PNG images in configured folders.
     *
     * Respects the plugin's folder settings, exclusions, and custom folder list
     * — identical to what the admin bulk converter processes.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. webp or avif. Default: webp
     *
     * [--quality=<quality>]
     * : Compression quality 1-100. Default: 82
     *
     * [--dry-run]
     * : Preview what would be converted without actually converting.
     *
     * ## EXAMPLES
     *
     *   wp image-optimizer bulk
     *   wp image-optimizer bulk --format=avif --quality=75
     *   wp image-optimizer bulk --dry-run
     *
     * @when after_wp_load
     */
    public function bulk( $args, $assoc_args ) {
        $format  = isset( $assoc_args['format'] )  ? sanitize_key( $assoc_args['format'] )  : get_option( 'wpio_format', 'webp' );
        $quality = isset( $assoc_args['quality'] ) ? absint( $assoc_args['quality'] )        : (int) get_option( 'wpio_quality', 82 );
        $dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

        if ( ! in_array( $format, array( 'webp', 'avif' ) ) ) {
            WP_CLI::error( 'Invalid format. Use webp or avif.' );
        }

        // Use the shared scanner so exclusions and custom folders are respected.
        $files = WPIO_Folder_Scanner::get_pending_images( $format );
        $total = count( $files );
        WP_CLI::log( sprintf( 'Found %d image(s) to process (via configured folders).', $total ) );

        if ( $dry_run ) {
            WP_CLI::success( 'Dry run complete. No files were converted.' );
            return;
        }

        $progress  = \WP_CLI\Utils\make_progress_bar( 'Converting images', $total );
        $converted = 0;
        $skipped   = 0;
        $errors    = array();

        foreach ( $files as $file ) {
            $result = WPIO_Converter::convert( $file, $format, $quality );
            if ( is_wp_error( $result ) ) {
                if ( $result->get_error_code() === 'file_not_found' ) {
                    $skipped++;
                } else {
                    $errors[] = basename( $file ) . ': ' . $result->get_error_message();
                }
            } else {
                $converted++;
            }
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success( sprintf( 'Done. Converted: %d | Skipped: %d | Errors: %d', $converted, $skipped, count( $errors ) ) );

        if ( ! empty( $errors ) ) {
            foreach ( $errors as $err ) {
                WP_CLI::warning( $err );
            }
        }
    }

    /**
     * Show conversion status summary.
     *
     * Uses the plugin's configured folders and exclusions for accurate counts.
     *
     * ## EXAMPLES
     *
     *   wp image-optimizer status
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {
        $format = get_option( 'wpio_format', 'webp' );

        // Use the shared scanner so counts match what the admin UI shows.
        $counts      = WPIO_Folder_Scanner::get_counts( $format );
        $total       = $counts['total'];
        $done        = $counts['converted'];
        $pending     = $counts['pending'];

        // Calculate saved bytes by iterating over converted files.
        $saved_bytes = 0;
        foreach ( WPIO_Folder_Scanner::get_folders() as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;
                $conv = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $file->getPathname() );
                if ( file_exists( $conv ) ) {
                    $saved_bytes += max( 0, $file->getSize() - filesize( $conv ) );
                }
            }
        }

        $saved_kb = round( $saved_bytes / 1024, 1 );
        $saved_mb = round( $saved_bytes / 1048576, 2 );

        WP_CLI\Utils\format_items( 'table', array(
            array( 'Metric' => 'Format',        'Value' => strtoupper( $format ) ),
            array( 'Metric' => 'Total images',  'Value' => $total ),
            array( 'Metric' => 'Converted',     'Value' => $done ),
            array( 'Metric' => 'Pending',       'Value' => $pending ),
            array( 'Metric' => 'Total saved',   'Value' => $saved_kb . ' KB (' . $saved_mb . ' MB)' ),
        ), array( 'Metric', 'Value' ) );
    }

    /**
     * Restore original image from backup for a specific attachment.
     *
     * ## OPTIONS
     *
     * [--id=<attachment_id>]
     * : Attachment ID to restore. If omitted, restores all.
     *
     * ## EXAMPLES
     *
     *   wp image-optimizer restore --id=42
     *
     * @when after_wp_load
     */
    public function restore( $args, $assoc_args ) {
        $id = isset( $assoc_args['id'] ) ? absint( $assoc_args['id'] ) : null;
        if ( $id ) {
            $file   = get_attached_file( $id );
            $result = WPIO_Backup::restore( $file );
            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            } else {
                WP_CLI::success( 'Restored: ' . basename( $file ) );
            }
        } else {
            WP_CLI::error( 'Please provide --id=<attachment_id>. Bulk restore via CLI is not yet supported.' );
        }
    }
}

WP_CLI::add_command( 'image-optimizer', 'WPIO_CLI' );
