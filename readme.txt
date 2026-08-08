=== Admin Query Profiler for WooCommerce ===
Contributors: zawadmonsur
Tags: woocommerce, performance, debug, database, orders
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds out which plugin is making your WooCommerce admin slow, by attributing every database query to the plugin that caused it.

== Description ==

Your WooCommerce orders screen takes forever to load. You have migrated to High-Performance Order Storage, cleaned the database, and it is still slow. Now what?

The usual advice is to deactivate plugins one at a time until it speeds up. This plugin tells you which one to deactivate.

Press **Scan this screen** in the admin bar. The profiler records every database query that screen runs, works out which plugin caused each one, and reports the result in plain English:

> order-delivery-date-for-example runs about 2.0 queries for every order shown. At 100 rows that is roughly 205 queries from this one plugin.

= Why not just use Query Monitor? =

Query Monitor is an excellent developer tool and this plugin is not a replacement for it. But its "Component" column tells you which code **ran** a query, not which code **caused** it.

That distinction matters more than it sounds. When a third-party plugin calls `wc_get_order()` inside an order list column, the query is issued by WooCommerce's own data store — so naive attribution blames WooCommerce and lets the real culprit off completely. In testing, that error attributed 3 queries per row to WooCommerce when its actual cost was 1, hiding a plugin responsible for the other 2.

This plugin blames the innermost frame that a WordPress hook invoked — the last point where control passed from WordPress into plugin code — which identifies the initiator instead.

= It will not guess =

A single page load genuinely cannot tell a **per-row** cost from a **fixed** cost. A plugin firing a constant 19 queries looks like "0.95 per row" on a 20-row page and "0.19 per row" on a 100-row page, without anything having changed.

So the first scan reports counts only and asks you to change the page size and scan again. The second scan compares the two and reports the slope. Only a cost that grows with the row count is an N+1.

That is slower than showing a verdict immediately, and it is the entire reason the verdict can be trusted.

= What it does not do =

* It does not change anything or attempt any fixes. It measures and reports.
* It does not run for visitors. Profiling happens only when you press the button, and only for users who can manage WooCommerce.
* It does not phone home, collect analytics, or contact any external service.

= Query timings =

Query **counts** and attribution work with no configuration. Per-query **timings** additionally require `SAVEQUERIES` in your `wp-config.php`:

`define( 'SAVEQUERIES', true );`

That constant roughly doubles the memory a page load uses, so turn it on to investigate and off again afterwards. N+1 detection does not need it.

== Installation ==

1. Install and activate the plugin.
2. Open a slow admin screen — usually WooCommerce → Orders.
3. Click **Scan this screen** in the admin bar.
4. Change the number of rows per page (Screen Options, top right) and scan again.
5. Read the verdict.

== Frequently Asked Questions ==

= Is it safe to run on a live site? =

Profiling only happens on the single request where you press the button, and only for logged-in users who can manage WooCommerce. Every other request is untouched. That said, profiling a request makes it slower and uses more memory, so do not leave scans running in a loop on a busy store.

= Why does it say it cannot tell me anything after one scan? =

Because after one scan it genuinely cannot. See "It will not guess" above. Change the page size and scan again.

= The verdict names WooCommerce itself. Now what? =

Then the cost is in WooCommerce core rather than an add-on, and deactivating plugins will not help. Look at what else scales with store size — order status counts are a common one on large stores, and they are cached in the object cache, so a persistent object cache can remove them entirely.

= Does it work without WooCommerce? =

It will run and profile any admin screen, but it is built and tested for WooCommerce order screens.

= Will it conflict with Query Monitor? =

No. Query Monitor is explicitly excluded from attribution — its database dropin wraps every query, so without that exclusion it would be blamed for all of them. Run both together happily.

== Screenshots ==

1. The scan result on a WooCommerce orders screen, naming the plugin responsible.
2. The first scan, reporting counts and asking for a second scan at a different page size.

== Changelog ==

= 0.1.0 =
* Initial release.
* Query attribution by hook-invocation boundary.
* Two-scan slope comparison for N+1 detection.
* Works with or without SAVEQUERIES.
