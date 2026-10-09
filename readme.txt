=== News Feed Factory ===
Contributors: dyadkov-wp
Tags: rss, feed, yandex, google, dzen, sitemap, syndication
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Generate news feeds in multiple formats (Yandex, Google, Dzen, generic RSS)
from WordPress content.

== Description ==

News Feed Factory builds feeds and sitemaps from WordPress content for
external consumers: news aggregators, search engines, reposters, RSS
readers. Each renderer targets a specific format; adding a new format does
not require changes to the core.

Features:

* Multiple output formats (Yandex, Google, Dzen, generic RSS).
* Configurable depth (default 4 hours, max 12 hours).
* Custom metadata table — safe to clear without touching posts.
* Flexible content selection by categories and tags.
* Short-lived caching to avoid regenerating feeds on every request.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Configure depth, cache TTL and feed slug under Settings → News Feed
   Factory.

== Frequently Asked Questions ==

= Where do I find the feed URLs? =

On the plugin settings page. Each enabled renderer exposes its own URL.

= Does the plugin delete my posts? =

No. The plugin maintains a separate table for feed metadata. The table is
safe to clear at any time; posts are not affected.

== Changelog ==

= 0.1.0 =

* Initial development.
