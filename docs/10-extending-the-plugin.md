# 10 — Extending the plugin

Practical recipes, each pointing at the exact file and method to change.

---

## Add a column to the sheet

**File:** `includes/WooCommerce/class-order-mapper.php` — usually the only file you touch.

Three edits, and they must stay in step:

1. **`header()`** — add the label in the right position.
2. **`map()`** — add the value to `$common_head`, the per-item array, or `$common_tail`
   depending on whether it repeats per row or varies per product.
3. **The empty-order guard** at the bottom of `map()`:
   ```php
   $rows[] = array_merge( $common_head, array( '', '', '' ), $common_tail );
   ```
   The `''` count must equal the number of **per-product** columns. Add a fourth per-product
   column and you need a fourth `''`.

Example — adding SKU as a per-product column after Product:

```php
// header()
'Product', 'SKU', 'Quantity', 'Unit Price',

// map(), inside the foreach
$product = $item->get_product();
array(
    $item->get_name(),
    $product ? $product->get_sku() : '',   // guard: the product may have been deleted
    $item->get_quantity(),
    $order->get_item_total( $item ),
),

// empty-order guard
array( '', '', '', '' )
```

**You do not need to touch `Sheets_Client`.** `update_rows()` computes its end column from
`column_letter( count( $values ) - 1 )`, so the write range grows automatically.

**Two things that will bite you:**

- **Order ID must stay in column A.** `Sheets_Client::ORDER_ID_COLUMN` is `'A'` and
  `find_rows_by_order_id()` searches that column. Move Order ID and every sync starts
  appending duplicates instead of updating. If you must move it, change the constant too.
- **Existing sheet rows are not migrated.** Old rows keep the old column meanings. Clear the
  sheet, or start a new tab, after a column change.

---

## Change which order statuses sync

**File:** `includes/WooCommerce/class-order-listener.php`

Currently a **deny-list**: everything syncs except drafts.

```php
const EXCLUDED_STATUSES = array( 'auto-draft', 'checkout-draft', 'trash' );
```

To exclude more, add slugs (without the `wc-` prefix):

```php
const EXCLUDED_STATUSES = array( 'auto-draft', 'checkout-draft', 'trash', 'cancelled', 'failed' );
```

To go back to an allow-list, change the guard in `handle()`:

```php
private function handle( $order_id, $status ) {
    if ( $order_id <= 0 || isset( self::$handled[ $order_id ] ) ) { return; }
    if ( ! in_array( $status, array( 'processing', 'completed' ), true ) ) { return; }
    // …
}
```

**Keep the ordering.** The status check must run **before** `self::$handled[ $order_id ] = true;`
— a draft becomes a real order later in the same request, and marking it handled while
rejecting it would swallow that transition.

---

## Change how often the background sync runs

**File:** `includes/class-plugin.php`

```php
const CRON_INTERVAL = 300;   // seconds
```

Changing it is not enough on its own: WP-Cron stores the interval **at schedule time**, so an
already-scheduled event keeps the old one. Deactivate and reactivate the plugin, which runs
`Deactivator::clear_cron()` then `Activator::schedule_cron()`.

Note this is only the safety net — `Order_Listener::schedule_immediate_run()` means most
orders sync within seconds regardless.

---

## Process more rows per run

**File:** `includes/Queue/class-sync-processor.php`

```php
const BATCH_SIZE = 20;
```

Each row costs **two** Google API calls (one lookup, one write), sometimes three when products
were added. Raising this raises the chance of hitting the PHP time limit. If you have a large
backlog, prefer running the queue more often over making batches bigger.

---

## Change the retry count

**File:** `includes/Queue/class-sync-queue.php`

```php
const MAX_ATTEMPTS = 5;
```

Read in `Sync_Processor::process()`. Rows that exceed it become `failed` and wait for a manual
**Retry**.

---

## Add a settings field

Four edits:

1. **`includes/class-settings.php`** — add the key and default to `defaults()`.
2. **`includes/Admin/class-settings-page.php`** → `register_settings()` — add it to the
   `$fields` array.
3. Same file — add a `render_field_<key>()` method. Name the input
   `wtg_settings[<key>]`.
4. Same file — handle it in `sanitize()`.

> **If your new setting is written programmatically rather than by the form**, you must also
> add its key to the passthrough loop in `sanitize()`:
> ```php
> foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'reauth_needed' ) as $internal_key ) {
> ```
> WordPress runs `sanitize()` on **every** `update_option( 'wtg_settings', … )`, so a key not
> listed there is silently stripped on save. This exact bug once made connecting appear to
> succeed while discarding the refresh token.

---

## Add a button to the Sync Log

Follow the `Clear Log` pattern exactly — it is the newest and cleanest example.

