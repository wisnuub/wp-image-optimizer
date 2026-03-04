<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIO_Stats {

    const CACHE_KEY    = 'wpio_stats_cache';
    const CACHE_EXPIRE = 300;

    public static function get() {
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) return $cached;
        return self::compute();
    }

    public static function compute() {
        $format      = get_option( 'wpio_format', 'webp' );
        $folders     = WPIO_Folder_Scanner::get_folders();
        $total       = 0;
        $converted   = 0;
        $restored    = 0;
        $orig_bytes  = 0;
        $conv_bytes  = 0;
        $largest     = array( 'file' => '', 'saved' => 0, 'pct' => 0 );

        foreach ( $folders as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iter as $file ) {
                if ( $file->isDir() ) continue;
                $path = $file->getPathname();
                if ( strpos( $path, 'wpio-backups' ) !== false ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ) ) ) continue;
                $total++;
                $orig_size   = $file->getSize();
                $orig_bytes += $orig_size;
                $conv_path   = preg_replace( '/\.(jpe?g|png)$/i', '.' . $format, $path );

                if ( file_exists( $conv_path ) ) {
                    $converted++;
                    $c_size      = filesize( $conv_path );
                    $conv_bytes += $c_size;
                    $saved       = $orig_size - $c_size;
                    if ( $saved > $largest['saved'] && $orig_size > 0 ) {
                        $largest = array(
                            'file'  => $file->getFilename(),
                            'saved' => $saved,
                            'pct'   => round( ( $saved / $orig_size ) * 100 ),
                        );
                    }
                } else {
                    $conv_bytes += $orig_size;
                    // Count as "restored" if a backup exists (was once converted, now reverted).
                    if ( WPIO_Backup::has_backup( $path ) ) {
                        $restored++;
                    }
                }
            }
        }

        $saved_bytes = max( 0, $orig_bytes - $conv_bytes );
        $backup_size = WPIO_Backup::total_backup_size();
        $stats = array(
            'format'       => strtoupper( $format ),
            'total'        => $total,
            'converted'    => $converted,
            'pending'      => $total - $converted,
            'restored'     => $restored,
            'orig_bytes'   => $orig_bytes,
            'saved_bytes'  => $saved_bytes,
            'saved_kb'     => round( $saved_bytes / 1024, 1 ),
            'saved_mb'     => round( $saved_bytes / 1048576, 2 ),
            'saving_pct'   => $orig_bytes > 0 ? round( ( $saved_bytes / $orig_bytes ) * 100, 1 ) : 0,
            'largest_save' => $largest,
            'backup_bytes' => $backup_size,
            'backup_mb'    => round( $backup_size / 1048576, 2 ),
            'progress_pct' => $total > 0 ? round( ( $converted / $total ) * 100 ) : 0,
            'folders'      => WPIO_Folder_Scanner::get_folders(),
        );

        set_transient( self::CACHE_KEY, $stats, self::CACHE_EXPIRE );
        return $stats;
    }

    public static function bust_cache() {
        delete_transient( self::CACHE_KEY );
    }
}
