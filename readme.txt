=== Voris — Privacy-first Analytics ===
Contributors: rocketventurelabs
Tags: analytics, privacy, woocommerce, statistics, core web vitals
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first analytics for WordPress and WooCommerce. Connect to Voris in one click — no code, no cookie banner.

== Description ==

Voris is a privacy-first analytics platform. This plugin connects your WordPress site to it without editing a theme, pasting a snippet into a code-snippets plugin, or losing your setup on the next theme update.

**What it does**

* **Connects in one click.** "Connect to Voris" opens Voris's approval page, and the plugin binds this site and stores the connection for you — no copy-pasting IDs. You can still enter a site ID by hand if you prefer.
* **Counts visits.** Once connected, every public page reports pageviews and Core Web Vitals to Voris, exactly as Voris's standard install does.
* **Keeps you ahead of your plan.** The settings screen shows your usage against your plan, with a nudge as you approach the limit. Plan figures come from Voris, so an upgrade is reflected without updating the plugin. No payment or checkout happens in WordPress.
* **Reports store revenue.** With WooCommerce active, completing an order reports the order total and currency to Voris from your server — so revenue is counted even when a visitor uses an ad-blocker, your pages are cached, or payment happens on the gateway's own site.
* **Corrects refunds.** Refunding an order, in full or in part, reports the matching correction.
* **Stays out of the way.** If Voris is slow or unreachable, your pages, your admin, and your checkout carry on exactly as if the plugin were not installed. Failed deliveries are retried in the background and, if they still cannot be sent, listed for you on the settings screen.

**What it never sends**

No visitor IP address. No names, email addresses or postal addresses — not even from an order. No visitor, session or user identifiers. Voris works out geography and device from the request on its own side and discards the IP; the plugin's job is to send the event, nothing more. (This describes the plugin's own events; the browser tag's per-mode behavior is set out under "External service" below.)

== External service ==

This plugin is a client for **Voris**, a privacy-first analytics service operated by **Rocket Venture Labs B.V.** (Amsterdam, the Netherlands). It is not a standalone plugin: analytics are collected and shown by Voris, so using it means sending data to Voris. The plugin contacts Voris in three ways:

* **Browser analytics tag** — on every public page, the plugin loads Voris's tracking script from `https://cdn.voris.ai/v1/vor.js` (deferred). It reports pageviews and Core Web Vitals to Voris. In Voris's default privacy mode (Mode A) it sets no cookies, uses no browser storage, and creates no visitor identifier. If your Voris site is configured for Mode B or C, identifiers are derived on Voris's side according to your configuration and your own legal setup.
* **Server events** — once connected, and with WooCommerce active, the plugin sends order and refund events from your server to `https://ingest.voris.ai/api/event`, so revenue is counted even behind a cache or an ad-blocker. These events carry no IP address, no names or addresses, and no visitor, session or user identifiers.
* **Connection & plan data** — connecting your site (one-click OAuth) and reading your plan usage happen against `https://app.voris.ai` (`/oauth/*` and `/api/partner`). Nothing is sent to Voris until you connect.

Voris derives geography and device on its own side and discards the IP address; it is never stored. Data is processed primarily in the EU, with some sub-processors elsewhere — the Privacy Policy has the specifics.

By connecting your site you agree to Voris's terms and privacy practices:

* Terms of Service: https://voris.ai/legal/terms/
* Privacy Policy: https://voris.ai/legal/privacy/

== Installation ==

