# 05 — `includes/Queue/`

## Why this folder exists

**This folder exists so that no other part of the plugin ever calls the Google Sheets API
directly — everything becomes a queue row first.**

That one rule is what makes the plugin safe to run on a real store:

1. **Checkout never waits on Google.** `Order_Listener` does one fast local `INSERT` and
   returns. A Google outage, a 20-second timeout, an expired token — none of it can reach
   the customer's checkout request.
2. **Retries are possible.** A row has a `status` and an `attempts` count, so a failure can be
   put back to `pending` and tried again, up to `MAX_ATTEMPTS = 5`.
3. **Failures are visible.** `last_error` is a real column, so the admin Sync Log can show
   exactly why an order did not sync, instead of failing silently.

The folder splits into **storage** and **algorithm**:

- **`Sync_Queue`** is pure data access. **Every SQL statement in the entire plugin lives in
  this one file.** Nothing else calls `$wpdb` directly.
- **`Sync_Processor`** is the sync algorithm. It is the only class in the plugin that imports
  from three different modules, and that is intentional — it is the designated meeting point
  for WooCommerce data, Google transport, and queue state.

---

## `class-sync-queue.php` — `WTG\Queue\Sync_Queue`

**Purpose.** All reads and writes against `{prefix}wtg_sync_log`.

**Depends on:** `WTG\Plugin` (for `Plugin::table_name()`) — its only `use` statement.
**Called by:** `WooCommerce\Order_Listener`, `Queue\Sync_Processor`,
`Admin\Queue_Controller`, `Admin\Settings_Page`.
**Registers no hooks.** Every method is `static`.

### Constants

| Constant | Value | Meaning |
|---|---|---|
| `STATUS_PENDING` | `pending` | Queued, waiting to be sent |
| `STATUS_PROCESSING` | `processing` | Claimed by the current run |
| `STATUS_SUCCESS` | `success` | Written to the sheet |
| `STATUS_FAILED` | `failed` | Gave up after exhausting retries |
| `MAX_ATTEMPTS` | `5` | Attempts before a row is terminally failed |

### A note on SQL safety

Several methods interpolate the table name directly:

```php
$table = self::table();
$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d LIMIT 1", $order_id ) );
```

That is safe and necessary: `$wpdb->prepare()` **cannot bind identifiers** (table or column
names), only values. The table name comes from `Plugin::table_name()`, which is
`$wpdb->prefix` plus a hard-coded constant — never user input. Every *value* goes through a
`%d` / `%s` placeholder.

### `table()`
Returns `Plugin::table_name()`. One indirection so the queue never hard-codes a prefix.

### `exists( $order_id )`
`SELECT id … LIMIT 1`, returns bool. Used by `Order_Listener::handle()` to choose between
enqueue and requeue.

### `enqueue( $order_id )`

Inserts a `pending` row, after two guards: `$order_id <= 0` returns 0, and `exists()` returns
0 (never queue the same order twice).

```php
$now = current_time( 'mysql', true );
```

The `true` means **GMT**. Timestamps are stored timezone-independent; display can localise.

Returns `$wpdb->insert_id`, or `0` on duplicate or failure.

> **Silent-failure trap.** `$wpdb->insert()` returns `false` if the table does not exist, and
> `enqueue()` turns that into a plain `0`. Nothing is logged and nothing appears in the Sync
> Log. If orders are not appearing *at all*, verify the table exists before anything else —
> see `10-extending-the-plugin.md`.

### `requeue( $order_id )`

Flips an existing order's row back to `pending`, resetting `attempts` to 0 and clearing
`last_error`.

**This is what makes status changes update the sheet instead of appending to it.** The plugin
deliberately reuses the order's single queue row rather than inserting another. The processor
then looks the order up in the sheet by ID and overwrites the rows it already occupies.

Attempts are reset because this is a genuinely new send (a fresh status), not a retry of the
previous failed one.

### `clear( array $statuses )`

Backs the admin **Clear Log** button. Deletes every row whose status is in the list.

It takes a status list rather than emptying the table because `pending` and `processing` rows
are **unfinished work** — deleting those would silently drop orders that never reached the
sheet. `Queue_Controller::handle_clear_log()` passes only `STATUS_SUCCESS` and
`STATUS_FAILED`.

The `IN` clause is built with one placeholder per status so the whole list stays
parameterised:

```php
$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ( {$placeholders} )", $statuses ) );
```

**Clearing the log is safe for your sheet.** The dedupe that prevents duplicate sheet rows
does *not* depend on this table — `Sheets_Client::find_rows_by_order_id()` asks the sheet
itself. A cleared order that is later re-queued still updates its existing rows.

### `count_by_status( $status )`
`SELECT COUNT(*)`. Drives the four counters at the top of the Sync Log tab.

### `get_pending_batch( $limit )`

```sql
SELECT id, order_id, attempts FROM … WHERE status = 'pending' ORDER BY id ASC LIMIT %d
```

`ORDER BY id ASC` means oldest first — fair, and predictable. Only the three columns the
processor needs are selected.

### `mark( $id, $status, $error = '' )`
Sets `status`, `last_error` and `updated_at` together. Stores `''` rather than `NULL` for
`last_error` to keep `$wpdb->update()` simple and consistent across MySQL configurations.

### `bump_attempts( $id )`
A raw `UPDATE … SET attempts = attempts + 1`. Written as a query rather than a read-modify-
write so the increment happens atomically in the database.

