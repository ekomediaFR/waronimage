=== War on Image Connect ===
Contributors: waronimage
Tags: images, seo, media library, alt text
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Connects this WordPress site to War on Image Manager.

== Description ==

Exposes a secure REST API (namespace waronimage/v1, API-key authenticated) giving War on Image Manager direct access to:

* the full media library with every image's info — URL, type/mime, dimensions, file size, ALT text, title, caption, description, thumbnail sizes
* all published content (pages, posts, custom post types) with permalinks and featured-image status
* image upload with SEO metadata in one call (base64)
* metadata updates (ALT, title, caption, description)
* featured-image assignment

== Installation ==

1. In WP admin: Plugins → Add New → Upload Plugin → choose war-on-image-connect.zip → Install → Activate.
2. Open Settings → War on Image and copy the API key.
3. In War on Image Manager, open the site's WP Library tab and paste the key.

== Changelog ==

= 1.0.0 =
* Initial release.
