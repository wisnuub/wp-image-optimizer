# WP Image Optimizer

> **v1.2** — Convert & serve next-gen images (WebP & AVIF) automatically, without breaking any existing site links.

A free, lightweight WordPress plugin to convert and compress existing images (JPG/PNG) to **WebP** or **AVIF** — without breaking any existing site links.

## How It Works

Instead of replacing image files, the plugin:

1. Generates a `.webp` (or `.avif`) copy of each image alongside the original.
2. Injects `.htaccess` rewrite rules in the uploads folder:
   - If a browser supports WebP/AVIF (via the `Accept` header), requests to `.jpg` / `.png` URLs are transparently served the converted file.
   - If the browser doesn't support it, the original file is served — no broken links, ever.
3. Optionally auto-converts images on upload.

## Features

- ✅ Convert existing uploads (bulk) or on-upload automatically
- ✅ Choose WebP or AVIF output
- ✅ Configurable quality (1–100)
- ✅ Zero broken links — original URLs kept, served via `.htaccess` rewrite
- ✅ Works with GD or Imagick, with manual method selector
- ✅ Supported file extensions selector (JPG, PNG, GIF)
- ✅ Excluded directories with live preview
- ✅ Extra features: Strip EXIF, Remove if larger
- ✅ Expandable file tree with per-folder image counts
- ✅ Nginx config generator
- ✅ WP-CLI support
- ✅ Clean admin UI under **Media → Image Optimizer**

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Apache with `mod_rewrite` enabled (for the redirect magic)
- GD with WebP/AVIF support **or** Imagick

## Installation

1. Clone or download this repo
2. Upload the folder to `/wp-content/plugins/`
3. Activate in **Plugins**
4. Go to **Media → Image Optimizer** and configure

## Changelog

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

- [x] Nginx rewrite rule support
- [x] Per-image conversion status in Media Library
- [x] WP-CLI bulk conversion command
- [x] Image backup/restore before conversion
- [x] Conversion method selector (Imagick / GD / Auto)
- [x] Excluded directories
- [x] File tree with per-folder image counts
- [ ] 🚧 Server-side image optimization *(coming soon)*
- [ ] REST API endpoint for headless WordPress use

## License

GPL-2.0+
