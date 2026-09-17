# Changelog

All notable changes to the Voris WordPress plugin. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The user-facing summary lives in `readme.txt`; this file carries the engineering detail.

## [0.1.4] — 2026-09-17

Transparency, consent, and durability. Adds an in-admin privacy/consent surface with WP Consent API
integration, hardens revenue delivery and the OAuth refresh against silent loss, corrects two
WooCommerce commerce-accounting bugs, makes the connect flow translatable, and polishes the admin
screen. No change to what data the plugin collects or sends to Voris ingest.

### Added

- **Privacy & data sharing** section on the Tools → Voris screen: the plugin's Mode A posture and
  what Modes B/C mean (VOR-312), the consent-plugin situation (VOR-313), and an itemised list of
  what WooCommerce data is and is not sent (VOR-314).
- **WP Consent API integration** (`Voris_Consent`, ADR-ACH005). The plugin registers as
  consent-aware under `statistics-anonymous` and gates the hosted tag on
  `wp_has_consent()` when a compatible consent manager is active; with none present the tag renders
  exactly as before. A new `voris_tag_has_consent` filter is the escape hatch for unlisted managers.
- Detection of common consent plugins (CookieYes, Complianz, Cookiebot, Borlabs Cookie, Real Cookie
  Banner, WPConsent) to phrase the settings message; detection never drives the gate.
- **Settings link on the Plugins screen (VOR-317).** The plugin's row on `wp-admin/plugins.php` now
  carries a **Settings** link to Tools → Voris, so a just-activated site can jump straight to setup.

### Changed

- `Voris_Tag::render()` now also checks `Voris_Consent::has_consent()` after its existing
  connected/enabled guards.
- Corrected stale in-code and metadata claims to match shipped behaviour (VOR-309): the admin-page
  docblock no longer says the plugin adds no admin-ajax endpoint (the connect flow registers three and
  nudge dismissal one) and names the screen's real home (Tools → Voris); the dispatcher's shutdown-drain
  comment no longer claims to cover events raised by a *later* `shutdown` callback (it does not); and
  the developer README, `package.json`, and `docs/developers/integrating.md` no longer describe the
  plugin as bootstrap-only or as shipping no JavaScript, now that the Phase 2 admin scripts
  (`assets/js/connect.js`, `assets/js/nudge.js`) ship.
- **The Tools → Voris heading now shows the Voris wordmark (VOR-316)** — a shipped SVG rendered
  inside the `<h1>`, so the accessible heading name stays exactly "Voris". Presentation only.
- **Connect-flow runtime strings are now translatable (VOR-308).** Every user-facing string in
  `assets/js/connect.js` is wrapped for `wp.i18n`, and the `voris-connect` script depends on
  `wp-i18n` and registers `wp_set_script_translations()` for the `voris` text domain, so the in-admin
  connect copy can finally be localized. `languages/voris.pot` is regenerated (`composer make-pot`)
  and a pot-freshness CI gate keeps the catalog current. No change to tracking, revenue, or connect
  behaviour.

### Fixed

- **Delivery durability + OAuth-refresh correctness (VOR-300–303, ADR-A121).** Four validated
  residuals that could silently lose already-captured revenue or disconnect a site:
  - *VOR-300* — the under-lock OAuth-refresh re-read was served from the WP object cache, missing a
    concurrent rotation and re-presenting a poison refresh token → grant-family revoke. The re-read is
    now authoritative (`wp_cache_delete` first) and the `GET_LOCK` name is scoped per install.
  - *VOR-301* — deactivating cleared the drain cron but kept the queue and never rescheduled on
    reactivate, stranding captured revenue. `Voris_Dispatcher::reconcile_schedule()` now re-arms the
    drain on activate and on upgrade.
  - *VOR-302* — an event raised by a `shutdown` callback ordered after the flush was delivered by
    neither the loop nor a re-arm. `dispatch()` now persists it durably by event id so the drain
    delivers it.
  - *VOR-303* — the first shutdown flush had no count/time budget, so a bulk completion during a Voris
    outage could block the request or strand events on a fatal. It now applies the drain's per-run
    caps (`MAX_PER_RUN` / `MAX_RUN_SECONDS`) and enqueues the remainder for the cron drain.

- **WooCommerce refund corrections could reverse revenue against the wrong Voris workspace
  (VOR-304).** A confirmed purchase now pins the destination site id it was delivered to
  (`_voris_purchase_site_id` order meta, taken from the delivered event). A later refund is
  refused — and recorded in the delivery log as `purchase_site_mismatch` — when that pinned
  workspace differs from the one connected at refund time, instead of silently mis-routing the
  correction to a workspace that never saw the sale. Orders confirmed before this change (no
  pinned site) fall back to the connected site id, so existing stores that never changed
  workspace are unaffected. See ADR-ACH006.
