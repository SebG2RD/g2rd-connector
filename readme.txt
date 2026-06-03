=== G2RD Connector ===
Contributors: sebg2rd
Tags: management, monitoring, multisite, dashboard, agency
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.1
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
