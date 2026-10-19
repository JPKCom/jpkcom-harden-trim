# JPKCom Harden & Trim – Developer Reference

## Plugin Overview

Bundles small hardening (security), privacy and trimming (performance) switches
behind one settings page. Every switch can also be set by a PHP constant in
`wp-config.php`; the constant wins and locks the field.

- **Text Domain:** `jpkcom-harden-trim` (header, `Domain Path: /languages`,
  loaded via `load_plugin_textdomain()` on `init` priority 1)
- **Min PHP:** 8.3 | **Min WP:** 7.1 | **Tested up to:** 7.1 (all core facts below
  verified against 7.1.3)
- **Not network-only** (no `Network:` header); settings are per site
- **Harmless defaults:** activating with defaults must not visibly change a
  site. Everything with a break path ships OFF.
- **No remote requests, no telemetry.** The only outbound call is the bundled
  updater's manifest fetch.

---

## Architecture

```
Main file (jpkcom-harden-trim.php)
├── declare(strict_types=1), WPINC guard, plugin header
├── JPKCOM_HARDEN_TRIM_VERSION / FILE / PATH / URL
├── JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD  (snapshot taken at file load)
├── init @ 1: load_plugin_textdomain()
├── init @ 5: boot JPKComGitPluginUpdater
├── plugins_loaded @ 5: require modules (schema, store, features.php, then
│     features-*.php sorted), honour the kill switch,
│     then jpkcom_harden_trim_register_features( settings )
└── register_activation_hook: add_option() with defaults (never overwrites)
```

Modules load on `plugins_loaded` (priority 5) so the settings store exists
before any feature reads it. The feature hooks are attached at plugin load
time (not on `init`), because some must act before `init`: the constants
`AUTOSAVE_INTERVAL` and `DISALLOW_FILE_EDIT`, and the application-password
filters, which run when `$wp->init()` determines the current user just before
`init`. Role decisions are therefore made lazily inside each callback, never
at registration time: in REST the current user is only known inside
`serve_request` for application-password requests, and XML-RPC forces user 0.

---

## Data model

One array option `jpkcom_harden_trim_settings`, **autoload on** (read on every
front-end request), holding every key from the schema plus `schema` (int 1)
and `updated`. Reads always merge with defaults and pass through the single
sanitiser (`jpkcom_harden_trim_sanitize_settings()`), which never throws:
unknown keys are dropped and invalid values fall back to the default.

Effective value per key: constant (if defined and valid) → stored option →
default. The filter `jpkcom_harden_trim_settings` runs on the whole effective
array afterwards. **Reach:** the effective array is computed once per site and
request, when the main file first reads it on `plugins_loaded` priority 5, and
then cached (only a save resets it). A callback must therefore be added from a
plugin or mu-plugin file body (or on `plugins_loaded` below priority 5); one
added in a theme's `functions.php`, on `init` or later is ignored, also for
values that callbacks read lazily (`exempt_roles`, `heartbeat`). An invalid constant is ignored and the settings page names it.

Core constants (`WP_POST_REVISIONS`, `AUTOSAVE_INTERVAL`, `DISALLOW_FILE_EDIT`,
`WP_AI_SUPPORT`) defined before the plugin loaded win over everything: core
honours them and the feature code does nothing for that key. So
`jpkcom_harden_trim_locked_keys()` names the core constant whenever one exists,
the settings page shows its value (as text, and in the disabled control through
`jpkcom_harden_trim_core_control_value()`, which reads it the way core does:
`DISALLOW_FILE_EDIT` truthy → checked, `WP_AI_SUPPORT` falsy → "Disable core AI
features" checked, `WP_POST_REVISIONS` `true`/negative → "WordPress default",
`AUTOSAVE_INTERVAL` 60 → "WordPress default", any other value no option names →
an extra selected option), and a plugin constant for the same key is
shown as ignored. The effective-settings array itself is not rewritten to the
core value.

**Multisite.** Settings are per site. The effective-settings cache is keyed
by `get_current_blog_id()`, so after `switch_to_blog()` every reader sees the
switched site's values. Hooks, however, are registered once, from the settings
of the site the request boots on. Callbacks that store or delete data or decide
authentication therefore re-read the current site's value: the revision limit
(returns core's value when the current site sets none), `comment_ip` (IP and
user agent) and the application-password roles callback. Presentation hooks
(head cleanup, headers, `__return_false` filters) act for the booting site's
response.

