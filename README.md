# JPKCom Harden & Trim

**Plugin Name:** JPKCom Harden & Trim  
**Plugin URI:** https://github.com/JPKCom/jpkcom-harden-trim  
**Description:** Secure, private & lean WordPress: hardening, privacy and performance tweaks with safe defaults.  
**Version:** 1.1.2  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com  
**Contributors:** JPKCom  
**Tags:** Security, Privacy, Performance, Hardening, Cleanup  
**Requires at least:** 7.1  
**Tested up to:** 7.1  
**Requires PHP:** 8.3  
**Stable tag:** 1.1.2  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Text Domain:** jpkcom-harden-trim  
**Domain Path:** /languages  
**Update URI:** https://github.com/JPKCom/jpkcom-harden-trim

Secure, private & lean WordPress: hardening, privacy and performance tweaks with safe defaults.


## Description

A collection of small switches that make a WordPress site more secure, leak less
information and load less unnecessary code. Every switch lives on one settings
page under **Settings → Harden & Trim** and can also be set with a PHP constant
in `wp-config.php`. The constant always wins and locks the field on the
settings page.

### Harmless defaults

Activating the plugin and touching nothing must not visibly change your site:
no layout change, no broken editor workflow, no broken integration. Everything
that has a concrete way to break something ships **off**, with a warning next
to the switch. The defaults only remove things nobody sees (generator tags,
fingerprint headers, an emoji script, user enumeration paths) and add headers
that match what browsers already do.

**One deliberate exception: XML-RPC.** The XML-RPC switch is **on** by default
and in every preset, so `xmlrpc.php` is refused on every site that runs this
plugin unless you switch it off. Jetpack, the WordPress mobile apps and incoming
pingbacks stop working while it is on; see the Security table and the changelog
of 1.1.0.

Three presets on the settings page (Harmless, Recommended, Strict) apply a
whole set of switches at once. A preset only assigns the keys it manages, never
touches a locked key, and can never switch on the risky switches below.

### Performance

| Setting | Default | Constant | What it does |
|---|---|---|---|
| Emoji scripts | on | `JPKCOM_HARDEN_TRIM_EMOJI` | Removes the emoji detection script and styles (front end, embeds, admin), the TinyMCE emoji plugin and the feed/e-mail conversion to `s.w.org` images. Emoji stay as Unicode. The feed/e-mail part is global. |
| Self pingbacks | on | `JPKCOM_HARDEN_TRIM_SELF_PINGBACKS` | Stops WordPress from pinging your own posts. Links count as your own when host and path start with your site address, except a path that belongs to another site of the same multisite network, so on a subdirectory network every site, the main site included, still pings its sibling sites. |
| Asset versions | off (`off`, `hash`, `remove`) | `JPKCOM_HARDEN_TRIM_ASSET_VERSION` | Front end only. `hash` replaces the `ver` query value with a short keyed hash, so versions stay hidden and cache busting keeps working. `remove` drops it (stale assets after updates). |
| Post embeds | off | `JPKCOM_HARDEN_TRIM_POST_EMBEDS` | Own posts cannot be embedded, other WordPress sites' URLs are no longer turned into iframes, `wp-embed.js` is not loaded, `/embed/` answers 404. YouTube and other whitelisted providers keep working. The editor's oEmbed REST route stays. |
| Comment reply script | off | `JPKCOM_HARDEN_TRIM_COMMENT_REPLY` | Dequeues `comment-reply`. Replies fall back to a page reload. |
| Dashicons | off | `JPKCOM_HARDEN_TRIM_DASHICONS` | Dequeues dashicons for logged-out visitors. Themes and plugins that use dashicons on the front end lose their icons. |
| Block library CSS | off | `JPKCOM_HARDEN_TRIM_BLOCK_LIBRARY_CSS` | Dequeues the core block styles. Not available with block themes. Block content in posts loses its styling. |
| Global styles | off | `JPKCOM_HARDEN_TRIM_GLOBAL_STYLES` | Dequeues the global styles inline CSS and the block supports CSS (`core-block-supports`, duotone). Not available with block themes. Colours, font sizes and spacing from the block editor no longer show, and block layouts (columns, rows, groups) lose their arrangement. |
| Heartbeat | `enabled` (`enabled`, `optimized`, `disabled`) | `JPKCOM_HARDEN_TRIM_HEARTBEAT` | `optimized` sets the interval to 120 s, the documented safe maximum. `disabled` stops post locking, the session-expiry login modal, classic-editor autosave and nonce refresh. |
| Post revisions | WordPress default | `JPKCOM_HARDEN_TRIM_POST_REVISIONS` | Limits revisions per post (0 to 100). A positive limit prunes old revisions on the next save. Site Editor content types are exempt. Locked when `WP_POST_REVISIONS` is set in `wp-config.php`. |
| Autosave interval | WordPress default (60 s) | `JPKCOM_HARDEN_TRIM_AUTOSAVE_INTERVAL` | Longer autosave interval (2 to 60 minutes). Locked when `AUTOSAVE_INTERVAL` is set in `wp-config.php`. |

