<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI command: wp image-converter
 * Usage:
 *   wp image-converter bulk [--format=webp] [--quality=82] [--dry-run]
 *   wp image-converter status
 *   wp image-converter revert --id=<attachment_id>
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
     * [--folder=<path>]
     * : Only process images under this folder, relative to uploads (e.g. 2026/07).
     *
     * [--limit=<n>]
     * : Stop after attempting this many images. Useful on hosts with short request timeouts.
     *
     * ## EXAMPLES
     *
     *   wp image-converter bulk
     *   wp image-converter bulk --format=avif --quality=75
     *   wp image-converter bulk --dry-run
     *   wp image-converter bulk --folder=2026/07 --limit=50
     *
     * @when after_wp_load
     */
    public function bulk( $args, $assoc_args ) {
        $format  = isset( $assoc_args['format'] )  ? sanitize_key( $assoc_args['format'] )  : get_option( 'wpio_format', 'webp' );
        $quality = isset( $assoc_args['quality'] ) ? absint( $assoc_args['quality'] )        : (int) get_option( 'wpio_quality', 82 );
        $dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

        if ( ! in_array( $format, array( 'webp', 'avif', 'both' ) ) ) {
            WP_CLI::error( 'Invalid format. Use webp, avif, or both.' );
        }

        // Use the shared scanner so exclusions and custom folders are respected.
        $files = WPIO_Folder_Scanner::get_pending_images( $format );
        sort( $files );

        if ( ! empty( $assoc_args['folder'] ) ) {
            $upload_dir = wp_upload_dir();
            $prefix     = trailingslashit( $upload_dir['basedir'] ) . trim( $assoc_args['folder'], '/' ) . '/';
            $files      = array_values( array_filter( $files, function( $f ) use ( $prefix ) {
                return strpos( $f, $prefix ) === 0;
            } ) );
        }

        $found = count( $files );
        if ( ! empty( $assoc_args['limit'] ) ) {
            $files = array_slice( $files, 0, absint( $assoc_args['limit'] ) );
        }

        $total = count( $files );
        WP_CLI::log( sprintf( 'Found %d image(s) pending, processing %d.', $found, $total ) );

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
                if ( in_array( $result->get_error_code(), array( 'file_not_found', 'output_larger' ), true ) ) {
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
     *   wp image-converter status
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {
        $format  = get_option( 'wpio_format', 'webp' );
        $formats = WPIO_Converter::get_formats( $format );

        // Use the shared scanner so counts match what the admin UI shows.
        $counts      = WPIO_Folder_Scanner::get_counts( $format );
        $total       = $counts['total'];
        $done        = $counts['converted'];
        $pending     = $counts['pending'];

        // Calculate saved bytes by iterating over converted files.
        // For 'both', use the smallest converted file for savings.
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
                $best = PHP_INT_MAX;
                $found = false;
                foreach ( $formats as $fmt ) {
                    $conv = WPIO_Converter::converted_path( $file->getPathname(), $fmt );
                    if ( file_exists( $conv ) ) {
                        $found = true;
                        $sz = filesize( $conv );
                        if ( $sz < $best ) $best = $sz;
                    }
                }
                if ( $found ) {
                    $saved_bytes += max( 0, $file->getSize() - $best );
                }
            }
        }

        $saved_kb = round( $saved_bytes / 1024, 1 );
        $saved_mb = round( $saved_bytes / 1048576, 2 );

        WP_CLI\Utils\format_items( 'table', array(
            array( 'Metric' => 'Format',        'Value' => strtoupper( $format ) ),
            array( 'Metric' => 'Total images',  'Value' => $total ),
            array( 'Metric' => 'Converted',     'Value' => $done ),
            array( 'Metric' => 'Original kept', 'Value' => $counts['skipped'] ),
            array( 'Metric' => 'Pending',       'Value' => $pending ),
            array( 'Metric' => 'Total saved',   'Value' => $saved_kb . ' KB (' . $saved_mb . ' MB)' ),
        ), array( 'Metric', 'Value' ) );
    }

    /**
     * Remove the converted copies of an attachment so the original is served.
     *
     * ## OPTIONS
     *
     * --id=<attachment_id>
     * : Attachment ID.
     *
     * ## EXAMPLES
     *
     *   wp image-converter revert --id=42
     *
     * @when after_wp_load
     */
    public function revert( $args, $assoc_args ) {
        $id   = isset( $assoc_args['id'] ) ? absint( $assoc_args['id'] ) : 0;
        $file = $id ? get_attached_file( $id ) : '';
        if ( ! $file ) {
            WP_CLI::error( 'Please provide a valid --id=<attachment_id>.' );
        }

        $files = array( $file );
        $meta  = wp_get_attachment_metadata( $id );
        if ( is_array( $meta ) ) {
            foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
                if ( ! empty( $size['file'] ) ) $files[] = dirname( $file ) . '/' . $size['file'];
            }
        }
        foreach ( $files as $path ) {
            WPIO_Converter::delete_converted( $path );
        }
        WPIO_Stats::bust_cache();
        WP_CLI::success( 'Now serving the original: ' . basename( $file ) );
    }
}

WP_CLI::add_command( 'image-converter', 'WPIO_CLI' );