`includes/settings-schema.php` is the only table of defaults, choices, groups,
role scoping, core constants and badge flags. Presets live there too.

### Presets

`harmless` = the defaults. `recommended` and `strict` list overrides only. The
**preset-managed** keys are every schema key except `block_library_css`,
`global_styles`, `header_hsts`, `head_feed_links`, `app_passwords`,
`app_password_roles` and `exempt_roles`. Applying a preset assigns every
unlocked managed key (the preset value, or the default when unlisted); the
excluded keys are never touched.

### Role exemption

`exempt_roles` (default empty = off). It applies only to `emoji`,
`asset_version`, `comment_reply`, `block_library_css`, `global_styles` and
`heartbeat` (`role_scoped` in the schema). Decision:
`jpkcom_harden_trim_is_exempt( $user )`, filterable through
`jpkcom_harden_trim_is_exempt`.

Roles come from `jpkcom_harden_trim_user_roles( $user )`: on multisite a super
admin counts as `administrator` on every site, also where they hold no role
(`WP_User::roles` lists only the current site's roles). The same helper feeds
the application-password roles check.

---

## Behaviour (hooks per feature)

Features are registered in `includes/features*.php`.

### Performance (`features-performance.php`)

| Feature | Hooks | Why |
|---|---|---|
| `emoji` | `init`, `admin_init`, `wp_print_footer_scripts` @1, `admin_print_footer_scripts` @1 → remove emoji hooks; `tiny_mce_plugins`; global `remove_filter` of `wp_staticize_emoji*` | Core hooks the detection script at `wp_head` 7 and styles at `wp_enqueue_scripts`; removing late and per request lets the role check run. Feed/mail part is global. |
| `self_pingbacks` | `pre_ping` | Runs in cron, so no role scope. A link is "self" when host (scheme, `www.`, case and IDN spelling ignored) **and** path prefix of `home_url()` or `site_url()` match at a segment boundary, and the path does not belong to another site of the network on that host (`jpkcom_harden_trim_sibling_site_paths()`: `get_sites()` by `domain__in` host/`www.`host, without the current site, no limit, any network and status; cached per request, site and host). Without that second rule the main site of a subdirectory network (home without a path) dropped every ping to its siblings. Single site: no query, unchanged. |
| `asset_version` | `script_loader_src`, `style_loader_src`, `script_module_loader_src` @20 | Must run after core's `wp_style_loader_src` (10). Only the `ver` arg is changed. |
| `post_embeds` | `is_post_embeddable` false, `wp_oembed_add_discovery_links` removed at 4 and 10, `template_redirect` @1 (404 on `/embed/`), `embed_oembed_discover` false, `rest_pre_dispatch` (no discovery in the editor proxy), `embed_oembed_html` @5 (strip WP-embed iframes) | `is_post_embeddable` hides discovery but `/embed/` still renders. A bare dequeue of `wp-embed.js` leaves a hidden iframe that still loads the remote page. The oEmbed REST route stays for the editor proxy. |
| `comment_reply`, `dashicons`, `block_library_css`, `global_styles` | dequeue on `wp_print_scripts` (comment reply) or `wp_print_styles` (the others), and again on `wp_print_footer_scripts` @1 | Classic themes enqueue after `wp_head`. Dashicons is dequeued only for logged-out visitors and never deregistered. |
| `heartbeat` | `heartbeat_settings` @99, `script_loader_src` @PHP_INT_MAX, `wp_auth_check_load` @PHP_INT_MAX | See pitfalls. |
| `post_revisions` | `wp_revisions_to_keep` | Site Editor types are exempt. |
| `autosave_interval` | `define( 'AUTOSAVE_INTERVAL' )` at plugin load if undefined | Runs before `wp_functionality_constants()`. |

### `<head>` (`features-head.php`, all global)

| Feature | Hooks |
|---|---|
| `head_shortlink` | remove `wp_shortlink_wp_head` (`wp_head` 10), `wp_shortlink_header` (`template_redirect` 11) |
| `head_rsd` | remove `rsd_link` (`wp_head` 10) |
| `head_rest_links` | remove `rest_output_link_wp_head` (`wp_head` 10), `rest_output_link_header` (`template_redirect` 11) |
| `head_feed_links` | remove `feed_links` (2), `feed_links_extra` (3) |
| `head_generator` | remove `wp_generator`; `the_generator` → empty string |

### Privacy (`features-privacy.php`)

| Feature | Hooks |
|---|---|
| `block_author_enum` | `template_redirect` @0 (404 + no-cache for logged-out `?author=N`, pretty permalinks only). Exempt **only when ActivityPub itself answers the request** (`jpkcom_harden_trim_activitypub_answers_author()`): `\Activitypub\is_activitypub_request()` (q-aware, ld+json only with the AS2 profile) and `should_negotiate_content()`, `author` the only argument after dropping `activitypub`/`stamp` in the query of core's `$requested_url` (`jpkcom_harden_trim_requested_url()`: scheme + raw Host header + REQUEST_URI, `canonical.php:69-73`; parsed with `wp_parse_url()`/`wp_parse_args()`, input and logic as in its `Router::redirect_canonical()`; any other argument, a Host header such as `example.com#` that turns REQUEST_URI into the fragment, or no Host header at all lets core's redirect through), the ID a plain number and `\Activitypub\user_can_activitypub()` true (a non-actor gets the theme's author archive). The request must also reach `template_include`, where ActivityPub renders: any `feed`, `tb`, `robots`, `favicon`, `sitemap` or `sitemap-stylesheet` query var (from path, query or POST body) refuses, because core answers those earlier (`template-loader.php:40-62`, sitemaps exit on `template_redirect`); `/feed/?author=N` has only `author` in its raw query, yet core would serve the author's feed (display name = login by default). Any of the three functions missing → not exempt. Not exempt is not always a 404 on an ActivityPub site: its `Query::get_queried_object()` falls back to `url_to_authorid()` (`functions-user.php:56`, `[?&]author=(\d+)` on the request URL) and renders the actor at `template_include` despite the emptied query, so an AS2 request with extra arguments still gets the actor document (measured live, 9.3.1; only actors, the same document as its REST `actors/N`; under Authorized Fetch 401 with the 404 template). An exempted request arms `template_include` @PHP_INT_MAX (`jpkcom_harden_trim_author_enum_guard_template`): a template outside `ACTIVITYPUB_PLUGIN_DIR` (unsigned request under Authorized Fetch: 401 + theme template, `class-router.php:211`) becomes the 404. Filter `jpkcom_harden_trim_author_enum_exempt` (bool, only `true` exempts; args `$exempt, $query, $accept, $request_uri, $requested_url`) has the final say on the exemption; the guard is armed only when ActivityPub's own decision was true. Tests stub ActivityPub's API in `tests/stubs-activitypub.php` (mirrors 9.3.1) |
| `hide_rest_users` | `rest_pre_dispatch` @99 (401 inside dispatch, so cookie+nonce and application passwords count) |
| `oembed_author` | `oembed_response_data` @20 |
| `sitemap_users` | `wp_sitemaps_add_provider` |
| `browse_happy` | `pre_http_request` (short-circuits the api.wordpress.org browser check) |
| `dashboard_events` | `wp_dashboard_setup` |
| `local_avatars` | `pre_get_avatar_data` @99 |
| `comment_ip` | `pre_comment_user_ip`, `pre_comment_user_agent`; `wp_is_comment_flood` @11 repeats core's `wp_check_comment_flood()` with the shortened IP (stored rows never match the live IP, so guests changing their e-mail address were not throttled) |
| `embed_privacy` | `embed_oembed_html`, `render_block_core/cover`, `rest_post_dispatch` |
| `editor_remote` | remove `wp_enqueue_editor_block_directory_assets`; `should_load_remote_block_patterns`; `block_editor_settings_all`; `init` @11 unregisters the Google Fonts collection |
| `ai_features` | `wp_supports_ai` @PHP_INT_MAX false |

### Security (`features-security.php`)

| Feature | Hooks |
|---|---|
| `login_errors` | `wp_login_errors` (only codes `invalid_username`, `invalid_email`, `incorrect_password`; never `checkemail`) |
| `fingerprint_headers` | `send_headers` (remove `X-Powered-By`), `x_redirect_by` false |
| `header_*` | `send_headers`; skips any header already in `headers_list()` (PHP only: web server, proxy and CDN headers are invisible there, so the UI tells admins to set each header in one place); never on `is_embed()` for the frame header; HSTS only when `is_ssl()` |
| `rest_jsonp` | `rest_jsonp_enabled` false |
| `file_edit` | `define( 'DISALLOW_FILE_EDIT', true )` at plugin load if undefined |
| `app_passwords` | `wp_is_application_passwords_available` false, or `wp_is_application_passwords_available_for_user` against `app_password_roles` |

---

## Core facts and pitfalls (WP 7.1.3)

- `adjacent_posts_rel_link_wp_head` and `wlwmanifest_link` are not hooked in
  7.1 (`wp-includes/default-filters.php`), so there is deliberately no switch.
  `wp_oembed_add_host_js()` is an empty stub (`embed.php:387`).
- Emoji: detection script `default-filters.php:359`, styles `:378`, feed and
  mail staticize `:261`, `:268`, `:274`.
- RSD and REST link removal must stay **global**. Site Health's HTTPS check
  fetches the home page and compares it (`https-detection.php:153`,
  `bad_response_source`); a role- or front-end-scoped removal would make the
  fetched HTML differ.
- `wp_functionality_constants()` (`default-constants.php:376`, called from
  `wp-settings.php:633` right after `plugins_loaded`) defines
  `AUTOSAVE_INTERVAL` (`:380`) and `WP_POST_REVISIONS` (`:391`) only if
  undefined. A plugin may `define()` them at file load; wp-config wins.
- `AUTOSAVE_INTERVAL = 0` does not disable classic autosave (it saves on every
  heartbeat tick), so only positive values are offered.
- Revisions: limit 0 stops new revisions but deletes nothing; N > 0 prunes on
  the next changed save. `wp_revisions_to_keep` is applied at
  `revision.php:835`.
- Heartbeat: `heartbeat_settings` is applied once, when `wp_scripts()` is first
  built (`script-loader.php:876`). Core guards the call with
  `did_action( 'init' ) &&` (`:866`): if `wp_scripts()` is built before
  `init`, the filter is not applied early, it is skipped. Attaching the filter
  at plugin load is simply the earliest safe point; the callback checks roles
  when it runs. The block
  editor forces `interval(10)`; only `minimalInterval` overrides it
  (`heartbeat.js:72`, `:155`). 120 s is the documented safe maximum (longer
  breaks post locks). Deregistering `heartbeat` drops `autosave` and
  `wp-auth-check` with `_doing_it_wrong`; hence `disabled` neutralises the
  script source instead.
- `is_post_embeddable` (`embed.php:350`, `:573`) suppresses discovery and
  oEmbed data, but `/embed/` still renders: needs the `template_redirect` gate.
- `wp-embed.js` is only enqueued when content contains a WP post embed.
- `WP_Query::set_404()` (`class-wp-query.php:1843`) keeps `is_feed`, keeps
  `is_embed` (not in `init_query_flags()`, `:502`) and keeps the queried
  posts. `template-loader.php` runs `do_feed()` while `is_feed()` is true and
  checks `is_embed` before `is_404`; a theme without a 404 template falls back
  to the index loop. Every forced 404 (`/embed/`, `?author=N`, also
  `?author=N&feed=rss2`) therefore goes through `jpkcom_harden_trim_force_404()`
  in `features.php`, which also clears feed, embed, posts, queried object and
  the globals `$post` / `$authordata`. On a feed request `WP::send_headers()`
  (`class-wp.php:471-476`) has already set the feed Content-Type before
  `template_redirect`, so `force_404()` sets it back to core's HTML value
  (`html_type`; charset `blog_charset`, `:468`) unless headers are sent.
- Current user is reliable from `$wp->init()` (just before `init`).
- `wp_is_block_theme()` (`theme.php:4363`) is valid at and after
  `after_setup_theme`; block-theme gates evaluate lazily.
- `wp_login_errors` (`wp-login.php:1234`), `rest_jsonp_enabled`
  (`class-wp-rest-server.php:316`), `x_redirect_by` (`pluggable.php:1537`),
  `wp_sitemaps_add_provider` (`class-wp-sitemaps-registry.php:50`),
  `wp_supports_ai` (`ai-client.php:37`), `should_load_remote_block_patterns`
  (`block-patterns.php:299`).
- 7.1 news: outgoing pings are off outside `production`; same-site pingbacks
  may be auto-approved; the `wp-tooltip` style depends on dashicons.

---

## Constants

Per setting: `JPKCOM_HARDEN_TRIM_` + the schema key in upper case (for example
`emoji` → `JPKCOM_HARDEN_TRIM_EMOJI`). Bool via
`filter_var( ..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE )`; enums
exact strings; ints from the allowed list; role lists an array or comma string.

| Constant | Value | Purpose |
|----------|-------|---------|
| `JPKCOM_HARDEN_TRIM_VERSION` | matches header `Version:` | Plugin version |
| `JPKCOM_HARDEN_TRIM_FILE` | `__FILE__` of the main file | Plugin file |
| `JPKCOM_HARDEN_TRIM_PATH` | `plugin_dir_path( __FILE__ )` | Base path for modules and assets |
| `JPKCOM_HARDEN_TRIM_URL` | `plugin_dir_url( __FILE__ )` | Base URL for assets |
| `JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD` | array | Core constants already defined when the plugin file loads (`WP_POST_REVISIONS`, `AUTOSAVE_INTERVAL`, `DISALLOW_FILE_EDIT`, `WP_AI_SUPPORT`), so the UI can tell a site-set constant from one this plugin defined |
| `JPKCOM_HARDEN_TRIM_DISABLE_ALL` | undefined | Kill switch: a true boolean value registers no feature hooks; the settings page stays reachable. Parsed with `filter_var( FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE )` like every boolean constant, so `'false'`, `'off'`, `'no'` and `'0'` do not kill; an unparseable value is ignored and named in the invalid-constants notice (`jpkcom_harden_trim_kill_switch_state()`) |
| `JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES` | `65536` | Upper bound checked before an import file is read |

---

## File structure

```
jpkcom-harden-trim/
├── jpkcom-harden-trim.php           ← Main: header, constants, textdomain, updater, module loading, activation
├── uninstall.php                    ← Deletes the option, notice and updater transients (every site on multisite)
├── includes/
│   ├── settings-schema.php          ← Schema, defaults, presets (single table)
│   ├── settings-store.php           ← Read/merge/sanitise/write, constant resolution, exemption
│   ├── features.php                 ← jpkcom_harden_trim_register_features() and shared helpers (loaded first)
│   ├── features-performance.php
│   ├── features-head.php
│   ├── features-privacy.php
│   ├── features-security.php
│   ├── admin-page.php               ← Settings screen, save and preset handlers
│   ├── import-export.php            ← Export download, import
│   └── class-plugin-updater.php     ← GitHub updater (namespace JPKComHardenTrimGitUpdate)
├── assets/                          ← admin CSS/JS (settings screen only), banners
├── languages/                       ← .pot, .po/.mo/.l10n.php for de_DE and de_DE_formal
├── tests/                           ← Standalone test-*.php, no WordPress required
├── .github/workflows/               ← ci.yml, release.yml, dependabot-auto-merge.yml
├── phpdoc.xml, README.md, CLAUDE.md, LICENSE, .gitignore
```

---

## Settings screen

**Settings → Harden & Trim** (`add_options_page`, `manage_options`) plus a
"Settings" link in the plugin row. Sections: Performance, Clean up `<head>`,
Privacy, Security, Roles, Presets, Import / Export. Core `form-table` markup;
admin CSS/JS load only on this screen. Per field: label, one-sentence
description linked with `aria-describedby`, and badges (*Changes appearance*,
*Affects editors*, *Locked by wp-config.php*, *Not available with block
themes*, *Global, role exemption does not apply*). Locked fields are disabled
and show the constant and its value; locked keys keep their stored value on
save.

Unlocked fields render the **stored** value, not the effective one, so a value
changed by the `jpkcom_harden_trim_settings` filter is never written into the
option by a plain Save. Such fields get the badge *Changed by a filter* and a
note naming the value in effect.

Write paths: `admin_post_jpkcom_harden_trim_save`, `_preset`, `_export`,
`_import`. Each does `current_user_can( 'manage_options' )` → `wp_die( ..., 403 )`,
then `check_admin_referer()`, then the sanitiser, then `wp_safe_redirect()` with
a fixed message code. Codes map to strings; unknown codes are ignored.

## Export and import

Export: `jpkcom-harden-trim-settings-YYYY-MM-DD.json` with
`{ "plugin", "schema": 1, "version", "settings" }`, stored values only, no site
URL, no user data. Import: file upload (≤ `JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES`,
default 64 KB, named in the UI via `size_format()`; checked with
`is_uploaded_file()`) or pasted JSON; checks `plugin` and `schema`, known keys
only, every value through the sanitiser, roles validated against `wp_roles()`.
The notice reports "N applied, M rejected" with the rejected keys. Locked keys
are stored; the notice says the constant still wins.

## Uninstall

`uninstall.php` deletes the option, leftover notice transients and the family
updater's cache transients (`jpk_git_update_<md5 of the plugin directory>`
plus `_fail` and `_lock`), on every site on multisite. Deactivation keeps the option. This is the first family plugin
that cleans up on uninstall.

