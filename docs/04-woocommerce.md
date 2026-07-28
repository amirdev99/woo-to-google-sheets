# 04 — `includes/WooCommerce/`

## Why this folder exists

**This folder is the only place in the plugin that knows WooCommerce's vocabulary.**
`WC_Order`, `woocommerce_order_status_changed`, `get_billing_email()`,
`WC_Order_Item_Product` — every WooCommerce-specific assumption is quarantined in these two
files.

That containment has a practical payoff. When WooCommerce introduced HPOS (High-Performance
Order Storage), moving orders out of `wp_posts` into custom tables, this plugin needed no
changes — because only these two files touch an order, and they do it through WooCommerce's
public API rather than the database.

The folder has a second, sharper division:

- **`Order_Listener`** reacts to *events*. It runs on the front end during checkout, so it
  must be fast and must never fail.
- **`Order_Mapper`** performs a *pure transformation*. No database, no API, no hooks — give
  it a `WC_Order`, get back arrays. It is the easiest file in the plugin to change safely.

---

## `class-order-listener.php` — `WTG\WooCommerce\Order_Listener`

**Purpose.** Watch for order creation and status changes, put the order into the queue, and
ask WP-Cron to drain it immediately.

**Depends on:** `WTG\Plugin` (for `CRON_HOOK_NOW`), `WTG\Queue\Sync_Queue`.
**Depended on by:** nothing calls it directly — `Plugin::run()` instantiates it and
WordPress invokes it through hooks.

### Hooks registered

| Hook | Priority | Args | Callback |
|---|---|---|---|
| `woocommerce_new_order` | 10 | 2 | `on_new_order()` |
| `woocommerce_order_status_changed` | 10 | 4 | `on_status_changed()` |

**Why two hooks?** Neither alone sees everything.

`woocommerce_order_status_changed` fires only when there is a **previous** status —
`WC_Order::status_transition()` skips it when `from` is empty. So a brand-new order created
directly as `pending` never fires it. `woocommerce_new_order` covers exactly that gap.
Together they catch an order's opening status *and* every later transition, without having
to enumerate status names one by one.

`accepted_args` of 4 on the transition hook is what lets `on_status_changed()` receive the
`$to` status directly.

### `EXCLUDED_STATUSES`

```php
const EXCLUDED_STATUSES = array( 'auto-draft', 'checkout-draft', 'trash' );
```

**Everything else syncs** — `pending`, `on-hold`, `processing`, `completed`, `cancelled`,
`refunded`, `failed`, and any custom status another plugin adds. Only WordPress's and
WooCommerce's internal placeholders are excluded:

- `auto-draft` / `checkout-draft` — an order shell WooCommerce creates *before* the customer
  has finished. It usually has no line items yet, so syncing it would write a blank product
  row that would then have to be reconciled away. The real status arrives moments later and
  syncs properly.
- `trash` — a deleted order.

### `private static $handled`

A per-request guard, keyed by order ID. WooCommerce can fire both `woocommerce_new_order`
and `woocommerce_order_status_changed` for the same order in one request; without this,
the plugin would queue and spawn cron twice.

Because it is `static`, it persists for the whole PHP request and resets naturally on the
next one.

### `on_new_order( $order_id, $order = null )`

```php
$status = ( $order instanceof \WC_Order ) ? $order->get_status() : '';
$this->handle( (int) $order_id, $status );
```

The `instanceof` check is defensive — if some caller fires the hook without the second
argument, `$status` is `''`, which is not in `EXCLUDED_STATUSES`, so the order is still
queued. Failing toward *syncing* is the right default.

### `on_status_changed( $order_id, $from = '', $to = '' )`

Passes `$to` straight through to `handle()`.

**Why trust `$to` rather than re-reading the order?** This was a real bug. An earlier version
called `wc_get_order( $order_id )->get_status()` here, but WooCommerce can serve a cached
order object mid-transition, and a stale read would make the plugin silently skip an order
that should have been queued. If the hook fired with `$to = 'processing'`, the order *is* in
processing — there is nothing to verify, and verifying it introduces a failure mode.

### `handle( $order_id, $status )` — private

The decision core, four steps:

```php
if ( $order_id <= 0 || isset( self::$handled[ $order_id ] ) ) { return; }

if ( in_array( $status, self::EXCLUDED_STATUSES, true ) ) { return; }

self::$handled[ $order_id ] = true;

if ( Sync_Queue::exists( $order_id ) ) {
    Sync_Queue::requeue( $order_id );
} else {
    Sync_Queue::enqueue( $order_id );
}

$this->schedule_immediate_run();
```

**The ordering of the two guards is deliberate and easy to get wrong.** The excluded-status
check returns **before** marking the order handled. A draft becomes a real order moments
later *in the same request*; if we marked it handled while rejecting it as a draft, that
follow-up transition would be swallowed and the order would never sync.

