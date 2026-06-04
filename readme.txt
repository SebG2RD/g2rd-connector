=== G2RD Connector ===
Contributors: sebg2rd
Tags: management, monitoring, multisite, dashboard, agency
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this WordPress site to the centralized G2RD WP Manager dashboard (https://wp-manager.g2rd.fr) for inventory, telemetry, events and remote commands.

== Description ==

**G2RD Connector** is the agent-side counterpart of the [G2RD WP Manager](https://wp-manager.g2rd.fr) SaaS dashboard. Once enrolled, it lets your agency centrally manage this site alongside any number of other WordPress installs: inventory of plugins/themes, update status, security findings, real-time event stream, and remote maintenance commands.

= Features =

* **Inventory snapshot** — secure REST endpoint exposing WordPress core, plugins, themes and server info to the manager.
* **Optional hourly heartbeat** — light telemetry payload (disk usage, active plugin count, user count) sent to your manager instance via WP-Cron. Disabled until you opt in.
* **Optional event stream** — push real-time notifications (user logins, login failures, plugin activations, core/plugin/theme updates, auto-update failures) to the manager. Disabled until you opt in.
* **Optional remote commands** — let the manager trigger `clear_cache`, `check_updates` and `update_core` remotely. Disabled until you opt in.
* **Theme integration** — when the optional companion theme `g2rd-theme` (>= 1.19) is active, the plugin registers itself as a tab in *Appearance → G2RD Options* instead of adding a top-level menu, for a tidy admin UX.

= External service =

This plugin relies on the **G2RD WP Manager** service operated by G2RD Web Agency (France). It is not required to install the plugin, but **no feature works without enrolling the site** to a manager instance.

* Service URL: https://wp-manager.g2rd.fr
* Terms of Service: https://wp-manager.g2rd.fr/cgu
* Privacy Policy: https://wp-manager.g2rd.fr/privacy

**What is sent, when, and only after explicit enrollment:**

* On every manager-initiated `/snapshot` call: WordPress version, list of installed/active plugins and themes (names, versions, slugs), PHP/MySQL versions, memory limit.
* On every hourly heartbeat (if enabled): WordPress/PHP/connector version, free disk space, active plugin count, registered user count.
* On every webhook event (if enabled): event type (e.g. `user.login`, `plugin.activated`) and a small context payload (user id, plugin file name, IP for failed logins).

**Nothing is sent before enrollment.** Enrollment is a manual one-shot action that requires an invitation token obtained from your manager admin.

== Installation ==

1. Upload the `g2rd-connector` folder to `/wp-content/plugins/`, or install the ZIP from this directory.
2. Activate the plugin in WordPress *Plugins* page.
3. Go to *Settings → G2RD Connector* (or *Appearance → G2RD Options → Manager G2RD* if you also run the `g2rd-theme` companion).
4. Paste your manager URL and invitation token, then click **Enroll site**.

== Frequently Asked Questions ==

= Does the plugin work without the G2RD WP Manager service? =

No. The plugin is purpose-built to bridge a WordPress site with a G2RD WP Manager instance. Without enrollment, it exposes a public `/wp-json/g2rd/v1/health` endpoint and nothing else.

= Can I self-host the manager? =

Not yet. The manager is currently SaaS-only, operated at https://wp-manager.g2rd.fr. A self-hosted version is on the long-term roadmap.

= How do I revoke a site? =

Disconnect from the plugin settings (button **Disconnect from manager**), or revoke the site token from the manager admin. The plugin will then refuse any further authenticated request.

= What happens on uninstall? =

All plugin options (`g2rd_connector_settings`) are removed, the hourly cron job is unscheduled, and the site stops sending anything. The manager-side record is preserved until you delete it from the dashboard.

== Screenshots ==

1. Standalone settings page with manager URL, invitation token and feature toggles.
2. Theme-integrated tab in *Appearance → G2RD Options* (requires `g2rd-theme` >= 1.19).

== Changelog ==

= 1.5.1 =

* **Test release**: no functional change versus 0.1.5 — validates the GitHub self-update notification flow end-to-end (now-public repo → `releases/latest` → "update available" notice on installed sites).

= 0.1.5 =

* **Real-time update detection regardless of WP cache**: the `/snapshot`
  endpoint now explicitly clears the `update_core`, `update_themes` and
  `update_plugins` site transients before re-running `wp_version_check()`,
  `wp_update_themes()` and `wp_update_plugins()`. Without this, WordPress'
  internal 12h cooldown ("minimum_period") could silently skip the refresh
  and return a stale snapshot — so a theme or plugin with a newly published
  GitHub release would still show "up to date" in the manager until the
  next cron run.
* This is a defense-in-depth fix on top of the v0.1.2 sanity check: we now
  both **force the transient refresh** AND **verify `version_compare(candidate,
  installed, '>')`** before flagging `has_update=true`. Net result: the manager
  dashboard reflects the real upstream state at every sync, no matter when
  the last cache refresh happened.
* Additional CI hardening: PHPCS alignment rules (DoubleArrowNotAligned,
  MultipleStatementAlignment) explicitly excluded — they were cosmetic and
  caused churn on every variable rename. Pattern adopted by Yoast / ACF /
  WooCommerce. All structural and security sniffs remain active.

= 0.1.4 =

* **3 new maintenance commands** the manager can trigger remotely (extends the
  Phase 3 command queue):
  * `delete_spam_comments` — purge all comments marked as spam in one batch
    via `wp_delete_comment(force_delete=true)`. Returns deleted count.
  * `delete_post_revisions` — drop all rows of `post_type='revision'` to
    reclaim DB space. Uses `wp_delete_post_revision()` (no trash bin).
  * `optimize_database` — `OPTIMIZE TABLE` on every wp-prefixed table,
    purge of expired transients (`_transient_timeout_*` < now), and cleanup
    of orphaned transient timeout options.
* **Upgrade events now include actor**: `Events\Listener::on_upgrader_complete()`
  now captures the WP user that triggered a core/plugin/theme/translation
  update (`wp_get_current_user()`). If the event comes from the WP cron auto-
  update, actor is `cron`. Sent to the manager for proper audit attribution.
* **Translations inventory in the snapshot**: new `translations` section in
  `GET /wp-json/g2rd/v1/snapshot` payload listing installed languages,
  current locale, and available `.mo`/`.po` updates (with type/slug/language/
  version). The manager can now display and act on translation updates the
  same way it handles plugin/theme updates.

= 0.1.3 =

* GitHub Updater: every site running this plugin now receives automatic update
  notifications when a new release is published on
  https://github.com/SebG2RD/g2rd-connector/releases. WordPress detects the
  release in **Plugins → Updates** and lets the admin install it with one click,
  exactly like a plugin from the official directory. Mirrors the pattern already
  in use for the companion theme `g2rd-theme`.
* Hooks: `pre_set_site_transient_update_plugins`, `plugins_api`,
  `upgrader_source_selection`. Falls back gracefully if api.github.com is
  unreachable. Normalizes the extracted ZIP folder to `g2rd-connector/`
  regardless of GitHub's auto-generated zipball naming.

= 0.1.2 =

* Fix "phantom update" bug: snapshot endpoint now forces refresh of WordPress
  caches (`wp_clean_themes_cache`, `wp_clean_plugins_cache`, `wp_update_themes`,
  `wp_update_plugins`, `wp_version_check`) before reading inventory. Without
  this, a theme or plugin updated via a custom updater (GitHub updater, FTP,
  WP-CLI) could keep reporting "update available" in the manager dashboard
  even after the actual update was applied.
* Strict `version_compare` sanity check on every `has_update` flag: only
  reports an update when the candidate version is strictly newer than the
  installed one. Defends against stale `update_themes` / `update_plugins`
  transients.

= 0.1.1 =

* Remote command queue: hourly draining of pending manager commands (opt-in `remote_commands_enabled`).
* Shared `CommandExecutor` between the REST endpoint and the cron poller.

= 0.1.0 =

* Initial release.
* REST: `GET /wp-json/g2rd/v1/snapshot` (Bearer SiteToken).
* REST: `GET /wp-json/g2rd/v1/health` (public).
* REST: `POST /wp-json/g2rd/v1/command` (Bearer SiteToken) — `clear_cache`, `check_updates`, `update_core`.
* Outbound: enrollment, hourly heartbeat, real-time event push to the manager.
* Adaptive admin UI: tab inside *Appearance → G2RD Options* if `g2rd-theme` >= 1.19 is active, otherwise top-level menu.
* Compatible with WordPress 6.4+ and PHP 8.1+.

== Upgrade Notice ==

= 0.1.0 =

First public release. No upgrade path yet.