---

## Localisation

Every user-facing string goes through `__()`/`esc_html__()`/`esc_attr__()`
under `jpkcom-harden-trim`. English source; `de_DE` (du) and `de_DE_formal`
(Sie) ship in `languages/`. `tests/test-i18n.php` fails when a single-line
gettext call is missing from the `.pot`, when the `.mo` or the `.l10n.php`
holds a different translation than the `.po` for any entry (core serves the
`.l10n.php` first, so a fix in the `.po` alone changes nothing on a site), and
when a `de_DE_formal` translation uses a du form (pronouns, possessives, and
du imperatives: a seed list plus every `<verb>en Sie` the formal catalogue
uses) or a `de_DE` translation a mid-sentence `Sie`/`Ihr…`/`Ihnen`. A new du
imperative outside that lexicon is not caught; add it to `$register_seed`.

```bash
# Sync into a DDEV instance with WP-CLI (excludes .git, .github, CLAUDE.md, docs):
rsync -a --exclude='.git' --exclude='.github' --exclude='CLAUDE.md' --exclude='docs' \
  jpkcom-harden-trim/ <ddev-docroot>/wp-content/plugins/jpkcom-harden-trim/

# From inside the DDEV project:
ddev wp i18n make-pot wp-content/plugins/jpkcom-harden-trim \
  wp-content/plugins/jpkcom-harden-trim/languages/jpkcom-harden-trim.pot \
  --slug=jpkcom-harden-trim --exclude=tests,docs --allow-root

# Update both .po files by hand (new/changed msgids only), then:
ddev wp i18n make-mo wp-content/plugins/jpkcom-harden-trim/languages --allow-root
```

