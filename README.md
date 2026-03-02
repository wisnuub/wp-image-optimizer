# WP Image Optimizer

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
- ✅ Works with GD or Imagick
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

## Roadmap

- [ ] Nginx rewrite rule support
- [ ] Per-image conversion status in Media Library
- [ ] WP-CLI bulk conversion command
- [ ] REST API endpoint for headless WordPress use
- [ ] Image backup/restore before conversion

## License

GPL-2.0+