1. **`includes/Admin/class-queue-controller.php`**
   - `const ACTION_MY_THING = 'wtg_my_thing';`
   - register it in `hooks()`: `add_action( 'admin_post_' . self::ACTION_MY_THING, array( $this, 'handle_my_thing' ) );`
   - add `public static function my_thing_url()` wrapping `wp_nonce_url()`
   - add `handle_my_thing()` — call `$this->authorize( self::ACTION_MY_THING )` **first**, do
     the work, `set_notice()`, then `redirect_to_log()`
2. **`includes/Admin/class-settings-page.php`** → `render_sync_log_tab()` — render the link
   with `esc_url( Queue_Controller::my_thing_url() )`.

Never do the work inside the render method: without the redirect, refreshing the page repeats
the action.

---

## Send orders somewhere other than Google Sheets

The architecture already anticipates this. Add a sibling to `includes/Google/` — say
`includes/Destination/class-webhook-client.php` — exposing the same three operations
(`append_rows`, `find_rows_by_order_id`, `update_rows`).

Then in `Sync_Processor::process()`, replace:

```php
$sheets = new Sheets_Client();
```

Nothing else needs to change. `Order_Mapper` produces plain arrays and `Sync_Queue` stores
plain rows — neither knows Google exists.

For a proper multi-destination plugin, extract an interface and let `process()` loop over
registered destinations. The queue's `status` column would then need to become per
destination.

---

## Recommended cleanups

### Make the install self-healing (highest value)

`Activator::activate()` runs **only** when someone clicks Activate — not when files are
overwritten over FTP. If the table is missing, `Sync_Queue::enqueue()` fails silently
(`$wpdb->insert()` returns `false`) and **nothing appears in the Sync Log at all**. That is a
genuinely confusing failure to diagnose.

Add to `includes/class-activator.php`:

```php
public static function maybe_install() {
    $version_ok = get_option( self::DB_VERSION_OPTION ) === WTG_VERSION;
    if ( $version_ok && get_transient( 'wtg_table_ok' ) ) {
        return;
    }
    if ( ! self::table_exists() || ! $version_ok ) {
        self::create_table();          // dbDelta is safe to re-run
    }
    self::schedule_cron();
    if ( self::table_exists() ) {
        set_transient( 'wtg_table_ok', 1, DAY_IN_SECONDS );
    }
}

public static function table_exists() {
    global $wpdb;
    $table = Plugin::table_name();
    return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}
```

`create_table()` is currently `private` — make it `private static` → `public static` or call it
from within the class. Hook `maybe_install()` on `admin_init` in `Plugin::run()`.

This also gives `wtg_db_version` its first real reader (see `08-settings-reference.md`).

### Surface enqueue failures

`Sync_Queue::enqueue()` turns a failed insert into a plain `0`. Logging `$wpdb->last_error`
would have made the missing-table problem obvious immediately.

### Fold `fetch_spreadsheet_title()` into `Sheets_Client`

`OAuth_Controller::fetch_spreadsheet_title()` is the only place `Admin/` talks to Google
directly, with its own `SHEETS_API` constant. It predates `Sheets_Client` (see
`09-development-timeline.md`). Moving it there and deleting the constant restores the rule
that all Google HTTP lives in `includes/Google/`.

### Trim the log automatically

`Clear Log` is manual. A scheduled `Sync_Queue::clear()` for `success` rows older than N days
would keep the table small on a busy store. `get_rows()` already caps the **display** at 100.

### Fix the stale comments

Listed in full at the end of `09-development-timeline.md`. `readme.txt` is the most misleading
— it still documents ten columns, one row per order, append-only, and processing/completed
only.

### Localise timestamps in the Sync Log

`created_at` / `updated_at` are stored GMT and printed raw, so the Sync Log shows UTC.
Wrapping in `get_date_from_gmt()` would show site time.

---

## Testing without a live Google connection

Google OAuth rejects `.local` domains, so a local WordPress cannot complete the flow.
Everything **up to** the API call can still be tested by bootstrapping WordPress from the CLI:

```php
define( 'DB_HOST', '127.0.0.1:PORT' );   // your local DB port
$_SERVER['HTTP_HOST'] = 'yoursite.local';
define( 'WP_USE_THEMES', false );
require_once '/path/to/wp-load.php';

// Create a real order and watch the listener react.
$order = wc_create_order();
$order->add_product( $product, 2 );
$order->calculate_totals();
$order->save();
$order->update_status( 'processing' );

// Then inspect the queue table.
global $wpdb;
$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wtg_sync_log" );

// And check the mapper output without any network call.
$rows = ( new WTG\WooCommerce\Order_Mapper() )->map( wc_get_order( $order->get_id() ) );
```

This exercises the hooks, the queue, and the mapper — everything except `Sheets_Client`.
`Sync_Processor::write()` can be tested separately by passing a stub object with
`append_rows()` and `update_rows()` methods, since it only ever calls those two.

Keep such scripts **outside the plugin folder** so they are never shipped.