**The `.l10n.php` trap.** Since WP 6.5 `.l10n.php` is the preferred catalogue
format and is loaded **before** the `.mo`. A stale `.l10n.php` therefore
silently shadows an updated `.po`/`.mo`. The two shipped files were generated
(header `x-generator: Claude Code`), so **never run `wp i18n make-php` blindly**:
run it only after the `.po` files are final, then diff the result
(`git diff --stat languages/`), check that `plural-forms` and `language` are
still present, and commit `.po`, `.mo` and `.l10n.php` together. Do not delete
the `.l10n.php` files to "refresh" them.

**Never merge `.po` files with `msgcat --use-first`**; it silently drops
`Plural-Forms`. Use `msgmerge --compendium` and verify afterwards:

```bash
command grep -E '^"(Plural-Forms|Language):' languages/jpkcom-harden-trim-de_DE.po
```

---

## Plugin Updater

- **Namespace:** `JPKComHardenTrimGitUpdate\JPKComGitPluginUpdater`
- **Manifest URL:** `https://jpkcom.github.io/jpkcom-harden-trim/plugin_jpkcom-harden-trim.json`
- Shared JPKCom updater (downstream copy; do not edit per plugin: it must stay identical to `jpkcom-allow-blocks/includes/class-plugin-updater.php` modulo namespace and slug, pinned by a normalised SHA256 in `tests/test-debug-gate.php`). With `WP_DEBUG` on it writes failures (bad manifest URL, fetch error, checksum result) to `error_log()`, as in the whole family. SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`. Checksum verification is **mandatory**: a missing or unfetchable `checksum_sha256` aborts the update instead of installing unverified code. The verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed. Failed manifest fetches are negatively cached for 1 h.
- **When it fetches:** on every read of the `update_plugins` site transient, not only in wp-admin: `wp_get_update_data()` for the toolbar's update counter on the front end (logged-in users with update capabilities), `_maybe_update_plugins` on `admin_init`, and the `wp_update_plugins` cron (7.1.3 `update.php:369`, `:1052`, `:1197-1198`). Anonymous front-end requests do not fetch.
- **Before the first release** the manifest URL answers 404 (the release workflow creates `plugin_jpkcom-harden-trim.json` on `gh-pages`). Under `WP_DEBUG` the updater then writes one line per hour (the `_fail` transient; on multisite once per site, because the updater's transients are per site) to `debug.log`: `JPKCom Plugin Updater: Invalid response code 404 from https://jpkcom.github.io/jpkcom-harden-trim/plugin_jpkcom-harden-trim.json`. Expected until the first tag is pushed; it is the family updater's own log, not a plugin notice, and stays, because the updater must remain identical to the family copy.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.
- **`Update URI: https://github.com/JPKCom/jpkcom-harden-trim`** (header and README key block; this plugin deviates from the family here, security outranks conformity). Why: `wp_update_plugins()` (7.1.3 `update.php`) sends every plugin's headers to api.wordpress.org and stores whatever comes back for a slug in `response`; the family updater only *adds* `response`/`no_update` entries and never removes one, so a wordpress.org plugin squatting the slug `jpkcom-harden-trim` with a higher version would be offered as an update of this plugin. With a non-w.org `Update URI` the wordpress.org API returns nothing for the plugin (server-side behaviour introduced with WP 5.8; it cannot be read from core's source), and core then asks `update_plugins_github.com` (`update.php:514`, `:555`), which nothing hooks, so it skips the plugin. The updater is unaffected: it injects on every read through `site_transient_update_plugins` and never reads the header (`tests/test-bootstrap-main.php` asserts both). A plugin the site owner installs that hooks `update_plugins_github.com` could also answer for this plugin; that is trusted code by definition.

