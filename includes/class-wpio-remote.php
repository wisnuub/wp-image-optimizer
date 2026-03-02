<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Remote server image conversion.
 *
 * Sends the image to a remote REST endpoint for conversion
 * and downloads the result back. Falls back to local conversion
 * if remote is unavailable or returns an error.
 *
 * The remote endpoint should accept:
 *   POST /convert
 *   Body: multipart/form-data { file: <binary>, format: webp|avif, quality: int }
 *   Response: binary image data with correct Content-Type
 *
 * You can self-host the endpoint using the bundled Node.js server
 * (see /remote-server/README.md) or point to any compatible API.
 */
class WPIO_Remote {

    /**
     * Convert an image via remote server.
     *
     * @param string $source_path  Absolute path to source image.
     * @param string $format       'webp' or 'avif'.
     * @param int    $quality      1-100.
     * @return string|WP_Error     Destination path or WP_Error.
     */
    public static function convert( $source_path, $format = 'webp', $quality = 82 ) {
        $endpoint = trailingslashit( get_option( 'wpio_remote_url', '' ) ) . 'convert';
        if ( empty( get_option( 'wpio_remote_url', '' ) ) ) {
            return new WP_Error( 'no_remote_url', 'Remote server URL is not configured.' );
        }

        if ( ! file_exists( $source_path ) ) {
            return new WP_Error( 'file_not_found', 'Source file not found.' );
        }

        $info      = pathinfo( $source_path );
        $dest_path = $info['dirname'] . '/' . $info['filename'] . '.' . $format;

        if ( file_exists( $dest_path ) ) return $dest_path;

        // Read file and encode as base64 for JSON transport
        $file_data = base64_encode( file_get_contents( $source_path ) );
        $mime      = mime_content_type( $source_path );

        $response = wp_remote_post( $endpoint, array(
            'timeout'  => 60,
            'headers'  => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . get_option( 'wpio_remote_token', '' ),
                'X-WPIO-Site'   => get_bloginfo( 'url' ),
            ),
            'body' => wp_json_encode( array(
                'file'    => $file_data,
                'mime'    => $mime,
                'format'  => $format,
                'quality' => $quality,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'remote_request_failed', 'Remote request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $json = json_decode( $body, true );
            $msg  = isset( $json['error'] ) ? $json['error'] : 'HTTP ' . $code;
            return new WP_Error( 'remote_error', 'Remote server error: ' . $msg );
        }

        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( ! isset( $json['image'] ) ) {
            return new WP_Error( 'remote_bad_response', 'Remote server returned unexpected response.' );
        }

        $decoded = base64_decode( $json['image'] );
        if ( ! $decoded || strlen( $decoded ) < 100 ) {
            return new WP_Error( 'remote_bad_image', 'Remote server returned invalid image data.' );
        }

        if ( file_put_contents( $dest_path, $decoded ) === false ) {
            return new WP_Error( 'write_failed', 'Could not write converted file to disk.' );
        }

        return $dest_path;
    }

    /**
     * Test connectivity to the remote server.
     *
     * @return true|WP_Error
     */
    public static function test_connection() {
        $url = trailingslashit( get_option( 'wpio_remote_url', '' ) ) . 'ping';
        if ( empty( get_option( 'wpio_remote_url', '' ) ) ) {
            return new WP_Error( 'no_url', 'No remote URL configured.' );
        }
        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . get_option( 'wpio_remote_token', '' ),
            ),
        ) );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) return true;
        return new WP_Error( 'ping_failed', 'Remote server returned HTTP ' . $code );
    }

    /**
     * Whether remote conversion is configured and enabled.
     *
     * @return bool
     */
    public static function is_enabled() {
        return get_option( 'wpio_use_remote', '0' ) === '1' &&
               ! empty( get_option( 'wpio_remote_url', '' ) );
    }
}