1. Install and activate the plugin.
2. Go to **Tools → Voris**.
3. Click **Connect to Voris** and approve in the tab that opens — or paste your site ID by hand (find it in your site's settings in app.voris.ai; it looks like `3f2504e0-4f89-41d3-9a0c-0305e82c3301`).
4. Save. Tracking starts immediately.

== Frequently Asked Questions ==

= Do I need a cookie banner? =

Not for Voris's default privacy mode. In Mode A the tracker sets no cookies and uses no browser storage, and no visitor identifier is ever created. If your Voris site is configured for Mode B or C, consent is part of your own legal setup rather than something this plugin decides.

= Does it work with my consent plugin? =

Yes. Voris registers with the WP Consent API, which most consent plugins (CookieYes, Complianz, Cookiebot, Borlabs Cookie, Real Cookie Banner and others) support. When one of these is active and set to require statistics consent, Voris's tracking tag waits for that consent before it loads. When no consent plugin is present, Mode A needs no banner, so the tag loads as usual. The settings screen tells you which state you are in.

= I changed my settings and nothing seems different =

If your site uses a page cache, the old HTML is still being served. Purge the cache and the change takes effect. The plugin reminds you of this whenever you save.

= My store's revenue is not showing up =

Revenue is reported when an order reaches **Completed**, not when it is paid. Stores that leave orders in Processing indefinitely will not see revenue in Voris. Virtual and downloadable orders that auto-complete report immediately.

= A refund did not show up =

A refund is only reported when Voris confirmed receiving the original purchase. If the purchase could not be delivered, reporting the refund would leave your dashboard showing negative revenue against a sale Voris has no record of, so the plugin skips it and notes the skip in the delivery log.

= Does it work without WooCommerce? =

Yes. Commerce features simply do not appear, and nothing errors.

= What happens if I uninstall? =

Everything the plugin stored is removed. If you would rather keep your settings for later, tick **Keep my settings if I uninstall Voris** on the settings screen first. Deactivating never deletes anything.

= Does it slow my site down? =

No. The tracking tag is loaded from Voris's CDN with `defer`, and revenue events are sent after the page has finished rendering, with a two-second ceiling. Nothing about Voris blocks a page, an admin screen, or a checkout.

== Screenshots ==

1. Everything on one screen — connect your site, see that it is live, control tracking and what happens to your data on uninstall, and read in plain language exactly what is shared with Voris.
2. Connect in one click: "Connect to Voris" opens Voris's approval page and binds this site, or paste your site ID by hand.

== Changelog ==

= 0.1.4 =
* New "Privacy & data sharing" section on the settings screen: it explains your privacy mode and lists exactly what WooCommerce data is and isn't sent.
* Works with consent plugins: when a WP Consent API-compatible consent manager is active, the tracking tag waits for statistics consent; with none installed, nothing changes.
* More reliable revenue: fixes that could otherwise silently lose an order's revenue, double-count it, or file a refund against the wrong workspace — plus a cleaner reconnect after the plugin is deactivated and reactivated.
* Polish: a Settings link on the Plugins list, the Voris wordmark on the settings screen, and the connect flow is now fully translatable.

= 0.1.3 =
* Connecting to Voris is more reliable: an interrupted setup now recovers on its own instead of getting stuck, and the connection controls stay available until it finishes.
* The approval step always shows a clickable link and the code, so it still works when your browser blocks pop-ups.
* You can now disconnect, or switch Voris accounts, from the settings screen.
* A stalled connection attempt stops cleanly with a clear message instead of retrying forever.

= 0.1.2 =
* The settings screen now summarises exactly what is shared with Voris, with links to the Terms of Service and Privacy Policy.
* Prepared the plugin for the WordPress.org plugin directory — listing icon, banner, screenshots, and a full external-service disclosure in this readme. No change to tracking or your data.

= 0.1.1 =
* The Voris screen moved from Settings to **Tools → Voris**.

= 0.1.0 =
* First release.
* Connect a site with its Voris site ID, or connect in one click through Voris.
* Pageviews and Core Web Vitals via Voris's hosted tag.
* WooCommerce revenue on order completion, and corrections on refund.
* See your plan usage on the settings screen, with upgrade nudges as you approach your limit.
* Background retries with a visible delivery log when an event cannot be sent.
* Choose whether uninstalling keeps your settings.

== Upgrade Notice ==

= 0.1.4 =
Adds an on-screen privacy & consent summary with WP Consent API integration, hardens revenue delivery and refunds against silent loss or misfiling, and polishes the admin screen. No change to what is tracked or sent.

= 0.1.3 =
Makes connecting to Voris more reliable and recoverable, adds an in-admin disconnect / switch-account, and shows a clickable approval link when pop-ups are blocked. No change to what is tracked or sent.

= 0.1.2 =
Adds an on-screen summary of what is shared with Voris, with links to the Terms and Privacy Policy. No change to tracking or your data.

= 0.1.1 =
The Voris screen is now under Tools, not Settings.

= 0.1.0 =
First release.
