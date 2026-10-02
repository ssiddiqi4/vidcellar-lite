=== VidCellar Lite ===
Contributors: ssiddiqi4
Tags: video, video streaming, video player, video categories
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free WordPress video library with secure local playback, categories, ratings, thumbnails, and resumable large-file uploads.

== Description ==

VidCellar Lite is a standalone GPLv2-or-later WordPress plugin for managing and playing videos on your own WordPress site. It does not require an external account or a license key. It is suitable for hosting online streaming libraries, film and music festival videos, webinars, training, and enterprise videos.

Start with VidCellar Lite, a free core video platform for managing and streaming videos from your WordPress website. An optional Pro add-on is available separately for advanced monetization, cloud storage, paid-content access, analytics, advertisement insertion, and other premium features.

Features include:

* Video library and Watch pages.
* Administrator-managed video categories.
* Secure local video storage and playback.
* Resumable/chunked uploads for large video files, subject to server resources and hosting limits.
* Video file type and MIME validation.
* Video thumbnails and optional trailers.
* Ratings and view counts.
* Background video optimization when FFmpeg is available on the server.
* Responsive HTML5 video playback.
* WordPress-native administration, permissions, nonces, and REST APIs.
* Optional deletion of plugin data during uninstall; data is retained by default.

VidCellar Lite is fully usable on its own. Paid monetization, payment gateways, coupons, advertising controls, commercial storage integrations, and advanced revenue features are provided separately by an optional Pro add-on.

== Installation ==

1. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**.
2. Upload the VidCellar Lite ZIP and activate the plugin.
3. Open **VidCellar > Videos** to add videos.
4. Use the automatically created Videos and Watch pages, or place `[vidcellar_browse]` and `[vidcellar_video]` shortcode on your own pages.
5. Configure your video categories under **VidCellar > Categories**.

== Frequently Asked Questions ==

= Is VidCellar Lite free? =

Yes. VidCellar Lite is a standalone GPLv2-or-later plugin and does not require a license key or an external account.

= Can I upload large videos? =

Yes. The built-in resumable uploader supports files up to 30 GiB, subject to available disk space, PHP/web-server configuration, request limits, and hosting resources.

= Does Lite process payments? =

No. Payment and monetization features are not included in Lite. They are available separately in an optional Pro add-on.

= Where are videos stored? =

Lite stores uploaded videos in protected local storage under the WordPress uploads directory. The plugin creates protection rules for supported web-server configurations. Site administrators using Nginx or another web server should also configure server-level protection as appropriate for their hosting environment.

= Does VidCellar Lite send my video data to an external service? =

No. Lite does not require an external video service and does not send video files to a remote video server. Video data and plugin records remain on the WordPress installation unless the site administrator separately configures another service.

= How do I change the Watch-page heading? =

Edit the WordPress Page that contains `[vidcellar_video]` shortcode. The Page title is used as the Watch-page heading.

= What happens if I deactivate the plugin? =

Deactivation does not delete the plugin database tables or video files. Data is retained so the plugin can be reactivated safely.

= What happens if I uninstall the plugin? =

By default, VidCellar Lite retains its database tables and uploaded video files. An administrator can explicitly enable the plugin's delete-data-on-uninstall setting before uninstalling if permanent removal is desired.

== Privacy ==

VidCellar Lite does not require registration with an external video service and does not send video files to a remote video server. The plugin stores its video metadata and locally uploaded video files on the WordPress site. Site administrators are responsible for configuring their site's privacy policy and for describing any other services, analytics, storage providers, or media services they independently configure.

== Upgrade to Pro ==

An optional Pro add-on is a separate premium product that is not included in this WordPress.org plugin. The Lite plugin remains fully usable without Pro. Optional Pro information and purchase links are shown only inside the WordPress administration area.

== Screenshots ==

1. Video library.
2. Video management screen.
3. Video categories.
4. Video upload interface.
5. Watch page and HTML5 video player.

== Upgrade Notice ==

= 1.1.9 =
Fixes video uploads (every chunk was rejected), a fatal error on the Videos page, and category names turning into slugs.

= 1.1.8 =
Enqueues the dashboard pricing stylesheet with wp_enqueue_style() instead of printing a style tag.

= 1.1.7 =
When Pro is active, Lite no longer adds a second Settings menu item.

= 1.1.6 =
Moves the Pro plans and Lite vs Pro comparison to the Dashboard.