- **Concurrent WooCommerce order callbacks could double-count revenue (VOR-305).** The
  exactly-once guard was a non-atomic read-then-write of order meta: two completion (or refund)
  callbacks for the same order running at once could both mint a different event id and both
  dispatch, which ingest — deduplicating on event id — counted twice. The purchase and refund
  ops now take a non-blocking MySQL advisory lock (`GET_LOCK`, the same primitive as the OAuth
  refresh) before minting, so a concurrent callback that cannot claim the op mints nothing.
  Storage-agnostic, so it holds under classic and High-Performance Order Storage alike. See
  ADR-ACH007.

### Security

- Release provenance gate (VOR-310). The Release workflow and the dormant WordPress.org deploy now
  refuse to build or publish a `v*` tag unless its commit is on `main` **and** has a green CI run,
  via `bin/verify-release-provenance.sh`. Previously either workflow would ship any `v*` tag wherever
  it pointed, checking only that the tag matched the plugin version. See
  `docs/developers/releasing.md` (which also recommends a GitHub tag ruleset restricting who may push
  `v*` tags).

## [0.1.3] — 2026-09-16

Hardens the in-admin **connect flow** so no state strands the owner, and stops a stalled attempt cleanly
(spec ACH002; VOR-298, VOR-299, VOR-306, VOR-307). No change to what is tracked or sent to Voris ingest.

### Fixed

- **A connection interrupted at mint no longer strands (VOR-298).** When the token minted but the follow-up
  onboarding read failed once, the screen reloaded into a "connected" state that hid Connect and locked the
  site-id field, with nothing left to retry the bind. The flow now distinguishes *binding / attesting /
  tracking*, retries a failed install attestation instead of skipping it, keeps the recovery controls visible
  until the connection is fully established, and resumes the poll on load (no click needed).
- **A blocked approval pop-up has a fallback (VOR-299).** Approval is now a persistent, keyboard-focusable
  link plus the visible user code; `window.open` is best-effort only, so a browser that blocks it no longer
  leaves the owner with no way to approve.
- **In-admin disconnect / switch account (VOR-306).** A nonce- and capability-gated Disconnect control drops
  the stored credential (keeping the manual site id, so manual tracking survives) and clears the cached
  plan/usage and dismissed upgrade prompts so a later connection to a different account starts clean.
- **The device-code poll is bounded (VOR-307).** It now stops at the code's advertised deadline with a
  recoverable "expired — click Connect again" state, even when Voris was unreachable the whole time, instead
  of retrying indefinitely.

### Changed

- Schema version advances to **2**: a forward-only migration backfills the new attestation flag onto grants
  connected under an earlier version, so an already-connected site is not misread as still-connecting. This is
  the first release with a migration — it is **forward-only and downgrade-safe**: any 0.1.x version reads the
  same data (the extra key is ignored), so a rollback needs no action.
- Repaired the Playwright end-to-end suite and gated it in CI, with a reproducible global setup and a
  deterministic ingest mock, so a browser regression fails a PR like a PHPUnit one (internal — no user impact).

## [0.1.2] — 2026-09-16

Prepared for submission to the WordPress.org plugin directory (spec A072). No behavioural change to
tracking, revenue, or connect — this release adds the required disclosure, the listing assets, and
the directory slug.

### Added

- **External-service disclosure (wp.org Guideline 7).** An `== External service ==` section in
  `readme.txt` and a disclosure line on the Tools → Voris screen name Voris and its operator
  (Rocket Venture Labs B.V.), enumerate the three hosts the plugin contacts (`cdn.voris.ai`,
  `ingest.voris.ai`, `app.voris.ai`) and what each sends, and link the Terms of Service and Privacy
  Policy. A test asserts the disclosed host set equals the hosts the shipped code actually contacts
  (and that `mcp.voris.ai` is in neither).
- WordPress.org listing assets under `.wordpress-org/assets/` — icon (128×128, 256×256, SVG), banner
  (772×250 and 1544×500), and two screenshots — plus a `composer plugin-check` step wired into CI.

### Changed

- Plugin slug and text domain renamed `voris-analytics` → `voris`; the main file is now `voris.php`.
- Listing copy uses "privacy-first" consistently, and `Tested up to` is set to WordPress 7.1.

## [0.1.1] — 2026-09-13

### Changed