The `exists()` / `requeue()` branch is what makes status updates work: an order we have seen
before reuses its **one** queue row rather than inserting a second. The processor then
overwrites that order's rows in the sheet, so `processing → completed` updates in place
instead of adding duplicates.

### `schedule_immediate_run()` — private

```php
if ( ! wp_next_scheduled( Plugin::CRON_HOOK_NOW ) ) {
    wp_schedule_single_event( time(), Plugin::CRON_HOOK_NOW );
}
spawn_cron();
```

**The problem this solves.** WP-Cron is not a real clock — events only run when an HTTP
request arrives. On a quiet site (and especially a local dev site) the recurring 5-minute
event might not fire for a very long time, which made the queue appear to require the manual
"Process Queue Now" button.

**The fix.** Schedule a one-off event due *now*, then call `spawn_cron()`, which fires a
non-blocking loopback request to the site. The sheet updates a second or two after the
order, and the customer's request is never held up waiting on Google.

Two safety properties:

- The `wp_next_scheduled()` guard means several orders changing in one request schedule one
  run, not many — a single run drains the whole batch.
- `spawn_cron()` self-limits to once every `WP_CRON_LOCK_TIMEOUT` (60 seconds) and does
  nothing while cron is already running. If it declines, the event simply waits for the next
  request or the recurring 5-minute run. **The row is never lost, only delayed.**

See `02-bootstrap-and-core.md` for why this uses `CRON_HOOK_NOW` and not `CRON_HOOK`.

---

## `class-order-mapper.php` — `WTG\WooCommerce\Order_Mapper`

**Purpose.** Convert a `WC_Order` into the rows written to the sheet — **one row per
product**.

**Depends on:** nothing (no `use` statements).
**Called by:** `Queue\Sync_Processor::process()` only.
**Registers no hooks.**

### `header()` — static

Returns the 12 column names, in order. Used as documentation and for pasting a header row
into row 1 of the sheet by hand — **the plugin never writes or reads the header row itself.**

| Col | Header | Per product? |
|---|---|---|
| A | Order ID | repeats |
| B | Date | repeats |
| C | Status | repeats |
| D | Customer Name | repeats |
| E | Email | repeats |
| F | Phone | repeats |
| G | **Product** | **varies** |
| H | **Quantity** | **varies** |
| I | **Unit Price** | **varies** |
| J | Order Total | repeats |
| K | Currency | repeats |
| L | Payment Method | repeats |

Only G, H and I change between rows of the same order.

### `map( \WC_Order $order )`

Returns a **2D array** — a list of rows — even for a single product, so callers never
special-case the count.

The method builds two fixed halves once, then loops the line items:

```php
$common_head = array( id, date, status, name, email, phone );   // A–F
$common_tail = array( order total, currency, payment method );  // J–L

foreach ( $order->get_items() as $item ) {
    $rows[] = array_merge(
        $common_head,
        array( $item->get_name(), $item->get_quantity(), $order->get_item_total( $item ) ),
        $common_tail
    );
}
```

**Why repeat the order-level fields on every row?** It is the standard shape for a flat
export: each row is self-contained, so filtering and pivot tables work in Sheets without
lookups.

**Why `$order->get_item_total( $item )` for Unit Price** rather than dividing the line total
by the quantity? Because it is WooCommerce's own figure for the amount actually paid **per
unit after any coupon**. Deriving it by division would produce wrong or ugly values on
discounts and on quantities that do not divide evenly.

Note the consequence for spreadsheet formulas: **column J (Order Total) repeats**, so summing
it counts a multi-product order once per row. There is no line-total column — `=H2*I2` gives
it if you want one.

### The empty-order guard

```php
if ( empty( $rows ) ) {
    $rows[] = array_merge( $common_head, array( '', '', '' ), $common_tail );
}
```

An order with no line items — rare, but possible for manually created orders — would
otherwise produce **zero** rows and vanish from the sheet entirely. Emitting one row with
blank product columns means the order is still recorded. The three `''` values must match the
three per-product columns; if you add a fourth product column, add a fourth `''` here.

### Private helpers

**`format_date( $order )`** — `get_date_created()` returns `WC_DateTime|null`, so the null is
guarded. Formats as `Y-m-d H:i:s`, a fixed site-independent format rather than the site's
display format, so the sheet is sortable and machine-readable.

**`customer_name( $order )`** — joins billing first and last name, falling back to the
translatable string `Guest` when both are empty.

---

## What this folder never does

- It never calls Google. `Order_Listener` finishes in a couple of local queries.
- It never runs raw SQL — it goes through `Sync_Queue`.
- `Order_Mapper` never touches the database or the network, which is why it can be tested
  with a fabricated order object and no WordPress at all.