### Clean up `<head>`

All of these are global; the role exemption does not apply.

| Setting | Default | Constant | What it does |
|---|---|---|---|
| Shortlink | on | `JPKCOM_HARDEN_TRIM_HEAD_SHORTLINK` | Removes the shortlink tag and the `Link: ...shortlink` header. |
| RSD link | on | `JPKCOM_HARDEN_TRIM_HEAD_RSD` | Removes the Really Simple Discovery link. |
| REST API links | on | `JPKCOM_HARDEN_TRIM_HEAD_REST_LINKS` | Removes the REST discovery link and header. The REST API itself keeps working. |
| Feed links | off | `JPKCOM_HARDEN_TRIM_HEAD_FEED_LINKS` | Removes feed discovery links. Feed readers can no longer auto-discover your feeds. |
| Generator | on | `JPKCOM_HARDEN_TRIM_HEAD_GENERATOR` | Removes the WordPress version from head, feeds and exports. |

The RSD and REST link removal is deliberately global: a version that only hid
them from some requests would make Site Health's HTTPS check report a
mismatching response.

**Why there is no switch for next/previous links or the Windows Live Writer
manifest:** WordPress 7.1 no longer prints either of them. A switch for them
would pretend to do something.

### Privacy

| Setting | Default | Constant | What it does |
|---|---|---|---|
| Block author enumeration | on | `JPKCOM_HARDEN_TRIM_BLOCK_AUTHOR_ENUM` | Logged-out `?author=N` requests get a 404 instead of core's redirect to `/author/<nicename>/`. Only with pretty permalinks. A request passes only when the ActivityPub plugin itself answers it with the actor document: ActivityPub classifies it as an ActivityPub request (its own content negotiation, not a header match), `author` is the only query argument (of the URL WordPress rebuilds from the `Host` header, as ActivityPub reads it), the user is an ActivityPub actor and the request is no feed, trackback, robots.txt, favicon or sitemap request (ActivityPub never answers those; `/feed/?author=N` would otherwise serve the author's feed). So federation keeps resolving author actors, while every other probe, whatever its `Accept` header, gets the 404 from this plugin. One limit: ActivityPub reads `author=N` from the URL itself, so an ActivityStreams request with extra arguments (`?author=1&x=1`) still gets the actor document from ActivityPub, the same document it publishes over its REST API, and never for a user who is not an actor. If ActivityPub still falls back to the theme page (an unsigned request under Authorized Fetch), that page becomes the 404 too. Without ActivityPub, or with a version whose functions are missing, nothing passes. The filter `jpkcom_harden_trim_author_enum_exempt` can override the decision per request. |
| Hide REST users | on | `JPKCOM_HARDEN_TRIM_HIDE_REST_USERS` | `/wp/v2/users` answers 401 for unauthenticated requests. Cookie and application-password authentication still work. |
| oEmbed author | on | `JPKCOM_HARDEN_TRIM_OEMBED_AUTHOR` | oEmbed responses name the site instead of the author. |
| Sitemap users | on | `JPKCOM_HARDEN_TRIM_SITEMAP_USERS` | Removes the users provider from core sitemaps. |
| Browse Happy | on | `JPKCOM_HARDEN_TRIM_BROWSE_HAPPY` | Stops the dashboard call that sends the admin's browser and your site URL to api.wordpress.org. The PHP version check stays. |
| Dashboard events and news | off | `JPKCOM_HARDEN_TRIM_DASHBOARD_EVENTS` | Removes the widget that sends anonymised IP, locale and timezone. |
| Local avatars | off | `JPKCOM_HARDEN_TRIM_LOCAL_AVATARS` | Shows a local avatar instead of Gravatar, so no e-mail hash goes to a third party. |
| Anonymise comment IPs | off | `JPKCOM_HARDEN_TRIM_COMMENT_IP` | Stores commenter IPs truncated (IPv4 /24, IPv6 /48; an IPv4-mapped IPv6 address as its IPv4 /24, a proxy list as its first address) and no user agent. Irreversible for new comments. Spam plugins still see the real IP. The comment flood check also runs against the shortened address, so it works per network: guests sharing one may be slowed down together. |
| Embed privacy | off | `JPKCOM_HARDEN_TRIM_EMBED_PRIVACY` | YouTube embeds use `youtube-nocookie.com`, Vimeo gets `dnt=1`. |
| Editor without remote content | off | `JPKCOM_HARDEN_TRIM_EDITOR_REMOTE` | No block directory, no remote core patterns, no Openverse category, no Google Fonts collection. |
| Core AI features | off | `JPKCOM_HARDEN_TRIM_AI_FEATURES` | Disables the core AI client. Locked when `WP_AI_SUPPORT` is set in `wp-config.php`. |

