=== W Image Converter ===
Contributors: wisnuub
Tags: webp, avif, image optimization, convert images, performance
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert JPG and PNG images to WebP or AVIF and serve them automatically — without changing image URLs or touching your originals.

== Description ==

W Image Converter makes your images smaller by creating a WebP or AVIF copy of each JPG and PNG, then serving that copy to browsers that support it. Image URLs never change, and your original files are never modified, so you can switch back at any time.

= How it works =

1. For every image, a converted copy is saved next to it: `photo.jpg` → `photo.jpg.webp`.
2. When a browser that supports WebP or AVIF asks for `photo.jpg`, it gets the smaller copy instead. Other browsers get the original.
3. If a converted copy isn't smaller than the original, it's discarded and the original keeps being served.

= Features =

* WebP, AVIF, or both (AVIF first, WebP as fallback)
* Bulk conversion of your existing library, running in small chunks with a background queue — gentle on shared hosting
* Automatic conversion of new uploads, including every thumbnail size
* Three delivery methods:
  * **Server rewrite** — `.htaccess` rules on Apache/LiteSpeed, or a ready-to-paste Nginx config
  * **HTML rewriting** — wraps images in `<picture>` elements; works on any host (WP Engine, Kinsta, behind a CDN). Responsive `srcset` and `sizes` are preserved, so phones still get small files.
  * **None** — convert only, and serve the files your own way
* **Test delivery** button that checks your server really serves the converted files
* Media Library column showing the format, size before and after, and one-click **Convert** / **Use original**
* Optional maximum width and height, EXIF stripping, and GD or Imagick selection
* Extra folders (themes, plugins or custom paths) and excluded folders
* Converted copies are removed automatically when you delete an image
* WP-CLI: `wp image-converter bulk`, `status`, `revert`

== Installation ==

1. Install from **Plugins → Add New**, or upload the plugin to `/wp-content/plugins/`.
2. Activate it.
3. Go to **Media → Image Converter**, choose WebP or AVIF, and click **Start Bulk Convert**.
4. On the **Delivery** tab, click **Test delivery** to confirm images are served in the new format.

== Frequently Asked Questions ==

= Are my original images changed? =

No. Converted copies are saved next to the originals. To serve an original again, click **Use original** in the Media Library (list view), or run `wp image-converter revert --id=<attachment_id>`.

= Which delivery method should I use? =

Use **Server rewrite** on Apache or LiteSpeed hosting. On Nginx, paste the two-part config from the Delivery tab. On managed hosts that don't allow server configuration, or when a CDN caches your images, use **HTML rewriting**.

= Does AVIF work on my server? =

AVIF needs PHP 8.1+ with GD built against libavif, or Imagick with AVIF support. The **System Status** tab shows what your server supports.

= Why are some images "kept as original"? =

Either the converted file wasn't smaller (common for small, already-optimized PNGs), or the image couldn't be converted (for example, it's too large for the PHP memory limit). Hover the badge in the Media Library to see the reason. Changing conversion settings retries them.

= Why aren't GIF files converted? =

Converting an animated GIF would keep only its first frame, so GIFs are left alone.

= How do I remove everything? =

On the **Help** tab, click **Delete converted files**, then deactivate and delete the plugin. Uninstalling removes the plugin's settings and server rules.

== Screenshots ==

1. Format choice, quality and bulk conversion
2. Delivery methods and the delivery test
3. Media Library column with size savings

== Changelog ==

= 1.5.0 =
* Renamed to W Image Converter.
* Removed the "backup originals" option. Originals were never modified, so backups only doubled disk usage. Existing backup folders can be deleted from the bulk panel.
* HTML rewriting now keeps responsive `srcset`/`sizes`, and no longer nests `<picture>` elements.
* Files that fail or don't get smaller are no longer retried on every bulk run, and progress now reaches 100%.
* Converted copies are deleted when the original image is deleted.
* A replaced original is converted again instead of serving the old copy.
* The "Skip if larger" setting is now respected.
* New: Test delivery, Delete converted files, Use original.
* Nginx config split into its http {} and server {} parts (map {} is not allowed inside server {}).
* Removed GIF conversion (lost animation) and the unfinished remote-server option.
* WP-CLI command renamed to `wp image-converter`.

= 1.4 =
* Fixed crash loops on very large images, wrong images being served, and rotated photos.
