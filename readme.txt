=== Event Mirror ===
Contributors: yourname
Tags: events, eventbrite, sync, calendar, mirror
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mirror your Eventbrite events into WordPress as native posts, kept in sync automatically. Works with Eventbrite.

== Description ==

Event Mirror pulls events from your Eventbrite account and keeps them in sync as
native WordPress posts, so you can display a "What's On" page that updates itself.

* Paste your Eventbrite API token — no complex setup.
* Events are mirrored on a schedule (hourly, twice daily, or daily).
* Created, updated, and removed events stay in sync automatically.
* Display events with the `[event_mirror]` shortcode.
* A simple activity log shows exactly what each sync did.

Event Mirror is an independent, third-party tool. It is not affiliated with,
endorsed by, or sponsored by Eventbrite. "Eventbrite" is a trademark of its
respective owner; it is used here only to describe compatibility.

== Installation ==

1. Install and activate the plugin.
2. Go to Event Mirror → Settings and paste your Eventbrite API token.
3. Click "Sync now" to pull your events, or wait for the scheduled sync.
4. Add the `[event_mirror]` shortcode to any page to display them.

== Frequently Asked Questions ==

= Where do I get an Eventbrite API token? =
In your Eventbrite account under Account Settings → Developer → API Keys.

= Does this work for low-traffic sites? =
The schedule uses WP-Cron by default. For reliable timing on quiet sites, point
a real server cron job at wp-cron.php (see the setup guide).

== Changelog ==

= 0.8.0 =
* New: "Events (grid)" block for the block editor — the block equivalent of the [event_mirror] shortcode, with column/limit/filter controls.
* New: "How to Display" admin page with wireframes, copy-paste shortcodes, and block references.
* New: DS.Emotion (DSE 2026) house styling across the plugin's admin pages (no external fonts).
* New: optional Event structured data (JSON-LD) on the events archive, attributing each event to its Eventbrite listing (off by default).
* Change: individual event pages now return 404 — events are shown only as cards and in the listings/archive page.
* Change: sync now stores structured address, ticket price, and local timezone for richer structured data (populated on the next sync).
* Fix: calendar prev/next navigation now works (evmr_month registered as a query var) and no longer jumps to the top of the page.
* Fix: event grid collapses to a single column on small screens for columns="2" and columns="3".
* Fix: [event_mirror_card] with an invalid event_id now shows an editor-only note instead of rendering nothing.

= 0.1.0 =
* Initial proof of concept: token settings, sync engine, manual sync, shortcode display, activity log.
