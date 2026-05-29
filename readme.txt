=== Levers ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com
Plugin URI: https://www.contentpowered.com
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tweak WordPress with recommended settings to improve usability, security, performance, reduce spam, fix bugs and more.

== Description ==

Levers gives you a single screen of recommended WordPress tweaks. Each tweak is a "lever" you flip on or off, grouped by what it improves: usability, security, performance, spam and bug fixes.

Only keep the tweaks and functionality that you need.

Find it under Settings > Levers.

= Included levers =

* Friendlier admin greeting - swaps the toolbar's "Howdy" for a warmer "Welcome back".
* Remove "Uncategorized" category - hides the default category from category lists.
* Replace em-dashes with a regular dash sitewide - tidies up a common AI-writing tell.
* Prevent XML-RPC login attacks - disables XML-RPC, a common brute-force vector.
* Force SSL - redirects all HTTP traffic to HTTPS.
* Limit login attempts - blocks brute-force logins per IP, with a ban log.
* Close blog comment spam exploit - holds comments for review to beat a spam exploit.
* Prevent links in blog comments - marks comments that contain a link as spam.
* Remove comment website field - drops the "Website" slot from the comment form.
* Auto-empty spam & trash comments - daily cron purges spam/trash older than 30 days.
* Email obfuscation - rewrites email addresses in content as HTML entities to defeat harvesters.
* Clean expired transients - daily sweep of expired _transient_* rows from wp_options.
* Optimize database tables - weekly OPTIMIZE TABLE pass over your wp_* tables.
* Delete expired sessions - daily purge of expired _wp_session_* rows from wp_options.
* Limit & clean post revisions - caps revisions at 5 per post and trims the existing backlog.
* Clean orphaned metadata - daily sweep of postmeta/commentmeta/termmeta rows whose parent is gone.
* Fix insecure content - rewrites http:// resource URLs to https://.
* Disable self-pingbacks - stops WordPress pinging itself when you link to your own posts.
* Fix scheduled post modified time - aligns modified date with publish date for scheduled posts.
* Publish missed scheduled posts - catches stuck "future" posts that WP-Cron missed.
* Local avatars - lets each user upload a local image that overrides their Gravatar.
* Hide admin notices - one-click dismiss-for-everyone on persistent admin notices.
* Hide updates from non-admins - blanks update nags and counters for users who can't update.
* Strip EXIF/GPS from uploads - removes EXIF metadata (and GPS) from uploaded JPEGs.
* Enable post/page duplication - adds a Duplicate row action that clones posts, pages and CPTs as drafts.
* Disable admin fade transitions - turns off WordPress 7.0's wp-view-transitions-admin stylesheet.
* Header & footer scripts - paste raw code into the head, body-open and footer of every page.
* Hide admin footer credit - removes the "Thank you for creating with WordPress" footer.
* Skip admin email verification prompt - suppresses the periodic "Is this still your email?" check.
* Favicon - shows your favicon in the WordPress admin too, with an in-place media picker.
* Custom login logo - replaces the WordPress logo on wp-login.php with an uploaded image.
* Remove Grammarly code bloat - strips the leftover markup Grammarly leaves in pasted content.
* Disable per-post feeds - redirects each post's /feed/ URL back to the post itself.
* Redirect attachment pages - sends attachment URLs to the parent post (or home if no parent).
* Open external links in a new tab - adds target="_blank" + rel="noopener" to off-site links.
* Noindex internal search results - tells crawlers to skip /?s= pages so spammers can't rank them.
* Default inserted images to no link - stops new images from auto-linking to their attachment page.
* Skip front-end dashicons - drops the Dashicons stylesheet for logged-out visitors.
* Remove emoji scripts - stops wp-emoji-release.min.js and the s.w.org DNS prefetch.
* Disable jQuery Migrate - drops the legacy jquery-migrate shim from the front end.
* Disable oEmbed/embeds - removes wp-embed.min.js, the embed REST endpoint and discovery links.
* Hide WordPress version - strips the generator meta, ?ver= on core assets, and feed generator.
* Block user enumeration - blocks ?author=N redirects and locks /wp-json/wp/v2/users for guests.
* Block PHP execution in uploads - drops an .htaccess into /uploads so PHP files there can't run.
* Disable file editor - sets DISALLOW_FILE_EDIT so theme/plugin PHP can't be edited from the dashboard.
* Remove readme.html & license.txt - deletes and blocks the two root files that leak the WP version.
* Disable directory browsing - drops Options -Indexes so folders without an index file aren't browsable.
* Disable smart punctuation - turns off WordPress's auto-curly-quotes / dash conversion, and straightens any curly quotes already in content.
* Dynamic copyright year - auto-bumps stale © YYYY / Copyright YYYY in <footer> to the current year.
* Stop nav menu jumps - rewrites placeholder href="#" in nav menus so dropdown parents don't scroll the page to the top.
* Add missing image dimensions - sets width/height on <img> and <source> tags missing them, preventing layout shift and helping Core Web Vitals.
* Add security headers - sends X-Frame-Options, X-Content-Type-Options, Referrer-Policy and a restrictive Permissions-Policy on every response.
* Allow SVG uploads (sanitized) - lets admins upload SVGs, sanitizing each file to strip scripts, event handlers and external references.
* Clean rel on internal links - strips SEO-blocking rel tokens (nofollow, sponsored, ugc, noindex...) from links pointing at your own domain.
* Custom admin CSS - paste your own CSS to restyle the dashboard, menus or any wp-admin screen.
* Custom frontend CSS - paste your own CSS into every front-end page, no theme editing required.
* Remove double slashes from URLs - collapses // inside link and image paths, resolving the "Double slash in URL" warning SEO tools flag.
* Search engine visibility warning - flags when "Discourage search engines" is on, with a one-click fix.
* User last login - adds a sortable "Last Login" column to the Users screen showing each user's last login time.

== Installation ==

1. Upload the `levers` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Settings > Levers and flip the levers you want.

== Changelog ==

See https://github.com/jmsprsns/levers/releases for the full release history.
