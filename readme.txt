=== G2RD Connector ===
Contributors: sebg2rd
Tags: management, monitoring, multisite, dashboard, agency
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.13.0-rc.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this WordPress site to the centralized G2RD WP Manager dashboard (https://wp-manager.g2rd.fr) for inventory, telemetry, events and remote commands.

== Description ==

**G2RD Connector** is the agent-side counterpart of the [G2RD WP Manager](https://wp-manager.g2rd.fr) SaaS dashboard. Once enrolled, it lets your agency centrally manage this site alongside any number of other WordPress installs: inventory of plugins/themes, update status, security findings, real-time event stream, and remote maintenance commands.

= Features =

* **Inventory snapshot** — secure REST endpoint exposing WordPress core, plugins, themes and server info to the manager.
* **Optional hourly heartbeat** — light telemetry payload (disk usage, active plugin count, user count) sent to your manager instance via WP-Cron. Disabled until you opt in.
* **Optional event stream** — push real-time notifications (user logins, login failures, plugin activations, core/plugin/theme updates, auto-update failures) to the manager. Login failures are sent with a 2-second cap instead of 15, at most 30 per minute. Disabled until you opt in.
* **Optional remote commands** — let the manager trigger cache clearing, update checks, core/plugin/theme updates and database maintenance (delete spam comments, delete post revisions, empty trash, delete expired transients, optimize database) remotely. Disabled until you opt in.
* **Direct login from the manager** — the manager can open this site's dashboard without a password, through a signed one-time link valid for one minute, for staff whose manager account uses two-factor authentication. On by default; can be turned off in the plugin settings ("Allow direct login from G2RD").
* **Theme integration** — when the optional companion theme `g2rd-theme` (>= 1.19) is active, the plugin registers itself as a tab in *Appearance → G2RD Options* instead of adding a top-level menu, for a tidy admin UX.

= External service =

This plugin relies on the **G2RD WP Manager** service operated by G2RD Agence Web (France). It is not required to install the plugin, but **no feature works without enrolling the site** to a manager instance.

* Service URL: https://wp-manager.g2rd.fr
* Terms of Service: https://wp-manager.g2rd.fr/cgu
* Privacy Policy: https://wp-manager.g2rd.fr/privacy

**What is sent, when, and only after explicit enrollment:**

* On every manager-initiated `/snapshot` call: WordPress version, list of installed/active plugins and themes (names, versions, slugs), PHP/MySQL versions, memory limit.
* On every hourly heartbeat (if enabled): WordPress/PHP/connector version, free disk space, active plugin count, registered user count.
* On every webhook event (if enabled): event type (e.g. `user.login`, `plugin.activated`) and a small context payload (user id, plugin file name, IP for failed logins).
* Since 1.13, the `/snapshot` answer also contains the login page address, the state of the "Allow direct login from G2RD" setting and the site's administrators (up to 50: user id, login, email, registration date), so the manager can offer direct login with the account chosen by the agency.
* Since 1.13, a `direct_login` event (WordPress login and user id, manager user id) is sent after each direct login, if events are enabled.

**Nothing is sent before enrollment.** Enrollment is a manual one-shot action that requires an invitation token obtained from your manager admin.

= External service: GitHub (update checks) =

To provide update notifications, the plugin queries the **public GitHub Releases API** at https://api.github.com/repos/SebG2RD/g2rd-connector during WordPress' normal update checks. This request is read-only and unauthenticated: it sends no site data and only retrieves the latest published version number and its download URL.

* Service: GitHub REST API (https://docs.github.com)
* Terms of Service: https://docs.github.com/site-policy/github-terms/github-terms-of-service
* Privacy Policy: https://docs.github.com/site-policy/privacy-policies/github-privacy-statement

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

All plugin options (`g2rd_connector_settings`, restore point index, signature state) are removed, the cron jobs are unscheduled, the restore point archives the plugin created in `wp-content/g2rd-snapshots/` are deleted (nothing else in that folder is touched), and the site stops sending anything. The manager-side record is preserved until you delete it from the dashboard.

== Screenshots ==

1. Standalone settings page with manager URL, invitation token and feature toggles.
2. Theme-integrated tab in *Appearance → G2RD Options* (requires `g2rd-theme` >= 1.19).

== Changelog ==

= Unreleased =

* **Fix: a failed automatic rollback leaves the plugin deactivated.** The request that runs the
  update had loaded the plugin when it started (it was active), so the WordPress activation
  sandbox would not load it again: it would activate whatever the failed restore left on disk
  without testing it. After a failed restore, a plugin whose main file is already loaded by the
  request is no longer reactivated (automatic rollback, shutdown handler of the update request,
  manual rollback): it stays deactivated, and the error sent to the platform says so, with the
  same error code ("the plugin was left inactive: … which could not be checked because its code
  was already loaded earlier in this request…"). A restore now deactivates the plugin only once
  its folder has been set aside: a refusal before that (`version_drift`, integrity) leaves the
  plugin active and untouched instead of deactivated.
* **Fix: a protected update never reactivates its plugin after a recovery run by another
  process.** When a step lasts more than 10 minutes, a recovery started by the cron takes the
  transaction over, restores the plugin and alone decides whether it is reactivated. If that
  restore failed and left the plugin inactive (it does not load), the update request, still
  running, used to force it active once the recovery had closed the transaction (nominal
  reactivation or shutdown handler), taking every page down with a critical error. The update
  request now reactivates the plugin only while the transaction is still its own, or after it
  closed it itself; its shutdown handler no longer reactivates a plugin that a recovery closed
  without reactivating.

= 1.13.0-rc.2 =

Lighter on the server: fewer WordPress boots and fewer calls to the manager, same features.

* **Performance: the snapshot no longer restarts WP-Cron at almost every sync.** Update
  discovery runs twice a day. The snapshot used to wake WP-Cron (`spawn_cron()`: a second
  WordPress boot and a full update check) whenever the last capture was older than 6 hours,
  which was nearly every sync since the manager syncs about every 7 hours. The threshold is now
  13 hours: WP-Cron is woken only when the scheduled discovery is late (WP-Cron disabled or
  stuck, site without visits). With no capture at all, nothing changes.
* **Performance: a failed login holds a WordPress page 2 seconds at most.** The
  `user.login_failed` event is sent with a 2-second cap instead of 15, response not read (same
  address, headers and body), and at most 30 of them are sent per minute and per site, counted
  in a transient that expires by itself. Beyond that, attempts are neither sent nor counted (no
  database write), and sending resumes the next minute. No summary event is sent: the existing
  event format has none, and adding one needs a change on the manager side. Other events
  (successful logins, plugins, updates) are unchanged.
* **Performance: the local purge of restore points and used direct-login tickets runs twice a
  day instead of hourly.** The hourly schedule is migrated once, on load and on activation,
  keeping its next run time. Ticket validation does not depend on the purge: expiry is checked
  before single use, so an expired ticket is refused whether or not it was purged (covered by
  tests). A protected update whose request was killed without reaching the shutdown handler is
  now recovered by a one-off check of its own (`g2rd_connector_update_recovery_check`),
  scheduled 11 minutes after such an update starts and again while it stays open, so this
  recovery happens sooner than with the hourly run. That check only recovers: it never purges
  restore points or tickets. The purge itself is skipped while a protected update is running,
  so the restore point and archive of an update in progress are never deleted under it. The
  update refreshes its transaction after each long step (health measures, restore), so a check
  does not take a live update for a dead one unless a single step lasts more than 10 minutes.
  A request still running does not recreate a transaction that a check has closed (it re-reads
  the transaction, bypassing the options cache, before each refresh), except in a window of a
  few milliseconds with a persistent object cache. A dead update is recovered once: if its
  transaction cannot be removed, the check is not scheduled again (never at a past date), the
  plugin is not restored again, and the attempt is recorded in the
  `g2rd_update_txn_recovery` option. Expired restore points may stay on disk up to 12 hours
  longer (24 hours if an update was running at purge time), still within the per-plugin cap
  and the disk budget. Deactivation and uninstall also remove a pending one-off check;
  uninstall also removes the recovery record.
* **Fix: a new protected update recovers a dead one first instead of overwriting it.** An update
  killed mid-way (plugin deactivated, files half replaced) and not yet recovered is restored once
  before the new update opens; the recovery reserves the transaction (`recovering` step) before
  restoring, so a check running at the same time does not restore again and a protected update
  requested meanwhile is refused with the existing message ("another protected update is still
  running on this site…", now ending with "retry in a few minutes"). Closing and the shutdown
  handler only touch the transaction of their own process, an update whose transaction was taken
  over during a step longer than 10 minutes stops before updating the plugin with an explicit
  error, and a lost recovery check is scheduled again right before the plugin files change
  instead of waiting up to 12 hours for the purge.
* **Fix: recoveries, protected updates and manual rollbacks no longer cross each other.** A
  recovery run by the cron arms the shutdown handler and keeps a one-off check pending before it
  restores anything: a recovery killed mid-way is taken over in about 11 minutes instead of
  waiting up to 12 hours for the purge. Right before WordPress replaces the plugin files
  (`upgrader_pre_install` filter, before the plugin is deactivated), a protected update refreshes
  its transaction; if a recovery took it over during a download or transient refresh longer than
  10 minutes, the update stops there with an explicit error ("protected update stopped before
  replacing the plugin files… the plugin was not updated, retry in a few minutes"): the new
  version is never copied after the recovery put the previous one back, nor while it is still
  restoring. A protected update whose transaction was taken over while the files were being
  replaced stops right after the update with an explicit error: no health measure, no second
  restore at the same time, no `updated` or `not_updated` result (the recovery restores the
  previous version and reports its own result); if the update itself fails after such a takeover,
  its error says so ("its transaction was taken over by a recovery, which reports its own
  result"). Taken over during the health check, it does not roll back without its transaction: it
  fails with an explicit error and keeps the restore point for a rollback from the platform (known
  limit: it takes a health measure longer than 10 minutes). A recovery that died while restoring
  is no longer retried forever: after 3 recovery attempts that did not finish (a fatal error
  replayed each time), the next one does not restore, reports `recovery_failed` with an explicit
  detail and keeps the restore point, instead of leaving the plugin deactivated with no result for
  the platform. If the plugin was active, it is reactivated only if it loads without error
  (otherwise it stays inactive): its files are then at their most doubtful (maybe half
  extracted), and `active_plugins` is never written directly, which would take every page of the
  site down with a critical error. A protected update stopped because a recovery took over its
  transaction no longer reactivates the plugin while that recovery is still restoring it (the
  recovery reactivates it itself once done). A manual rollback (`rollback_plugin`)
  is refused with the same "another protected update is still running on this site… retry in a
  few minutes" message while a protected update or a recovery is running; a dead transaction
  does not block it. A recovery that outlived its reservation no longer marks the next recovery
  as done. The transaction is re-read even when it was read as missing earlier in the request
  (WordPress `notoptions` cache), and the `g2rd_update_txn_recovery` record is removed along
  with its transaction.
* **Fix: a restore that fails no longer forces the plugin active.** After a failed restore
  (automatic rollback, recovery of an interrupted update or manual rollback), the plugin is
  reactivated only through the WordPress activation sandbox, never by writing `active_plugins`
  directly (its folder may be half replaced, which would take every page of the site down with
  a critical error), and if it does not load there it stays inactive and the error sent to the
  platform says so ("the plugin was left inactive…").
* **Uninstall also removes the failed-login counter** (`g2rd_connector_login_failed_window`
  transient), on every site of a multisite network.
* **Performance: one SQL query less per page view.** The `g2rd_connector_settings` option
  (about 0.5 KB) is now autoloaded. Existing installs are switched once with
  `wp_set_option_autoload()`, without rewriting the value.

= 1.13.0-rc.1 =

Pre-release for the pilot: sites do not update to it automatically.

* **Feature: direct login from G2RD WP Manager.** A new `admin-ajax.php?action=g2rd_login` entry
  point opens the dashboard of this site from the manager, without a password. It only accepts a
  ticket signed with the site token, bound to this site and to one administrator account, valid
  for 60 seconds and usable once. Any refusal shows a plain 403 page that says what happened,
  the likely cause and what to do. The page links to the login page only when the ticket is
  genuine (valid signature); with no ticket, or a malformed or wrongly signed one, no link is
  shown, so a hidden login address (WPS Hide Login, Solid Security...) is never disclosed to
  an anonymous visitor.
* **Security: six checks, in this order.** Format and signature (constant-time comparison, with
  a key derived from the site token in a context distinct from command signatures), site,
  expiry (30 s clock tolerance, at most 90 s ahead), single use, the "Allow direct login from
  G2RD" setting, and that the account still has the `manage_options` capability.
* **Setting: "Allow direct login from G2RD"**, checked by default, in the settings page and in
  the admin panel. Unchecking it refuses every ticket at once. The setting is kept by the
  settings sanitizer (lesson of 1.12.0-rc.4).
* **Snapshot: new `site` fields** `login_url` (as filtered by plugins that move the login page),
  `direct_login_enabled` and `admins` (up to 50 administrators who really hold the
  `manage_options` capability: id, login, email, registration date), plus the `direct_login`
  capability.
* **Event: `direct_login`** (WordPress login and user id, manager user id) is sent after each
  direct login when events are enabled, once the response has been sent to the browser.
* **The session is opened without firing the `wp_login` hook**, so a direct login is not
  reported as a regular `user.login` event and a site-side two-factor plugin does not cancel it
  (two-factor authentication is required on the manager side instead).
* **Security: a ticket is consumed by a single atomic database insert**, so two simultaneous
  requests carrying the same ticket cannot both open a session. Consumed tickets are purged
  hourly after 10 minutes, and all removed on uninstall (on every blog of a multisite).
* **Tests: shared ticket vectors.** The ticket vectors are produced by the manager and replayed
  here byte for byte.

= 1.12.0 =

Stable release of the 1.12 line, piloted as 1.12.0-rc.1 to rc.5 (details in the entries below).

* **Feature: protected updates with automatic rollback.** Before a plugin update the connector
  takes a restore point; if the site stops answering correctly afterwards, the previous version
  is put back automatically. A restore point can also be used on demand from the manager.
* **Security: signed commands.** Commands sent by the manager carry an HMAC signature bound to
  the site, the command, a timestamp and a nonce (replay protection). Sensitive commands require
  a valid signature, from the REST route and from the command queue alike.
* **Security: the site token can be encrypted at rest with authenticated encryption** (read by
  default, written only when enabled), and the anti-replay registry is bounded by time.
* **Feature: two network fallbacks** for restrictive hosts: the token can travel in the
  `X-G2RD-Token` header, and outbound calls to the manager can be forced over IPv4.
* **Fix: the connector loads again on PHP 8.1**, the minimum version this plugin declares.

= 1.12.0-rc.5 =

* **Security: commands that require a signature now require it from the command queue too.**
  `rollback_plugin`, `delete_restore_point` and `set_signature_policy` already required a valid
  signature on the REST route, whatever the signature policy. The hourly cron, which pulls
  commands from the manager's queue, executed them without any check: a single unsigned queue
  entry could switch a site from `required` back to `report`, or delete every restore point.
  They are now executed from the queue only with a valid signed envelope (same key, same v1
  scheme, bound to the site and to the command, ±300 s window, replay protection); otherwise
  the site answers `failed` with an explicit, translatable message and counts the failure. The
  manager never puts these commands in the queue today, so no current traffic is refused; the
  historical queue commands (cache, updates, cleanup) run exactly as before. When the stored
  site token cannot be used (unreadable, or refused by the strict mode below), no signature is
  ever accepted, from the queue or anywhere else (`token_unavailable`): the key derived from an
  empty token could be computed by anyone. These commands are then refused with a message asking
  to re-enrol the site; historical commands keep their path.
* **Security: the anti-replay registry is now bounded by time, not by volume.** It used to keep
  at most 500 nonces and evicted the oldest ones even while they could still be replayed:
  500 signed requests within ten minutes were enough to make an earlier request replayable.
  A nonce is now kept for as long as it can be replayed (two ±300 s windows plus one minute).
  A memory guard of 5,000 live nonces remains; when it is reached, the new request's nonce is
  refused (`nonce_store_full`, counted in the signature diagnostics) instead of evicting a live
  one. In the default `report` policy the request is still accepted; it is refused only under
  the `required` policy or for commands that always require a signature, with an explicit,
  translatable message. The registry is now written under a MySQL advisory lock, so two
  simultaneous requests can no longer erase each other's nonce; if the host does not allow
  the lock, the plugin works exactly as before. Storage format unchanged: no migration.
* **Security: wordpress.org rollback archives can now be verified against a SHA-256 sent by the
  platform.** When a restore point is gone, `rollback_plugin` falls back to the archive on
  `downloads.wordpress.org`; its content was checked by nothing but the version header. The
  command now accepts a new `source_sha256` key (64 hexadecimal characters): the downloaded
  archive must match it, otherwise the site answers `rollback_failed_integrity` before touching
  anything (plugin neither moved nor deactivated, temporary file removed). A malformed value is
  refused before downloading. `expected_sha256` keeps its meaning (the local restore point only)
  and is never applied to the downloaded archive. Without `source_sha256`, which is the case of
  every current manager, behaviour is unchanged; the unverified download is recorded (counter and
  last case in the non-autoloaded `g2rd_connector_unverified_downloads` option, the
  `g2rd_connector_rollback_source_unverified` action) and the result now reports
  `source_integrity` and the computed `source_sha256_actual`. Integrity failures also report
  `via` (`restore_point` or `download`). No setting, signature policy or existing key changes.
* **Security: the site token can now be encrypted at rest with authenticated encryption,
  delivered in two steps.** Since 1.6.7 it was stored as AES-256-CBC without any integrity check
  (`enc:v1:`): a value altered in the database decrypted, without any error, into a modified
  token. This version can **read** a new `enc:v2:` format, `enc:v2:sb:` (libsodium secretbox,
  XSalsa20-Poly1305) or `enc:v2:gcm:` (AES-256-GCM), the algorithm being written in the value;
  the keys are still derived from the wp-config.php salts (no new secret). An altered `v2` value
  gives no token at all instead of a wrong one. **Writing** `v2` is off by default: sites keep
  writing the `v1` format 1.12.0-rc.4 reads, because the connector cannot be downgraded from the
  manager and a rollback release built on code unable to read `v2` would cut every migrated site
  off. It is enabled by the new `G2RD_CONNECTOR_TOKEN_V2` wp-config.php constant (or the
  `g2rd_connector_token_v2` filter); removing it again is a real switch: a `v2` token (or a `v1`
  value wrapping a `v2` one) is rewritten as plain `v1` on the next settings write, after a
  successful round trip (never rewritten as plaintext on a host without openssl). Release rule:
  no published version may drop the `v2` reader; a test checks it. With `v2` writing enabled, a
  `v1` or plaintext token is rewritten as `v2` on the next settings write (in practice the next
  accepted heartbeat), never at boot, after a successful round trip; its `v1` value is then kept
  in the non-autoloaded `g2rd_connector_site_token_v1` option (removed on uninstall; no copy is
  possible on a host without openssl). That copy follows the current token: regenerated on every
  new enrolment, emptied (never deleted) on "Disconnect from the manager". **Limit:** only `v2`
  values are protected against tampering. For compatibility, `v1` values and the historical
  plaintext token are still accepted by default, so someone with write access to the database
  can still replace the token with an old-format value (including the backup copy, or a
  plaintext token of their choice); with `v2` writing enabled, such a value is reported as
  `tokenState: legacy` in the admin page data. The new `G2RD_CONNECTOR_REQUIRE_AUTHENTICATED_TOKEN`
  wp-config.php constant (or the `g2rd_connector_require_authenticated_token` filter), off by
  default, refuses a `v1`-only or plaintext token once the site itself writes authenticated
  values (`tokenState: refused`, with an explicit admin notice); a `v1` value wrapping a `v2` one
  stays accepted. Existing values keep working byte for byte: a historical plaintext token is
  encrypted at boot as `v1`, as before (left as is without openssl). An unreadable token is never
  overwritten, and administrators now see an explicit notice instead of a silent disconnection
  (altered value or changed salts) telling them to disconnect then re-enrol the site; the
  standalone connector page shows that state instead of the green "enrolled" banner. The
  `g2rd_connector_token_cipher` filter (`sb`, `gcm`, `v1`) chooses among the algorithms the host
  and the `v2` option allow, `v1` also bringing a `v2` token back to `v1`. Nothing changes on the
  wire: same Bearer token, same signature key, no re-enrolment. Before downgrading the connector
  by hand to 1.12.0-rc.4 or older after a `v2` migration, remove the `v2` option and wait for a
  heartbeat; otherwise copy `g2rd_connector_site_token_v1` back into the `site_token` key of
  `g2rd_connector_settings`, or simply update the connector again (the value written by the
  older version is repaired on read).
* **Fix: the connector loads again on PHP 8.1.** Since 0.1.0, two methods of the outbound client
  (heartbeat and real-time events) declared the `true|WP_Error` return type; the standalone `true`
  type only exists from PHP 8.2. On PHP 8.1, the minimum version this plugin declares, merely
  loading that class was a fatal error, so the hourly heartbeat and every event sent to the
  manager crashed the request. The return type is now `bool|WP_Error` (accepted since PHP 8.0);
  the methods still only ever return `true` or a `WP_Error`, and nothing changes for callers or
  on the wire. A new test reads every PHP file of the plugin and refuses the syntaxes PHP 8.1
  does not know (standalone `true`, `false` or `null` types, DNF types, `readonly` classes, typed
  class constants), and runs `php -l` on each file with the interpreter running the tests, so the
  "PHP 8.1" CI job also catches what the reader does not.
* **Fix: a signed-only queue command without an envelope now blames the right cause when the site
  token is unusable.** If the stored site token cannot be read (or is refused by the strict
  mode), such a command is now refused with `token_unavailable` and the message asking to
  disconnect then re-enrol the site, instead of `signature_missing` and a message suggesting the
  platform predates signed commands.
* **Maintenance:** the `source_sha256` value of `rollback_plugin` is trimmed with an explicit
  character list, so its behaviour does not depend on the PHP version (PHP 8.6 widens the default
  list of `trim()`); the value is still validated as 64 hexadecimal characters. Admin screen
  build: `@wordpress/scripts` 36 and `@wordpress/element` 8.8 (the latter is provided by
  WordPress at runtime, not bundled); one line of the admin screen source reformatted for the
  new code style. No change to the shipped behaviour.

= 1.12.0-rc.4 =

* **Fix: the "Force IPv4 to the manager" option can now be saved.** In rc.3 the box could be
  ticked and saved, but it was unticked again on reload: the settings sanitizer, which
  WordPress runs on every write of the option, did not know the new key and dropped it. The
  option is now kept, from the settings page and from the admin panel alike. A new test
  checks that every boolean setting declared in the defaults survives a save, so a future
  option cannot be silently dropped the same way.

= 1.12.0-rc.3 =

* **The site token can travel in an `X-G2RD-Token` header.** Some shared-hosting firewalls
  reject any REST request carrying `Authorization`, from any origin, before WordPress even
  sees it. The fallback header is read only when `Authorization` is absent or unusable; same
  token, same comparison, same signature policy afterwards. Two tests guard the precedence.
* **"Force IPv4 to the manager" option** (off by default). On 2026-09-25 the host's CDN
  returned 403 to the server's outgoing IPv6 for every domain: heartbeats, events and
  enrollment died on the way while the site served its visitors normally. When enabled, the
  connector pins cURL to IPv4 for requests whose host is the manager's -- and only those.
  Replaces the hand-placed mu-plugin that did not follow plugin updates.

= 1.12.0-rc.2 =

* **A plugin is never left deactivated after a restore.** `activate_plugin()` includes the plugin's
  main file and lets third-party code run, all of it BEFORE `active_plugins` is written. A fatal
  error there used to leave the plugin switched off with no recourse. The fallback path, which runs
  no third-party code, now takes over. Reactivation is also attempted when the restore itself fails:
  the operation is transactional -- the original directory is put back -- so switching the plugin
  back on is always the right move.
* **`$wp_filesystem` is initialised before touching any files.** The connector does not need it
  (extraction goes through ZipArchive), but a lot of third-party code assumes it is available as
  soon as a plugin moves. That assumption holds in wp-admin and fails in a REST request.

= 1.12.0-rc.1 =

* **Plugin rollback with restore points.** When the manager asks for it, `update_plugin` now takes a
  health baseline, zips the plugin into `wp-content/g2rd-snapshots/`, updates, checks the site again
  (home page + admin-ajax) and rolls back automatically if the site regressed. Manual rollback,
  wordpress.org fallback, disk budget, grace period and a local hourly purge cron. The connector
  never rolls itself back.
* **Signed manager requests.** HMAC-SHA256 signature verified next to the Bearer, report-only by
  default (nothing is refused; the result is reported to the manager). New commands always require
  a valid signature. A toggle in the admin page switches to strict mode.
* **Auto-update guard.** After a rollback, WordPress will not silently reinstall the removed version.
* **Tests.** PHPUnit + Brain Monkey suite (unit tests without WordPress), excluded from the shipped zip.

= 1.11.8 =

* **Security** — closes the two remaining Dependabot alerts on the build tooling. `@wordpress/scripts`
  moves to 35.0, whose `@puppeteer/browsers` 3.x no longer depends on `extract-zip` (flagged for
  every published version, no fix available), and `adm-zip` is bumped to 0.6.1. `npm audit` is
  back to zero. None of this ships in the released plugin, which contains only PHP and the
  pre-built admin bundle.

= 1.11.7 =

* **Fix** — the self-updater now caches the GitHub "latest release" answer for six hours.
  It used to call the GitHub API on every rebuild of the `update_plugins` transient: core
  update checks, admin screen visits, and every synchronisation from the manager. The
  unauthenticated API allows 60 requests per hour per IP, and sites on shared hosting share
  that IP, so the ceiling was reachable across a fleet.
* **Safety** — the cache is flushed whenever the manager refreshes update data, before a
  synchronisation and before an upgrade. Without that, the connector updating itself could
  read a stale release and do nothing, silently. A failed GitHub call is remembered for only
  fifteen minutes so an outage is not retried on every request, yet never hides a release for
  six hours.

= 1.11.6 =

* **Security** — closes the `minimatch` ReDoS advisory. A single vulnerable copy (3.0.8) sat
  under `markdownlint-cli`; the override is scoped to that one parent, so the rest of the tree
  keeps the v9 and v10 copies its consumers expect. Also pins `qs` to 6.16.0, which clears
  three advisories reached through `express`.
* **Note** — one advisory remains and cannot be fixed from here: `extract-zip` is flagged for
  every published version, so no upgrade closes it. It is pulled in by `puppeteer`, which
  `@wordpress/scripts` ships for its end-to-end and performance commands. Escaping it would
  mean forcing `@puppeteer/browsers` 3.x, which requires Node 22.12 or newer while this
  project still builds on Node 20. None of it is part of the released plugin, which contains
  only PHP and the pre-built admin bundle.

= 1.11.5 =

* **Fix** — the settings panel stylesheet was never loaded. The plugin enqueued
  `assets/admin/build/index.css`, but `wp-scripts` emits `style-index.css`; a `file_exists()`
  guard meant the mismatch failed silently rather than raising an error, and it had been that
  way since the panel was written. The panel now picks up its intended styling: a 720 px
  column, spaced action buttons, and the version string in grey monospace.
* **Added** — right-to-left locales now get `style-index-rtl.css`, which was already built and
  shipped but had no way of being used.

= 1.11.4 =

* **Fix** — the dependency overrides introduced in 1.11.3 were too broad. Pinning `minimatch`
  to the v3 branch globally also downgraded the copies used by `typescript-estree`,
  `test-exclude` and `@sentry/node`, which expect v9 or v10; forcing `@opentelemetry/core` v2
  into `@sentry/node` made that package throw on load. Both overrides are now scoped so only
  the vulnerable copies are replaced, and the lockfile was regenerated so that `npm ci` and
  `npm install` build the same tree. Development toolchain only — the shipped plugin was never
  affected.
* **Fix** — the overrides now use plain package keys instead of the version-range selector
  (`"markdown-it@<14.2.0"`), which npm 10 does not interpret. The lockfile is generated with
  npm 10 and `npm ci` was verified under npm 10.8.2, 10.9.4 and 11.16.0, so the CI runners and
  a local machine build the same tree.
* **Note** — the release archive no longer ships the `docs/` folder, which held internal design
  notes.

= 1.11.3 =

* **Maintenance** — the admin build toolchain moved from `@wordpress/scripts` 28 to 34, and
  every known vulnerability in the development dependencies is now closed: `npm audit` reports
  zero. No PHP logic changed in this release, only the version header and constant.
* **Note** — the admin panel bundle was rebuilt by the newer toolchain. It exposes the same REST
  routes, requests the same WordPress script handles (`wp-element`, `wp-components`,
  `wp-api-fetch`, `react-jsx-runtime`) and ships identical styles.
* **Compatibility** — declared as tested up to WordPress 7.1.

= 1.11.2 =

* **Fix** — WordPress core updates were reported as failures even though they applied
  correctly. `Core_Upgrader` was the only upgrader built without a skin, so the default
  `WP_Upgrader_Skin` echoed its progress HTML (`show_message()` = `echo` + `flush()`) into
  the REST response body, ahead of the JSON. The manager could not decode that body and
  marked every site of the fleet as failed. Core updates now use `Automatic_Upgrader_Skin`,
  like plugin, theme and translation updates already did.
* **Hardening** — command output is buffered: anything a third-party plugin or a PHP notice
  writes while a command runs is captured and returned as `stray_output` instead of
  corrupting the JSON response.

= 1.11.1 =

* **Fix** — on sites running a persistent object cache (LiteSpeed Object Cache, Redis…), the
  capture written by the discovery job was wiped on every sync. WordPress stores transients **in
  the object cache** rather than the database when one is present, and the snapshot deliberately
  calls `wp_cache_flush()` to defeat stale `get_plugins()` data — thereby erasing our own capture.
  Measured across the fleet right after the 1.11.0 rollout: capture lost on both sites with an
  external object cache, intact on the four without. The capture is now stored in an option, with
  its own expiry check, so it survives the flush.

= 1.11.0 =

* **Fix** — third-party plugin updates are now discovered **without anyone opening the site's
  wp-admin**, which is the whole point of a fleet console. 1.10.0 relied on two sources that both
  failed in practice: the admin capture only happens if a human browses the site, and the
  "preserve before destroying" path never preserved anything. The snapshot called
  `wp_clean_plugins_cache()` / `wp_clean_themes_cache()` **without arguments** two lines earlier,
  and both core functions delete the update transients unless passed `false` — so the bridge
  always read an already-erased transient. Measured on a client site: forcing a privileged
  re-check put SEOPress PRO 10.1 in the database, the very next snapshot reported
  `has_update: false` and destroyed the entry.
* **New** — a dedicated WP-Cron job (`g2rd_connector_refresh_updates`, twice daily) forces a real
  re-check and captures the result. WP-Cron is one of the three contexts third-party updaters
  accept — SEOPress tests `DOING_CRON` explicitly — and it is the context WordPress itself uses
  for automatic updates. No privilege elevation, no admin visit. The schedule self-heals on boot,
  so existing installs get it on upgrade without replaying the activation hook.
* **New** — the snapshot exposes `updates_discovery` (`captured_at`, `stale`, `cron_disabled`,
  `next_run`). A site whose WP-Cron is disabled used to silently claim everything was up to date;
  the manager can now tell "nothing to update" from "I could not look". When the capture is stale,
  the snapshot also wakes WP-Cron up (`spawn_cron()`, non-blocking), so low-traffic sites refresh
  within one sync cycle.
* **Fix** — applying an update no longer wipes the pending updates of *other* premium plugins:
  the two post-upgrade cache clears also pass `false`.

= 1.10.0 =

* **Fix** — updates announced by third-party updaters that do not register in the REST
  context are now detected and reported to the manager. Measured on a production site:
  `SEOPRESS_Updater::check_update` hooks `pre_set_site_transient_update_plugins` and is
  active in wp-admin and WP-CLI, but not during a REST request. The snapshot deleted the
  `update_plugins` transient and rebuilt it without that entry, so the plugin was always
  reported as up to date. Not all premium plugins were affected — those whose updater
  registers unconditionally (Elementor Pro, Astra Pro, Fluent*) always worked.
* **Fix** — such updates can now actually be installed from the manager. `Plugin_Upgrader`
  reads the same transient to find the `package` URL; without the entry the upgrade
  "succeeded" without changing the installed version.
* **New** — update entries are captured from an admin screen (Plugins, Updates, Dashboard)
  into a 7-day cache, then replayed through the `site_transient_update_plugins` /
  `site_transient_update_themes` read filters. Replaying through a read filter avoids
  re-triggering the updaters already present (and their network calls) on every sync, and
  never writes reconstructed entries to the database.
* **New** — the settings screen (both the standalone page and the React tab) shows when the
  last capture happened and how many entries it holds.

= 1.9.4 =

* **Fix** — publisher name corrected to **G2RD Agence Web** (plugin `Author` header, update
  info modal and readme). No functional change.

= 1.9.3 =

* **Fix** — `update_plugin` / `update_theme` now report `updated: false` when the installed
  version did not actually change after the upgrade (e.g. a premium plugin/theme without a
  valid license "succeeds" in WordPress but stays on the same version). A `reason` field
  (`version_unchanged` | `upgrade_failed`) is added. Prevents false-positive maintenance
  records on the manager side.

= 1.9.2 =

Traçage de la mise à jour du cœur WordPress :

* **Nouveauté** — la commande `update_core` renvoie désormais la version installée
  **avant** et **après** la mise à jour (`version_before` / `version_after`). Le
  tableau de bord G2RD peut ainsi tracer précisément le changement de version du
  cœur (ex. « WordPress mis à jour · 6.4 → 6.5 ») dans la maintenance du site et le
  portail client, au lieu d'un simple « à jour ».

= 1.9.1 =

Fiabilité de l'optimisation de la base de données :

* **Correctif** — `optimize_database` ne lance désormais `OPTIMIZE TABLE` que sur
  les tables réellement fragmentées (overhead > 0), au lieu de toutes les tables du
  préfixe WP. La durée de l'opération chute fortement sur les gros sites, ce qui
  évite de dépasser le délai d'attente du tableau de bord (faux « échec »). Le
  résultat renvoie l'overhead avant/après et le nombre de tables optimisées.

= 1.9.0 =

Enrôlement depuis l'onglet « Manager G2RD » :

* **Nouveauté** — l'onglet React « Manager G2RD » (Apparence → Options G2RD)
  permet désormais d'enrôler le site directement : champ *Token d'invitation* et
  boutons *Enrôler le site* / *Enregistrer* / *Déconnecter du manager*, via un
  point de terminaison REST d'administration locale (`g2rd/v1/admin`) gardé par
  la capacité `manage_options` et un nonce.
* **Correctif** — l'enrôlement était impossible lorsque le thème G2RD (>= 1.19)
  était actif : seul l'onglet React s'affichait, sans champ token ni bouton
  d'enrôlement. Le formulaire PHP autonome reste le repli pour les autres thèmes.

= 1.8.0 =

Maintenance & santé de la base (façon ManageWP) :

* **Snapshot enrichi** — le point de terminaison `/snapshot` renvoie désormais un
  bloc `db_health` : compteurs de commentaires indésirables, révisions d'articles,
  articles à la corbeille, transients expirés, taille des options autoloadées et
  overhead de la base (Mo). Lecture seule ; le manager affiche ces compteurs.
* **Deux nouvelles commandes distantes** — `empty_trashed_posts` (vide la
  corbeille, suppression définitive) et `delete_expired_transients` (purge les
  transients expirés). La purge des transients d'`optimize_database` a été
  factorisée et réutilisée.

= 1.7.1 =

Correctif critique — réactivation après mise à jour :

* **Le connecteur se réactive désormais « quoi qu'il arrive » après une commande
  `update_plugin`**, y compris lorsqu'il se met à jour LUI-MÊME. WordPress
  désactive silencieusement un plugin actif pendant l'upgrade
  (`deactivate_plugin_before_upgrade`) sans le réactiver en contexte REST/cron.
  Si la requête mourait (erreur fatale ou dépassement de `max_execution_time`)
  entre cette désactivation et la réactivation — ou si l'upgrade renvoyait une
  `WP_Error` qui interrompait le flux avant la réactivation — le plugin restait
  éteint. Étant éteint, plus aucun cron ne tournait pour le rétablir : le site
  devenait injoignable par le manager, irrécupérable à distance. La réactivation
  est désormais (1) protégée par un filet `register_shutdown_function` qui
  s'exécute aussi en cas d'arrêt anormal, et (2) idempotente, avec écriture
  directe de l'option `active_plugins` en dernier recours si `activate_plugin()`
  échoue.

= 1.7.0 =

Nouveautés (pilotées par le manager) :

* **Commande `update_translations`** — met à jour les packs de langue du cœur,
  des plugins et des thèmes via `Language_Pack_Upgrader` (mode silencieux,
  contexte REST/cron). Renvoie le nombre de packs mis à jour / en échec.
* **`site_icon_url` dans le snapshot** — expose le vrai favicon (Site Icon réglé
  dans l'admin WP, via `get_site_icon_url()`) pour que le manager l'affiche dans
  ses pages de gestion (repli sur l'icône générique si absent).

= 1.6.7 =

Durcissement suite à l'audit sécurité du 2026-06-21 (3 findings LOW) :

* **`/health` (public) minimal (anti-fingerprinting)** — ne divulgue plus
  `wp_version` / `php_version` / le statut d'enrôlement à un appelant anonyme ;
  ne renvoie que `ok` + `connector_version`.
* **`site_token` chiffré au repos** (AES-256-CBC, clé dérivée des sels WP, hors
  DB) : un dump SQL seul ne l'expose plus. Migration auto des installs legacy
  au démarrage.
* **Vérification SHA-256 des mises à jour** — le `GitHubUpdater` contrôle le
  hash publié (asset `.sha256`) du ZIP avant installation ; toute divergence
  avorte l'installation.

= 1.6.6 =

* **Fix (root cause): the `/snapshot` REST response is no longer cached by
  LiteSpeed / LSCWP**. The endpoint is authenticated with a Bearer SiteToken, so
  to a page cache it looks like a cacheable anonymous GET. LiteSpeed was serving
  a **frozen** snapshot to the manager — the connector PHP (and all its internal
  cache-busting, including the 1.6.4/1.6.5 fixes) never even ran on a cache HIT,
  so updated plugins kept showing as "update available" no matter how many times
  the manager resynced (`x-litespeed-cache: hit` on `/snapshot`). The connector
  now opts every `g2rd/v1` REST response out of caching via the official
  `litespeed_control_set_nocache` action plus `X-LiteSpeed-Cache-Control` /
  `Cache-Control: no-cache` headers (hooked on `rest_pre_dispatch`). This is the
  real fix for the recurring phantom-update reports; 1.6.4/1.6.5 addressed the
  object-cache and realpath/stat-cache layers, which only mattered on cache
  MISSes.

= 1.6.5 =

* **Fix: a plugin/theme updated through the manager (or auto-updated) is no
  longer reported as still needing that update**. Follow-up to 1.6.4, which
  fixed the *object cache* layer but left a second one untouched: the
  per-worker **realpath/stat cache**. When a plugin is updated its directory is
  replaced (new inode); a long-lived PHP-FPM/lsphp worker can keep the old inode
  cached, so `get_plugin_data()` re-reads the pre-update `Version:` header (e.g.
  `3.1.0`) for a file that is already `3.1.5` on disk — a phantom "update
  available" that survived every resync. Reproduced on Hostinger with FluentCRM
  Pro (`fluentcampaign-pro`). The `/snapshot` endpoint now calls
  `clearstatcache( true )` before reading plugin/theme versions, plus a targeted
  `clearstatcache( true, $path )` per file (the `true` flag also flushes the
  realpath cache — the no-argument form does not).
* **Hardening: the connector now invalidates its own compiled bytecode too**.
  The defensive OPcache pre-invalidation only globbed `wp-content/plugins/*/*.php`,
  which covers a plugin's root file but not nested classes
  (`includes/Rest/…`, `includes/Commands/…`). Under
  `opcache.validate_timestamps=0` a stale compiled `SnapshotController` could keep
  running after the connector itself was updated. It now recursively invalidates
  `G2RD_CONNECTOR_DIR/includes` and the main plugin file.

= 1.6.4 =

* **Fix: a plugin/theme that is already up to date is no longer reported as
  needing an update**. The `/snapshot` endpoint read `version_installed` from
  `get_plugins()`, which caches the whole plugin list in the `plugins` object
  cache group. On hosts with a persistent object cache (LiteSpeed / Redis on
  Hostinger), that cache could survive `wp_cache_flush()` /
  `wp_clean_plugins_cache()` between REST requests and return the pre-update
  `Version:` header (e.g. `2.5.0`) even though `wp-admin → Plugins` and the disk
  already showed `2.6.0`. The manager then kept proposing an update that was
  already applied. The `wp_cache_flush()` added in 1.6.2 was not enough on these
  hosts. The endpoint now reads the installed version **fresh from each file**
  via `get_plugin_data()` / `get_file_data()` (a direct file read, bypassing the
  object cache entirely) for both plugins and themes — exactly what the
  `wp-admin` Plugins screen does.

= 1.6.3 =

* **Fix: an active plugin is no longer deactivated by `update_plugin`**.
  `Plugin_Upgrader::upgrade()` silently deactivates an active plugin before the
  file swap (core's `deactivate_plugin_before_upgrade`) and does not reactivate
  it in a REST/cron context. The command now records the activation state before
  the upgrade and silently reactivates the plugin afterwards (network-aware).
  The command response gains `was_active` and `reactivated` booleans.

= 1.6.2 =

* **Snapshot freshness defense in depth** : `wp_cache_flush()` is now
  called inside the `/snapshot` REST endpoint BEFORE
  `wp_clean_plugins_cache` / `wp_clean_themes_cache`. On hosts that ship
  a persistent object cache (Redis, Memcached, LiteSpeed Object Cache,
  Hostinger AI Assistant cache wrapper, etc.), `get_plugins()` could
  otherwise return a stale cached `Version:` header for a plugin that
  was just updated. The site's `wp-admin → Plugins` page would correctly
  show the new version (1.6.1) but the manager would still see and
  report the old one (1.5.1). This flush guarantees the snapshot always
  reads fresh data from disk, at the cost of one extra cache rebuild
  per sync (~50-300 ms depending on the cache backend). Pattern aligned
  with the `clear_cache` command which already does it.
* Also pre-invalidates OPcache for `wp-content/plugins/*.php` and
  `wp-content/themes/*/{style.css,functions.php}` defensively, in case
  the host runs with `opcache.validate_timestamps=0` (some PHP-FPM
  pools on shared hosting).

= 1.6.1 =

* **Plugin Check conformance**: exception messages in the `update_plugin` /
  `update_theme` commands are now escaped with `esc_html()`
  (`WordPress.Security.EscapeOutput.ExceptionNotEscaped`). No behaviour change.

= 1.6.0 =

* **2 new commands : `update_plugin` and `update_theme`**. The manager can
  now trigger a real WordPress update on a specific plugin or theme via
  `POST /wp-json/g2rd/v1/command` with a `payload` carrying the file path
  (`{file: "akismet/akismet.php"}`) or stylesheet
  (`{stylesheet: "twentytwentyfour"}`). Backed by `Plugin_Upgrader` and
  `Theme_Upgrader` with an `Automatic_Upgrader_Skin` (no output).
* **Defensive whitelist**: both commands check that the target is
  actually installed via `get_plugins()` / `wp_get_themes()` before
  invoking the upgrader. Arbitrary path injection (e.g.
  `../../wp-config.php`) is rejected with a clear error message.
* **Forced transient refresh before upgrade**: `delete_site_transient()`
  on `update_plugins` / `update_themes` is called inside the upgrade
  command itself, so custom GitHub Updaters re-evaluate against their
  remote API right before the upgrade fires.
* **`payload` parameter on `POST /command`**: the REST endpoint now
  accepts an optional `payload` object alongside `command`. Legacy
  commands (clear_cache, update_core, etc.) ignore it silently.
* **HeartbeatJob drains payloads too**: when the manager queues an
  `update_plugin` / `update_theme` command, the cron drain reads the
  `payload` from the response and passes it to
  `CommandExecutor::run()`.
* **`CommandExecutor::run()` signature extended** with an optional
  second `$payload` parameter. Backwards compatible with v1.5.x
  callers that only pass the command name.

= 1.5.2 =

* **WordPress.org Plugin Check conformance** (hardening, no behaviour change):
  * `/snapshot`: `$_SERVER['SERVER_SOFTWARE']` is now unslashed and sanitized.
  * `register_setting()` now declares a `sanitize_callback` (`Settings::sanitize()`).
  * `includes/autoload.php` now blocks direct file access (`ABSPATH` guard).
  * Direct maintenance DB queries (OPTIMIZE TABLE, transient cleanup) annotated.
  * readme.txt: disclosed the GitHub Releases API as an external service and
    bumped "Tested up to" to 7.0.
* CI: added an automated WordPress Plugin Check workflow on every push/PR.

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
