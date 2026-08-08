# Admin Query Profiler for WooCommerce

Finds out **which plugin** is making your WooCommerce admin slow, by attributing
every database query to the plugin that caused it.

Your orders screen takes forever. You've migrated to High-Performance Order
Storage, cleaned the database, and it's still slow. The usual next step is to
deactivate plugins one at a time until it speeds up. This tells you which one to
deactivate.

## Install

Download this repository as a ZIP and upload it via **Plugins → Add New → Upload
Plugin**, or clone it into `wp-content/plugins/`.

No configuration. Nothing runs until you ask it to.

## Use

1. Open a slow admin screen — usually **WooCommerce → Orders**.
2. Click **Scan this screen** in the admin bar.
3. Change the rows per page (**Screen Options**, top right) and scan again.
4. Read the verdict:

> order-delivery-date-for-example runs about 2.0 queries for every order shown.
> At 100 rows that is roughly 205 queries from this one plugin.

### Why it makes you scan twice

A single page load genuinely cannot tell a **per-row** cost from a **fixed** one.
A plugin firing a constant 19 queries looks like this:

| page size | its queries | "per row" |
|---:|---:|---|
| 20 | 19 | 0.95 — looks like a textbook N+1 |
| 100 | 19 | 0.19 — looks completely innocent |

Nothing changed but the denominator. So the first scan reports counts only, and
the second compares against it:

```
queries(n) = fixed + slope × n
```

Only `slope` is an N+1. Showing a verdict after one scan would mean guessing, and
in testing that guess accused an innocent plugin.

## Why not just use Query Monitor?

Query Monitor is an excellent tool and this is not a replacement for it. But its
**Component** column tells you which code *ran* a query, not which code *caused*
it.

That matters more than it sounds. When a third-party plugin calls
`wc_get_order()` inside an orders-list column, the query is issued by
WooCommerce's own data store — so naive attribution blames WooCommerce and lets
the real culprit off entirely:

| | all plugins active | control (suspect deactivated) |
|---|---:|---:|
| per-row queries blamed on `woocommerce` | 3.0 | **1.0** |

Two of those three came from a third-party column callback. This plugin blames
the **innermost frame a WordPress hook invoked** — the last point where control
passed from WordPress into plugin code — which finds the initiator instead.

```
… → ListTable->column_default        [woocommerce]
    → do_action('manage_…_column')   [dispatcher]
      → WP_Hook->apply_filters       [dispatcher]
        → SomePlugin->render_column  [the plugin]   ← blame this
          → wc_get_order             [woocommerce]
            → OrdersTableDataStore->read [woocommerce]
              → wpdb->get_results    [core]
```

Query Monitor is explicitly excluded from attribution — its database dropin wraps
every query, so without that exclusion it would be blamed for all of them. The
two run together fine.

## Query timings

Counts and attribution work with no configuration. Per-query **timings**
additionally require `SAVEQUERIES`:

```php
define( 'SAVEQUERIES', true );
```

That roughly doubles the memory a page load uses — turn it on to investigate,
off again afterwards. N+1 detection doesn't need it.

`SAVEQUERIES` can't be enabled by a plugin: `wpdb` tests the constant at query
time, so it must be set before WordPress boots. Query Monitor works around that
with a `db.php` dropin, but WordPress permits exactly one `db.php`, so doing the
same would make this incompatible with Query Monitor. Instead, without
`SAVEQUERIES` this hooks core's `query` filter, which yields counts and
backtraces but no timing.

## Safety

- Profiles only the single request where you press the button.
- Requires `manage_woocommerce` or `manage_options`, plus a valid nonce.
- Never runs for logged-out visitors.
- Captured queries are capped at 8,000 so the profiler can't exhaust memory.
- Changes nothing, fixes nothing, and contacts no external service.

Profiling a request does make that request slower and use more memory. Don't
leave scans running in a loop on a busy store.

## Status

Early — version 0.1.0. Validated against a synthetic 500,000-order store
(WooCommerce 11, HPOS, MySQL 8, 10 popular plugins), where it correctly
identified a plugin costing 2 queries per row; deactivating only that plugin
removed exactly the predicted 205 queries and cut the screen from 0.62s to 0.47s.

**Reports from real stores are very welcome** — especially anything where the
verdict looks wrong.

## License

GPL-2.0-or-later