### `reset_for_retry( $id )`
Sets a row back to `pending` with `attempts = 0` and no error. Backs the per-row **Retry**
link. Note the difference from `requeue()`: this takes a **row id**, `requeue()` takes an
**order id**.

### `get_rows( $limit = 100 )`
`SELECT * … ORDER BY updated_at DESC, id DESC LIMIT %d` — most recently touched first. The
`id DESC` tiebreaker keeps ordering stable when several rows share a timestamp.

---

## `class-sync-processor.php` — `WTG\Queue\Sync_Processor`

**Purpose.** The callback that actually syncs: for each pending row, get a token, map the
order, decide append-or-update, and record the outcome.

**Depends on:** `WTG\Settings`, `WTG\Google\OAuth_Client`, `WTG\Google\Sheets_Client`,
`WTG\WooCommerce\Order_Mapper` — plus `Sync_Queue` from its own namespace.
**Called by:** WordPress via `Plugin::CRON_HOOK` and `Plugin::CRON_HOOK_NOW`, and directly by
`Admin\Queue_Controller::handle_process_now()`.

**`BATCH_SIZE = 20`** — how many rows one run attempts, so a large backlog cannot exhaust the
PHP time limit in a single request.

### `process()`

Returns a counts array: `processed`, `success`, `failed`, `retry`, `skipped`.
(`skipped` is initialised but never incremented in the current code.)

**Three early exits, all deliberately silent:**

```php
$token = $oauth->get_valid_access_token();
if ( is_wp_error( $token ) ) { return $counts; }      // not connected / refresh failed

if ( '' === $spreadsheet_id ) { return $counts; }      // not configured

$rows = Sync_Queue::get_pending_batch( self::BATCH_SIZE );
if ( empty( $rows ) ) { return $counts; }              // nothing to do
```

Bailing **before** touching any row leaves everything `pending`. Nothing is consumed, nothing
is marked failed, and no attempt is burned — so when the admin reconnects, the backlog syncs
untouched. One token is fetched once and reused for the whole batch.

### The per-row loop

**1. Claim the row before doing anything risky.**

```php
Sync_Queue::mark( $row->id, Sync_Queue::STATUS_PROCESSING );
Sync_Queue::bump_attempts( $row->id );
$attempts = (int) $row->attempts + 1;
```

Marking `processing` and counting the attempt **up front** means a fatal error mid-loop
cannot leave the row stuck as `pending` with an uncounted try — which would retry forever.
`$attempts` is computed in PHP because `$row` was read before the increment.

**2. The order may have been deleted.**

```php
$order = function_exists( 'wc_get_order' ) ? wc_get_order( $row->order_id ) : false;
if ( ! $order ) {
    Sync_Queue::mark( $row->id, Sync_Queue::STATUS_FAILED, 'Order not found.' );
    continue;
}
```

Marked failed immediately, not retried — a deleted order will never come back. The
`function_exists()` check guards against WooCommerce being deactivated while rows remain.

**3. Map, then look up.**

```php
$new_rows = $mapper->map( $order );
$existing = $sheets->find_rows_by_order_id( $spreadsheet_id, $sheet_name, $row->order_id, $token );
```

**4. A failed lookup is treated as a failed send — never as "not found".**

```php
if ( is_wp_error( $existing ) ) {
    $result = $existing;
} else {
    $result = $this->write( ... );
}
```

This is subtle and important. If a network blip made the lookup fail and we treated it as
"the order isn't in the sheet", we would **append a duplicate** on every retry. Treating it as
a send failure routes it through the normal retry path instead.

**5. Record the outcome.**

```php
if ( is_wp_error( $result ) ) {
    if ( $attempts >= Sync_Queue::MAX_ATTEMPTS ) {
        Sync_Queue::mark( $row->id, STATUS_FAILED, $result->get_error_message() );
    } else {
        Sync_Queue::mark( $row->id, STATUS_PENDING, $result->get_error_message() );
    }
    continue;
}
Sync_Queue::mark( $row->id, Sync_Queue::STATUS_SUCCESS );
```

A retryable failure goes back to `pending` **but keeps its error message**, so the Sync Log
shows why it is still trying. After 5 attempts it becomes `failed`, which is where the manual
Retry link takes over.

### `write( … )` — private

The upsert decision. Three handled cases and one deliberate refusal:

| Situation | Action |
|---|---|
| `0 === $have` — order not in the sheet | `append_rows()` with every product row |
| `$have === $need` | `update_rows()` — overwrite in place. The normal status-change path |
| `$need > $have` — a product was **added** | `update_rows()` on the overlap, then `append_rows()` for the surplus |
| `$have > $need` — a product was **removed** | **Refuse**: `WP_Error( 'wtg_row_count_shrunk' )` |

The refusal is a design decision, not an oversight. Deleting spreadsheet rows is destructive
and irreversible, and if the row lookup were ever wrong it would take real data with it. So
instead the plugin reports:

> This order has %1$d rows in the sheet but only %2$d products now. Delete its rows from the
> sheet and use Retry to re-add them.

Note the ordering in the grow case: the update is attempted first and its error returned
immediately if it fails, so the append only runs once the overlap is safely written.

### Verified behaviour

The decision table above was exercised directly during development with a stubbed client —
new 1-product and 3-product orders, status changes at both sizes, a product added (2→3), rows
that are **not adjacent** (rows 2 and 40, simulating a manually sorted sheet), and a product
removed. All produced the expected call sequence.
