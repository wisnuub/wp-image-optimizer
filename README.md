# W Image Converter

> **v1.5.0** — Convert JPG/PNG images to WebP or AVIF and serve them automatically, without changing image URLs or touching your originals. (Formerly "WP Image Optimizer".)

## How It Works

1. A converted copy is saved next to each image: `photo.jpg` → `photo.jpg.webp`. Originals are never modified.
2. Browsers that support WebP/AVIF get the smaller copy under the same URL; others get the original.
3. Copies that aren't smaller than the original are discarded, and the original is kept.

Delivery options: Apache/LiteSpeed `.htaccess` rules, a two-part Nginx config, or HTML rewriting to `<picture>` (keeps responsive `srcset`/`sizes`; works on managed hosts and behind CDNs).

## Features

- Bulk conversion with a chunked background queue, plus auto-convert on upload (all thumbnail sizes)
- WebP, AVIF, or both
- **Test delivery** button that confirms the server really serves the converted file
- Media Library column with before/after size and **Convert** / **Use original**
- Files that fail or don't shrink are remembered, so bulk runs reach 100% and don't retry them forever
- Converted copies are deleted with the original; replaced originals get reconverted
- GD or Imagick, optional max dimensions, EXIF stripping, extra and excluded folders, file tree
- WP-CLI: `wp image-converter bulk | status | revert --id=<id>`

## Requirements

- WordPress 5.8+, PHP 7.4+ (AVIF needs PHP 8.1+ with libavif, or Imagick with AVIF)
- GD with WebP/AVIF support **or** Imagick

## Installation

1. Download the release zip (or copy the plugin files into `/wp-content/plugins/w-image-converter/`)
2. Activate in **Plugins**
3. Go to **Media → Image Converter**, run **Start Bulk Convert**, then **Test delivery** on the Delivery tab

## Changelog

### v1.5.0
- Renamed to W Image Converter (WordPress.org doesn't allow names starting with "WP")
- Removed the backup option: originals were never modified, so backups only doubled disk usage. Old backup folders can be deleted from the bulk panel
- HTML rewriting keeps responsive `srcset`/`sizes` and no longer nests `<picture>`
- Skipped/failed files are remembered; progress reaches 100%
- Converted copies deleted with the original; replaced originals reconverted
- "Skip if larger" setting is respected
- New: Test delivery, Delete converted files, Use original
- Nginx snippet split into http {} and server {} parts (`map` isn't valid in `server`)
- Removed GIF conversion (lost animation) and the unfinished remote server option
- WP-CLI command renamed to `wp image-converter`
- WordPress.org readme, uninstall cleanup, Plugin Check clean

### v1.4
- **Changed:** converted files keep the original extension (`photo.jpg` → `photo.jpg.webp`). Previously `photo.jpg` and `photo.png` both mapped to `photo.webp`, and a native `photo.webp` upload was treated as the converted copy and served in place of `photo.jpg`. Files converted by v1.3 are left in place unused; run Bulk Convert again to regenerate them.
- Fixed: 8-bit (palette) PNG/GIF caused a PHP fatal error in GD's WebP encoder; they are now converted to truecolor with transparency preserved
- Fixed: a file that crashed the process (out of memory / timeout) was retried every 30s forever — the queue is now saved before each file is converted
- Fixed: GD now refuses images that won't fit in the PHP memory limit instead of crashing
- Fixed: "Raise limits" could *lower* a host's higher memory limit to 256M
- Fixed: phone photos came out rotated/mirrored because EXIF orientation was dropped without being applied
- Fixed: auto-convert on upload ran before WordPress created thumbnails, so only the full-size original was converted; now converts every generated size
- Fixed: `Vary: Accept` was added to every response (including HTML pages); now limited to images
- Rewrite condition uses `%{REQUEST_FILENAME}` instead of `%{DOCUMENT_ROOT}` so it works when the document root differs from the WP root
- Removed duplicate backup call in the queue (the converter already backs up)
- Fixed: `.htaccess` rules could not be written from WP-Cron, REST or WP-CLI (fatal: undefined `insert_with_markers()`), which broke the daily auto-upgrade of stale rules
- WP-CLI: `bulk` gains `--folder=<path>` and `--limit=<n>`

### v1.3
- Fixed: Start Bulk Convert button was submitting the settings form instead of triggering bulk conversion (missing `type="button"` attribute)

### v1.2
- Added conversion method selector (Auto / Imagick / GD) with live availability indicators
- Added supported file extensions toggle (JPG, PNG, GIF)
- Added excluded directories with live fragment preview
- Added extra features: Strip EXIF metadata, Remove if larger than original
- Added expandable file tree with per-folder total / converted / pending counts
- Added `class-wpio-folder-tree.php` — recursive folder tree builder

### v1.1
- Initial public release
- Bulk & auto-convert on upload
- WebP & AVIF support
- Background queue via WP-Cron
- Image backup & restore
- Nginx config generator
- WP-CLI commands
- Media Library column with per-image status

## Roadmap

- [ ] REST API endpoint for headless WordPress use
- [ ] Optional lossless mode for PNG

## License

GPL-2.0+
