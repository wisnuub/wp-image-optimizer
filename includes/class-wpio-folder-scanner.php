<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIO_Folder_Scanner {

    /**
     * JPG and PNG only: the delivery rules only serve those, and converting an
     * animated GIF would keep just its first frame.
     */
    public static function get_allowed_extensions() {
        $exts = array();
        if ( get_option( 'wpio_ext_jpg', '1' ) === '1' ) { $exts[] = 'jpg'; $exts[] = 'jpeg'; }
        if ( get_option( 'wpio_ext_png', '1' ) === '1' ) { $exts[] = 'png'; }
        return ! empty( $exts ) ? $exts : array( 'jpg', 'jpeg', 'png' );
    }

    public static function get_excluded_dirs() {
        $raw   = get_option( 'wpio_excluded_dirs', '' );
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $parts[] = 'wpio-backups';
        return array_unique( $parts );
    }

    public static function is_excluded_path( $path ) {
        return self::is_excluded( $path );
    }

    private static function is_excluded( $path ) {
        foreach ( self::get_excluded_dirs() as $fragment ) {
            if ( $fragment !== '' && strpos( $path, $fragment ) !== false ) return true;
        }
        return false;
    }

    /**
     * Returns the resolved ABSPATH boundary used to validate custom folders.
     */
    private static function abspath_real() {
        static $abspath = null;
        if ( $abspath === null ) {
            $abspath = realpath( ABSPATH );
        }
        return $abspath;
    }

    public static function get_folders() {
        $upload_dir  = wp_upload_dir();
        $content_dir = WP_CONTENT_DIR;
        $folders     = array();

        if ( get_option( 'wpio_scan_uploads', '1' ) === '1' ) {
            $folders[] = $upload_dir['basedir'];
        }
        if ( get_option( 'wpio_scan_plugins', '0' ) === '1' ) {
            $d = $content_dir . '/plugins';
            if ( is_dir( $d ) ) $folders[] = $d;
        }
        if ( get_option( 'wpio_scan_themes', '0' ) === '1' ) {
            $d = $content_dir . '/themes';
            if ( is_dir( $d ) ) $folders[] = $d;
        }

        $custom_raw = get_option( 'wpio_custom_folders', '' );
        if ( ! empty( $custom_raw ) ) {
            $abspath_real = self::abspath_real();
            foreach ( array_filter( array_map( 'trim', explode( "\n", $custom_raw ) ) ) as $line ) {
                if ( ! path_is_absolute( $line ) ) $line = rtrim( ABSPATH, '/' ) . '/' . ltrim( $line, '/' );
                $real = realpath( $line );
                // Security: only allow paths within ABSPATH to prevent path traversal.
                if (
                    $real &&
                    is_dir( $real ) &&
                    $abspath_real &&
                    strpos( $real . DIRECTORY_SEPARATOR, $abspath_real . DIRECTORY_SEPARATOR ) === 0 &&
                    ! in_array( $real, $folders )
                ) {
                    $folders[] = $real;
                }
            }
        }

        if ( empty( $folders ) ) $folders[] = $upload_dir['basedir'];
        return $folders;
    }

    /**
     * Returns per-source image counts including thumbnail breakdown.
     * Result shape:
     *   [ 'uploads' => ['total'=>N,'thumbs'=>N], 'plugins'=>..., 'themes'=>... ]
     */
    public static function get_counts_by_source() {
        $upload_dir  = wp_upload_dir();
        $content_dir = WP_CONTENT_DIR;
        $allowed     = self::get_allowed_extensions();

        $sources = array();

        if ( get_option( 'wpio_scan_uploads', '1' ) === '1' ) {
            $dir = $upload_dir['basedir'];
            if ( is_dir( $dir ) ) $sources['uploads'] = $dir;
        }
        if ( get_option( 'wpio_scan_plugins', '0' ) === '1' ) {
            $dir = $content_dir . '/plugins';
            if ( is_dir( $dir ) ) $sources['plugins'] = $dir;
        }
        if ( get_option( 'wpio_scan_themes', '0' ) === '1' ) {
            $dir = $content_dir . '/themes';
            if ( is_dir( $dir ) ) $sources['themes'] = $dir;
        }

        $result = array();
        foreach ( $sources as $label => $dir ) {
            $total  = 0;
            $thumbs = 0;
            $iter   = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iter as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( self::is_excluded( $path ) ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, $allowed ) ) continue;
                $total++;
                // WP thumbnail pattern: filename-NNNxNNN.ext
                if ( preg_match( '/-\d+x\d+\.(?:jpe?g|png)$/i', $file->getBasename() ) ) {
                    $thumbs++;
                }
            }
            $result[ $label ] = array( 'total' => $total, 'thumbs' => $thumbs );
        }

        return $result;
    }

    /**
     * Whether every target format has an up-to-date converted copy.
     */
    private static function has_all_conversions( $path, $formats ) {
        foreach ( $formats as $fmt ) {
            if ( ! WPIO_Converter::is_converted( $path, $fmt ) ) return false;
        }
        return true;
    }

    /**
     * Images still needing conversion — excluding ones already skipped
     * (failed, or the result was larger) unless they changed since.
     */
    public static function get_pending_images( $format = 'webp' ) {
        $formats = WPIO_Converter::get_formats( $format );
        $files   = array();
        $allowed = self::get_allowed_extensions();
        $skipped = get_option( WPIO_Converter::SKIP_OPTION, array() );

        foreach ( self::get_folders() as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( self::is_excluded( $path ) ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, $allowed ) ) continue;
                if ( self::has_all_conversions( $path, $formats ) ) continue;
                if ( $skipped && WPIO_Converter::is_skipped( $path, $skipped ) ) continue;
                $files[] = $path;
            }
        }

        return array_unique( $files );
    }

    public static function get_counts( $format = 'webp' ) {
        $formats   = WPIO_Converter::get_formats( $format );
        $total     = 0;
        $converted = 0;
        $skipped   = 0;
        $allowed   = self::get_allowed_extensions();
        $skip_list = get_option( WPIO_Converter::SKIP_OPTION, array() );

        foreach ( self::get_folders() as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( self::is_excluded( $path ) ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, $allowed ) ) continue;
                $total++;
                if ( self::has_all_conversions( $path, $formats ) ) {
                    $converted++;
                } elseif ( $skip_list && WPIO_Converter::is_skipped( $path, $skip_list ) ) {
                    $skipped++;
                }
            }
        }

        return array( 'total' => $total, 'converted' => $converted, 'skipped' => $skipped, 'pending' => $total - $converted - $skipped );
    }
}