**This is not GDPR compliance.** The privacy switches reduce the data your site
sends to third parties and exposes about its users. They do not replace a
privacy policy, a consent tool, a data processing agreement or legal advice.
The embed switch in particular is no consent solution.

### Security

| Setting | Default | Constant | What it does |
|---|---|---|---|
| Neutral login errors | on | `JPKCOM_HARDEN_TRIM_LOGIN_ERRORS` | One neutral message for unknown user, unknown e-mail and wrong password. Messages from other plugins (2FA, captcha, lockouts) stay. |
| Fingerprint headers | on | `JPKCOM_HARDEN_TRIM_FINGERPRINT_HEADERS` | Removes `X-Powered-By` and `X-Redirect-By`. |
| `X-Content-Type-Options` | on | `JPKCOM_HARDEN_TRIM_HEADER_NOSNIFF` | Sends `nosniff`. |
| `Referrer-Policy` | on | `JPKCOM_HARDEN_TRIM_HEADER_REFERRER` | Sends `strict-origin-when-cross-origin`, the browser default. |
| `X-Frame-Options` | off | `JPKCOM_HARDEN_TRIM_HEADER_FRAME` | Sends `SAMEORIGIN`, never on embed responses. |
| `Permissions-Policy` | off | `JPKCOM_HARDEN_TRIM_HEADER_PERMISSIONS` | Sends `camera=(), microphone=(), geolocation=()`. |
| `Strict-Transport-Security` | off | `JPKCOM_HARDEN_TRIM_HEADER_HSTS` | Sends `max-age=31536000` over HTTPS only, no subdomains, no preload. Browsers remember it; only switch it on when HTTPS works everywhere. |
| REST JSONP | on | `JPKCOM_HARDEN_TRIM_REST_JSONP` | Turns off JSONP for the REST API. |
| File editor | off | `JPKCOM_HARDEN_TRIM_FILE_EDIT` | Turns off the theme and plugin file editors. Locked when `DISALLOW_FILE_EDIT` is set in `wp-config.php`. |
| Application passwords | `unchanged` (`unchanged`, `disabled`, `roles`) | `JPKCOM_HARDEN_TRIM_APP_PASSWORDS` | `disabled` turns them off; `roles` limits them to the roles below. MCP and Abilities clients, the mobile app and other integrations stop working without them. |
| Application password roles | none | `JPKCOM_HARDEN_TRIM_APP_PASSWORD_ROLES` | Roles that may use application passwords when the setting above is `roles`. With no role selected nobody can use them, administrators included; the settings page warns about it. On multisite a super admin counts as `administrator` on every site. |
| Disable XML-RPC | **on** | `JPKCOM_HARDEN_TRIM_XMLRPC` | Refuses `xmlrpc.php` with a 403 and an XML-RPC fault, empties the method list, and stops advertising the endpoint (`X-Pingback` header, `<link rel="pingback">`). **Jetpack, the WordPress mobile apps and pingbacks stop working**, and trackbacks are refused too. Turn it off, or set the constant to `false`, when you need any of them. Global; the role exemption does not apply. |
| Disable comments | off | `JPKCOM_HARDEN_TRIM_COMMENTS` | Closes comments, pings and trackbacks on every post type and hides existing comments (nothing is deleted; switching it off brings them back). Removes the Comments screen, admin menu entry and admin-bar node and the comment counts on the dashboard. Every post reports zero comments, so `/feed/` and the Atom feed carry no comment links or counts and themes print no "N comments". The comment feeds answer 404 (`/feed/` stays); their query-string spellings (`?feed=comments-rss2`, `?p=123&feed=rss2`) get that 404 directly instead of WordPress' redirect to a pretty URL that would 404 as well, deliberately. The REST comment routes answer every request with the `rest_no_route` 404 a missing route gives (`OPTIONS` too, which a missing route answers with an empty 200), and REST post responses report `comment_status` and `ping_status` as `closed`. **Block editor Notes keep working**: they are comments of type `note` on the same REST routes, so the routes stay registered and a request for notes (the collection with `type=note`, or an existing note by its ID) reaches WordPress, whose own permission checks decide. A note's route whose `id` parameter names a different comment (`/wp/v2/comments/<note ID>?id=<comment ID>`, or that `id` in the request body, which WordPress would load instead) gets the 404. Because the routes stay registered, the REST index at `/wp-json/` lists them. Global. Never part of a preset. |

