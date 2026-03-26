<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds a recursive folder tree with image counts per directory.
 * Used by the Advanced tab file tree UI.
 */
class WPIO_Folder_Tree {

    /**
     * Build tree data for all configured scan folders.
     *
     * @param string $format  Target format to check conversion against.
     * @return array  Array of root-level tree nodes.
     */
    public static function build( $format = 'webp' ) {
        $roots   = WPIO_Folder_Scanner::get_folders();
        $allowed = WPIO_Folder_Scanner::get_allowed_extensions();
        $tree    = array();

        foreach ( $roots as $root ) {
            if ( ! is_dir( $root ) ) continue;
            $tree[] = self::scan_dir( $root, $root, $format, $allowed );
        }

        return $tree;
    }

    /**
     * Recursively scan a directory and return a node with counts + children.
     */
    private static function scan_dir( $dir, $root, $format, $allowed ) {
        $node = array(
            'path'      => $dir,
            'name'      => $dir === $root ? self::short_path( $dir ) : basename( $dir ),
            'is_root'   => $dir === $root,
            'total'     => 0,
            'converted' => 0,
            'pending'   => 0,
            'children'  => array(),
        );

        $items = @scandir( $dir );
        if ( ! $items ) return $node;

        $subdirs = array();
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $full = $dir . DIRECTORY_SEPARATOR . $item;

            if ( is_dir( $full ) ) {
                // Skip excluded dirs
                if ( WPIO_Folder_Scanner::is_excluded_path( $full ) ) continue;
                $subdirs[] = $full;
                continue;
            }

            $ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, $allowed ) ) continue;
            if ( WPIO_Folder_Scanner::is_excluded_path( $full ) ) continue;

            $node['total']++;
            $conv = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $full );
            if ( file_exists( $conv ) ) $node['converted']++;
        }

        // Recurse into subdirs
        foreach ( $subdirs as $subdir ) {
            $child = self::scan_dir( $subdir, $root, $format, $allowed );
            // Bubble counts up
            $node['total']     += $child['total'];
            $node['converted'] += $child['converted'];
            if ( $child['total'] > 0 || ! empty( $child['children'] ) ) {
                $node['children'][] = $child;
            }
        }

        $node['pending'] = $node['total'] - $node['converted'];
        return $node;
    }

    /**
     * Shorten an absolute path for display (strip ABSPATH prefix).
     */
    private static function short_path( $path ) {
        $base = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
        if ( strpos( $path, $base ) === 0 ) {
            return '.' . str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $base ) ) );
        }
        return $path;
    }
}