- The plugin's admin screen moved from **Settings → Voris** to **Tools → Voris** — a connector
  fits Tools better than Settings. The page hook suffix is now single-sourced
  (`Voris_Admin_Page::HOOK_SUFFIX`) so the connect/nudge script enqueues and the plan
  background-refresh hook track the menu location, and the screen prints its own settings-validation
  errors (a Tools page does not get core's automatic `options-head.php` notices).
- `readme.txt` Description and Installation now cover the Phase 2 surface (one-click connect, plan
  awareness).

## [0.1.0] — 2026-09-13

First release. ACH001 Phase 1 (manual site-id connection, tracking, WooCommerce revenue, platform
hygiene) and Phase 2 (in-admin one-click connect, plan awareness, and upsell nudges) built on the
frozen Voris A067 partner surface.

### Added

- **Connect a site with its Voris site ID.** Settings → Voris, administrators only. A malformed id
  is rejected with a clear message and never saved; submitting an empty id disconnects.
- **One-click connect to Voris (device flow).** A "Connect to Voris" button opens the Voris-hosted
  approval page in a new tab and polls until the grant is minted, then binds the site and writes its
  id automatically. The credential is stored in a non-autoloaded, administrator-only option, refreshed
  with token rotation, and removed on uninstall even when settings are kept.
- **Plan &amp; usage awareness.** The settings screen shows the workspace plan usage against the served
  cap; figures come from Voris (never hardcoded), refreshed in the background so no admin request
  waits on a Voris call.
- **Upsell nudges from the served plan state** — approaching-cap (dismissible per threshold), at-cap
  (trial / free / paid worded honestly), billing lock, past-due, and a second-site check. Nudges are
  administrator-only and link out to app.voris.ai; no payment or checkout happens in WordPress.
- **Pageviews and Core Web Vitals** through Voris's hosted tag, rendered on the front end when a
  site is connected and tracking is on. The plugin ships no tracker code and adds no storage.
- **WooCommerce revenue** reported from the server when an order reaches Completed, so it is
  counted despite ad-blockers, page caches, and off-site payment redirects.
- **Refunds** reported as negative revenue corrections, full and partial.
- **Bounded delivery with retries.** First attempt on `shutdown`, then at most two more via single
  WP-Cron events at five and thirty minutes, with an admin-visit fallback for sites where WP-Cron
  does not fire. Retries reuse the original event id so Voris deduplicates.
- **Delivery log** on the settings screen listing events that could not be sent, in terms the site
  owner can act on.
- **Uninstall removes everything the plugin stored**, unless the owner opted to keep settings.
  Deactivation deletes nothing.
- Translation-ready throughout, with `languages/voris.pot`.

### Security

- Endpoints are class constants rather than `define()`d globals. A global constant keeps whichever
  value was set first, and both `wp-config.php` and mu-plugins run before plugins load — so a global
  endpoint could be repointed, and a host answering 200 would have been recorded as a successful
  delivery.
- The queue drain runs only for users who can `manage_options`. `admin_init` is not an authenticated
  hook: core fires it in `admin-ajax.php` and `admin-post.php` before either checks who is asking.
- Certificate verification is forced on for ingest requests, so a site-wide snippet disabling
  `sslverify` cannot downgrade them.
- The settings sanitizer is registered at bootstrap, so writes from WP-CLI, cron, or another plugin
  are validated too.
- Order and refund references are refused unless they match `^[A-Za-z0-9._-]{1,64}$`. WooCommerce
  order numbers are filterable and can be templated from customer fields.

### Privacy

- No visitor IP is collected or transmitted, in any mode.
- No customer name, email address, or postal address leaves the site, including from an order.
- No visitor, session, or user identifier is ever sent. Voris derives identity, geography, and
  device server-side.
- Event properties carry only order references, counts, and flags.

### Known limitations

- Uninstall cleans the current site only. On multisite, other subsites retain their data.
- High-Performance Order Storage compatibility is declared and implemented, and the cleanup path was
  verified by hand on an HPOS store. The automated suite still runs against legacy post-meta storage,
  so nothing guards that path against regression.

[0.1.4]: https://github.com/Rocket-Venture-Labs/voris-plugin-wordpress/releases/tag/v0.1.4
[0.1.3]: https://github.com/Rocket-Venture-Labs/voris-plugin-wordpress/releases/tag/v0.1.3
[0.1.2]: https://github.com/Rocket-Venture-Labs/voris-plugin-wordpress/releases/tag/v0.1.2
[0.1.1]: https://github.com/Rocket-Venture-Labs/voris-plugin-wordpress/releases/tag/v0.1.1
[0.1.0]: https://github.com/Rocket-Venture-Labs/voris-plugin-wordpress/releases/tag/v0.1.0