---

## Release Workflow

**Actions are pinned to commit SHAs.** Every `uses:` line in `.github/workflows/` references a 40-character commit SHA instead of a tag (`@v4`), with the version as a trailing comment. A tag is a movable pointer and can be repointed; a SHA cannot. Since the release workflow builds the plugin ZIP **and** the SHA256 checksum the auto-updater trusts, a compromised action would ship a tampered ZIP together with a matching checksum — the checksum secures the transport, the pinning secures the build. `.github/dependabot.yml` keeps the pins current weekly in one combined PR; when updating, always change the SHA *and* the version comment together.

**CI** (`.github/workflows/ci.yml`) runs on every pull request *and* on every push to `main` — a required status check only covers pull requests, so a direct push with bypass rights would otherwise skip the checks entirely. It runs `php -l` over all PHP files; flags invalid named arguments to internal PHP functions (catches `sprintf(format:, values:)` → `ArgumentCountError`, which `php -l` does not see); validates the YAML of every `.github` file; asserts every action is pinned to a 40-character commit SHA; and executes `tests/test-*.php` where present.

**Dependabot auto-merge** (`.github/workflows/dependabot-auto-merge.yml`) merges only `semver-patch` and `semver-minor`, and only PRs from `dependabot[bot]` in this repo — never from forks. Major updates get a comment and stay manual. Two repo settings are prerequisites, otherwise this is useless or outright dangerous: "Allow auto-merge" must be enabled, and branch protection must list `CI / Lint & Guards` as a **required status check** — without it `gh pr merge --auto` merges *immediately*, since there is nothing left to wait for. Together with `cooldown: default-days: 7` no action release is adopted during its first week.