**Page caches and headers:** headers are sent by PHP on front-end HTML
responses and skipped when PHP already set a header of that name (WordPress or
another plugin). Headers added by the web server (nginx `add_header`, Apache
`mod_headers`), a proxy or a CDN are invisible to PHP, so the same header would
be sent twice. Set each header in one place: either switch it on here, or set it
in the server configuration and switch it off here. Two `Strict-Transport-Security`
headers are the worst case, because browsers only use the first one and
hstspreload.org rejects such responses. A page served straight from a page
cache may not carry PHP headers at all; the server configuration is the more
reliable place for them.

### Role exemption

| Setting | Default | Constant | What it does |
|---|---|---|---|
| Exempt roles | none (off) | `JPKCOM_HARDEN_TRIM_EXEMPT_ROLES` | Logged-in users with one of these roles get the untouched site for emoji, asset versions, comment reply script, block library CSS, global styles and heartbeat. |

On multisite a super admin counts as `administrator` on every site, also
where they hold no role there. The exemption covers only those six switches. Everything else is global by
nature (cron, anonymous requests, headers, constants, `<head>` output), and the
settings page marks each switch accordingly. The filter
`jpkcom_harden_trim_is_exempt` can change the decision per user.

### Presets

| Preset | Content |
|---|---|
| Harmless | The defaults above (including XML-RPC refused). |
| Recommended | Harmless plus optimized heartbeat, `X-Frame-Options`, no dashboard events widget, embed privacy, file editor off, 20 revisions. |
| Strict | Recommended plus hashed asset versions, no post embeds, no comment reply script, no dashicons, local avatars, anonymised comment IPs, no remote editor content, no core AI, `Permissions-Policy`, 5 revisions, 2 minute autosave. |

XML-RPC is refused in every preset (Harmless, Recommended and Strict all list it
as on), so applying a preset never reopens it. The flip side: applying a preset
switches it **on** again, also on a site where you had turned it off (for
example for Jetpack). A `wp-config.php` constant keeps it off regardless.