= 1.1.5 =
Restores the Lite vs Pro comparison table on Videos and Need Help.

= 1.1.4 =
Shows the optional Pro licensing pricing table on the Videos screen.

= 1.1.3 =
Removes the duplicate VidCellar admin submenu so Videos is the first item.

= 1.1.2 =
WordPress.org review fixes: distinctive plugin name/slug, enqueued admin JavaScript, and reviews table schema compatibility.

== Changelog ==

= 1.1.9 =
* Fixed resumable uploads rejecting every raw chunk with "No valid upload chunk was received." (an operator-precedence bug in the upload error check).
* Fixed a JavaScript error when resuming an upload whose chunks had all already reached the server.
* Fixed a fatal error on the Videos page: the [vidcellar_browse] shortcode callback rejected the attributes WordPress passes.
* Category names such as "Short Films" are no longer replaced by their slug once a video uses them, the active category tab is highlighted again, and video edit forms preselect the right category.

= 1.1.8 =
* Enqueued the dashboard pricing stylesheet with wp_enqueue_style() instead of printing a style tag.

= 1.1.7 =
* Stops registering a Lite Settings submenu when VidCellar Pro is active, so Settings is not duplicated.

= 1.1.6 =
* Moved the Pro plans and Lite vs Pro comparison table from Videos to Dashboard.

= 1.1.5 =
* Restored the VidCellar Lite vs Pro comparison table on Videos (above Add a video) and Need Help.

= 1.1.4 =
* Added the optional Pro licensing pricing table to the Videos screen, above Add a video.

= 1.1.3 =
* Removed the duplicate first admin submenu titled VidCellar. The first submenu is now Videos.

= 1.1.2 =
* Updated the public plugin name, slug, author, and text domain to VidCellar Lite.
* Enqueued admin video-management JavaScript instead of printing an inline script tag.
* Added the missing reviewer_key column to the reviews table on activation and upgrade.
* Loaded public CSS/JS only on pages that use the plugin shortcodes.

= 1.1.1 =
* Resolved Plugin Check short PHP echo-tag errors by using full PHP echo syntax throughout the plugin.
* Hardened public query-string handling with unslashing, sanitization, validation, and context-appropriate nonce exceptions for public browsing/streaming URLs.
* Hardened admin POST and upload handling, including centralized request/file access helpers and MIME validation.
* Escaped generated HTML attributes and content, including rating controls and shortcode-generated markup.
* Updated custom-table SQL to use prepared statements and WordPress identifier placeholders where supported.
* Replaced avoidable direct file deletion/move operations with WordPress filesystem APIs and documented narrowly-scoped native stream/process operations that are required for chunked uploads, video range streaming, and optional FFmpeg transcoding.
* Improved CSV export implementation without direct fopen/fclose calls.
* Raised the minimum supported WordPress version to 6.2 for `%i` database identifier placeholders.

= 1.1.0 =
* Prepared the Lite release for WordPress.org distribution.
* Updated the plugin and stable tag to 1.1.0.
* Improved the Watch-page heading resolution so the WordPress Page title is used reliably.
* Retained existing database compatibility and migration safeguards.
* Refined installation, privacy, data-retention, and Pro separation documentation.

= 1.0.9 =
* Improved Watch-page heading resolution across normal WordPress queries, request URLs, page-builder contexts, and the configured Watch page.
* No database changes required.

= 1.0.8 =
* Improved Watch-page title handling and page-title resolution.

= 1.0.7 =
* Improved Watch-page title handling.

= 1.0.6 =
* Added editable Watch-page heading support based on the WordPress Page title.

= 1.0.5 =
* Improved Lite administration and compatibility handling.

= 1.0.2 =
* Fixed Lite video helper loading for Pro shortcodes.
* Added defensive compatibility fallback for category normalization.
* Improved activation compatibility with existing databases.

= 1.0.1 =
* Fixed Lite/Pro activation schema compatibility and legacy helper loading.
* Added safeguards against duplicate function declarations.
* Improved migration safety for existing installations.

= 1.0.0 =
* First Lite release.
* Free video library and secure local playback.
* Categories, ratings, thumbnails, trailers, and view counts.
* Resumable large-file uploads with validation.
* WordPress-native security controls and GPLv2-or-later licensing.

== License ==

VidCellar Lite is licensed under the GNU General Public License, version 2 or later.

https://www.gnu.org/licenses/gpl-2.0.html
