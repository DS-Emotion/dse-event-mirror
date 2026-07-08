=== Event Mirror ===
Contributors: yourname
Tags: events, eventbrite, sync, calendar, mirror
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.7.3
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

= 0.1.0 =
* Initial proof of concept: token settings, sync engine, manual sync, shortcode display, activity log.