Never touched by any preset: disabling comments, block library CSS, global
styles, HSTS, feed link removal, application password settings and the role
lists. Every other switch gets the preset's value or its default, so a preset
never switches heartbeat off or removes asset versions, but it does reset a
disabled heartbeat or removed asset versions. Applying a preset asks for
confirmation and lists the keys that changed.

### Import and export

**Export** downloads the stored settings as a JSON file. It contains no site URL
and no user data. **Import** accepts a file (up to 64 KB; `JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES` in
`wp-config.php` changes the limit) or pasted JSON,
accepts known keys only, runs every value through the same sanitiser as the
settings form and reports how many keys were applied and which were rejected.
A key locked by a constant is stored, but the constant still wins.

### Kill switch

`define( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', true );` registers no feature hooks
at all. The settings page stays reachable and shows a notice. The value is read
like every boolean constant: `'false'`, `'off'` or `0` keep the features on, and
a value that is no boolean is ignored and named on the settings page.

### Filters for developers

- `jpkcom_harden_trim_settings` (array) changes the effective settings; values
  are validated again. It runs once, on `plugins_loaded`, so add it in a plugin
  or mu-plugin, not in a theme's `functions.php`. The settings page marks fields
  it changes and keeps saving the stored values.
- `jpkcom_harden_trim_is_exempt` (bool, user) changes the role exemption.
- `jpkcom_harden_trim_author_enum_exempt` (bool, query vars, Accept header,
  REQUEST_URI, requested URL as core rebuilds it) lets
  an anonymous `?author=N` request through.

### Privacy of the plugin itself