Triggered by **pushing a `v*` tag**; the workflow creates the GitHub release automatically. Pipeline: setup PHP/Python/Pandoc/GraphViz → README metadata → slug-named ZIP (excludes `tests`, `docs`, `CLAUDE.md`, `phpdoc.xml`, among others — `languages/` and `includes/` ship) → SHA256 → upload ZIP + `.sha256` → `plugin_<slug>.json` manifest → PHPDoc → deploy to `gh-pages`.

**phpDocumentor is pinned.** Step 10 downloads `phpDocumentor.phar` from a
fixed GitHub release (`PHPDOC_VERSION`) and runs `sha256sum -c` against the
hard-coded `PHPDOC_SHA256` before executing it; a mismatch fails the job before
anything is deployed. This deviates from the family's unpinned
`https://phpdoc.org/phpDocumentor.phar`. To bump: pick a release on
<https://github.com/phpDocumentor/phpDocumentor/releases>, download its
`phpDocumentor.phar`, check `sha256sum` against the digest the release page
lists, and change both values in the same commit (currently 3.10.0).

The release notes are cut from the README by `awk`, so the section names
`## Description`, `## Installation`, `## Changelog` and `## FAQ` must stay exact.

---

## Tests

`tests/test-*.php`, standalone, no WordPress. Each stubs what its module calls
(shared helpers in `tests/bootstrap.php`, `tests/stubs-admin.php`,
`tests/stubs-query.php`, a `WP_Query` stub with core's `set_404()` semantics, and
`tests/stubs-activitypub.php`, ActivityPub 9.3.1's request classifier and actor
check, loadable in stages with `require`; `bootstrap.php` carries verbatim copies of
core's `wp_parse_url()`, `wp_parse_str()` and `wp_parse_args()`), requires
the module and asserts; a failure exits 1.

- `test-settings-store.php` — sanitiser edge cases, constant resolution, read/write round trip, exemption logic
- `test-presets.php` — preset-managed keys, locked keys untouched, never-in-preset keys
- `test-features-performance.php`, `test-features-head-security.php`, `test-features-privacy.php` — hook registration per setting, including "default settings register exactly the harmless set"
- `test-admin-page.php`, `test-import-export.php` — form application, import validation and rejection codes
- `test-bootstrap-main.php` — main file, `Update URI`, module load order, kill switch values, activation callback
- `test-uninstall.php`, `test-i18n.php` — uninstall cleanup, catalogue guard (`.pot` coverage, `.po` = `.mo` = `.l10n.php` per entry, one form of address per locale)
- `test-release-workflow.php` — `release.yml` fetches phpDocumentor from a pinned GitHub release and runs `sha256sum -c` before executing it
- `test-debug-gate.php` — the updater is the unmodified family copy (normalised SHA256), the plugin's own code never references `error_log()` in any spelling (`error_log`, `\error_log`, `namespace\error_log`, `A\error_log`, `use function`, callable string); the detector is itself checked against `fixture-error-log.php`

`tests/fixture-*.php` are **not** suites and are deliberately not named
`test-*.php`: they run as subprocesses of the suites (for example one process
per constant combination). `fixture-feed-404.php` is a router for the built-in
web server (`php -S`, started by `test-features-privacy.php` on a free port and
stopped again): the CLI SAPI keeps no header list, so real response headers
need a web SAPI. Anything added to `tests/` that is not itself a
suite must follow the same naming rule.

```bash
for t in tests/test-*.php; do php "$t" || echo "FAILED: $t"; done
```

Not covered: the `admin_post_*` handlers end to end (real nonces, `$_FILES`,
`wp_die()`) and the settings screen's JS. Those need a live WordPress.

---

## Security Checklist

- `declare(strict_types=1)` in every PHP file; `WPINC` guard in the main file,
  `ABSPATH` guard in every include
- Every state change: `current_user_can( 'manage_options' )` → `wp_die( ..., 403 )`,
  then `check_admin_referer()`, before anything is touched
- `jpkcom_harden_trim_sanitize_settings()` is the only path into the option;
  unknown keys dropped, invalid values fall back to defaults, roles validated
  against `wp_roles()`
- Import uploads: `is_uploaded_file()` and `JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES`
  before reading
- All output escaped (`esc_html`, `esc_attr`, `esc_url`); `wp_kses_post` only
  where markup is intended
- No remote requests, no telemetry, no `error_log()` in the plugin's own
  code; the family updater logs failures under `WP_DEBUG`, exactly like every
  sibling plugin (`tests/test-debug-gate.php` guards both)
- No PHP notice, `_doing_it_wrong` or JS console error in any mode
- Updater: SHA256 verification + URL validation

---

## Release Checklist

1. Bump version in: header `Version:` + `Stable tag:`, `JPKCOM_HARDEN_TRIM_VERSION`,
   `README.md` (`**Version:**`, `**Stable tag:**`), `phpdoc.xml`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. If any user-facing string changed, regenerate the `.pot` and update both
   `.po` files (see Localisation, including the `.l10n.php` trap) before tagging
4. Run the full test suite and `php -l` over every PHP file
5. Commit, tag `vx.y.z`, push the tag → the workflow builds and publishes everything