No remote requests, no telemetry, no tracking. The one exception is the plugin
updater, which fetches a manifest from GitHub Pages whenever WordPress reads its
list of plugin updates: in wp-admin, on the front end for logged-in users who
can update plugins (the toolbar's update counter), and in WordPress's own update
cron. The manifest is cached for a day, a failed fetch for an hour.

### Documentation

**API Documentation:** Complete PHPDoc-generated API documentation is available at:
[https://jpkcom.github.io/jpkcom-harden-trim/docs/](https://jpkcom.github.io/jpkcom-harden-trim/docs/)


## Installation

1. In your admin panel, go to 'Plugins' > and click the 'Add New' button.
2. Click Upload Plugin and 'Choose File', then select the Plugin's .zip file. Click 'Install Now'.
3. Click 'Activate' to use the plugin right away, then review **Settings → Harden & Trim**.


## FAQ

### How do I set options in wp-config.php?

Define the constant before the "That's all, stop editing" line. The constant
wins over the stored value and locks the field on the settings page:

```php
define( 'JPKCOM_HARDEN_TRIM_EMOJI', true );
define( 'JPKCOM_HARDEN_TRIM_HEARTBEAT', 'optimized' );
define( 'JPKCOM_HARDEN_TRIM_POST_REVISIONS', 20 );
define( 'JPKCOM_HARDEN_TRIM_EXEMPT_ROLES', 'editor,author' );
```

Booleans take `true`/`false`, enums an exact value from the table, integers a
value from the allowed list, role lists an array or a comma separated string.
An invalid value is ignored and the settings page names the constant.

`WP_POST_REVISIONS`, `AUTOSAVE_INTERVAL`, `DISALLOW_FILE_EDIT` and `WP_AI_SUPPORT`
in `wp-config.php` win over this plugin, including its constants: WordPress
follows them, the matching field is locked and its disabled control shows their
value the way WordPress reads it (`WP_POST_REVISIONS` `true` or `-1` as
"WordPress default", a number outside the list as an extra entry), and a
`JPKCOM_HARDEN_TRIM_*` constant for the same setting is marked as ignored.

### Something broke. How do I switch everything off?

Add `define( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', true );` to `wp-config.php`. No
feature runs while it is defined. Deactivating the plugin does the same.

### Does it work with block themes?

Yes. Block library CSS and global styles are unavailable with block themes
(the switches are disabled and do nothing there), because a block theme
depends on those styles.

### Does it overlap with Rank Math, caching plugins or Defender?

Possibly. SEO plugins may remove the same `<head>` links or replace the core
sitemaps (then the sitemap switch has nothing to do). Caching and optimisation
plugins may offer emoji, embed or heartbeat switches. Security plugins may send
the same headers; this plugin skips a header another plugin already set in PHP,
but cannot see headers from the web server or a CDN. Run each switch in one
place only.

### What about XML-RPC, comments and the login URL?

XML-RPC and comments are built in since 1.1.0 (the two switches in the Security
table) and behave like the standalone plugins JPKCom Disable XML-RPC and JPKCom
Disable Comments, hook for hook. Those plugins remain available and maintained,
and running one of them next to the matching switch is harmless: the same hooks
fire twice, and the settings page notes that the plugin is already active. One
exception: JPKCom Disable Comments up to 1.0.10 removes the REST comment routes,
which breaks block editor Notes even with this switch; update it to 1.0.11 or
later. Hiding the login URL stays with JPKCom Hide Login; this plugin does not
duplicate it.

### Does it make my site GDPR compliant?

No. See the note in the description.

### What happens when I uninstall it?

Deleting the plugin removes its settings option and its cached data (on
multisite, from every site). Deactivating keeps them.


## Changelog

### 1.1.2
* Added: a plugin icon. The update manifest now points WordPress to the JPKCom logo, so the Updates screen (Dashboard → Updates) shows it next to this plugin instead of the generic pattern wordpress.org generates from the slug

### 1.1.1
* Changed: the German translations call the defaults "Standardwerte"; "Voreinstellungen" now only means the presets, so the settings page no longer uses one word for both

### 1.1.0
* Changed: **XML-RPC is blocked on every site right after this update**, because the new "Disable XML-RPC" switch is on by default and the stored settings of an earlier version do not contain it. Jetpack, the WordPress mobile apps and incoming pingbacks stop working until you untick the switch in **Settings → Harden & Trim** or set `define( 'JPKCOM_HARDEN_TRIM_XMLRPC', false );` in `wp-config.php`. Every preset keeps it on, so applying a preset also switches it on again
* Added: "Disable XML-RPC" switch, mirroring JPKCom Disable XML-RPC 1.0.10: `xmlrpc.php` answers 403 with an XML-RPC fault, the endpoint is no longer advertised, trackbacks are refused
* Added: "Disable comments" switch (off by default, never part of a preset), mirroring JPKCom Disable Comments 1.0.11: comments and pings closed on every post type, existing comments hidden, every post reports zero comments (no comment links or counts in `/feed/` and the Atom feed, no "N comments" in themes), comment screens and feeds removed. The REST comment routes answer every request that is not a block editor Notes request with the `rest_no_route` 404 a missing route gives (`OPTIONS` too, which a missing route answers with an empty 200); a note's route whose `id` parameter names a different comment is not a Notes request. Notes keep working, and WordPress' own permission checks decide who may read or write them. Because the routes stay registered for Notes, the REST index at `/wp-json/` lists them. The query-string comment feeds (`?feed=comments-rss2`, `?p=123&feed=rss2`) answer 404 directly instead of redirecting to a pretty URL that would 404 as well; this is deliberate
* Added: a hint on the settings page while the matching standalone plugin is active
* Added: German translations of the new texts (`de_DE`, `de_DE_formal`)

### 1.0.1
* Changed: the updater keeps its manifest cache, its failure marker and its fetch lock in site transients. On multisite the network now fetches the manifest once instead of once per site; on a single site nothing changes
* Changed: uninstalling also removes the updater's network-wide cache entries, and still the per-site ones 1.0.0 wrote

### 1.0.0
* Added: Initial release.
* Added: performance switches (emoji, self pingbacks, asset versions, post embeds, comment reply, dashicons, block library CSS, global styles, heartbeat, revisions, autosave interval)
* Added: `<head>` cleanup, privacy and security switches with harmless defaults
* Added: role exemption (off by default), presets, JSON import/export, `wp-config.php` constants and a kill switch
* Added: German translations (`de_DE`, `de_DE_formal`)
