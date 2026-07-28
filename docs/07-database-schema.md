# 07 — Database schema

The plugin creates **one** custom table and stores **two** options. Nothing else.

---

## Table: `{$wpdb->prefix}wtg_sync_log`

On a default install that is `wp_wtg_sync_log`. The name is built by
`Plugin::table_name()` from `$wpdb->prefix` + `Plugin::TABLE_SUFFIX` (`'wtg_sync_log'`), read
live on every call so multisite works.

**Created by:** `Activator::create_table()` via `dbDelta()`, on activation.
**Dropped by:** `uninstall.php` — only when the plugin is deleted.
**Accessed by:** `Sync_Queue` exclusively. No other file runs SQL.

### The exact `CREATE TABLE`

Taken verbatim from `includes/class-activator.php`:

```sql
CREATE TABLE {$table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_id bigint(20) unsigned NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending',
    attempts smallint(5) unsigned NOT NULL DEFAULT 0,
    last_error text NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    KEY order_id (order_id),
    KEY status (status)
) {$charset_collate};
```

`$charset_collate` comes from `$wpdb->get_charset_collate()`, so the table matches the site
— normally `utf8mb4`, which is required for emoji and many non-Latin scripts in product
names.

### Columns

| Column | Type | Null | Default | Meaning |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | no | auto | Surrogate key. Shown as **ID** in the Sync Log |
| `order_id` | `bigint(20) unsigned` | no | — | The WooCommerce order this row represents |
| `status` | `varchar(20)` | no | `pending` | Queue state — see below |
| `attempts` | `smallint(5) unsigned` | no | `0` | How many times the processor has tried |
| `last_error` | `text` | yes | `NULL` | Most recent failure message |
| `created_at` | `datetime` | no | — | When enqueued, **GMT** |
| `updated_at` | `datetime` | no | — | When last changed, **GMT** |

Both timestamps are written with `current_time( 'mysql', true )` — the `true` means GMT, so
values are timezone-independent. The Sync Log currently prints `updated_at` raw, so it
displays in UTC rather than site time.

`last_error` is declared `NULL` and `enqueue()` does insert `null`, but every later write
goes through `Sync_Queue::mark()` or `reset_for_retry()`, which store `''` instead. So in
practice a row is `NULL` only until its first state change. Code reading it uses
`(string) $row->last_error`, which handles both.

### Indexes

| Index | Column | Why |
|---|---|---|
| `PRIMARY KEY` | `id` | Row identity; `mark()`, `bump_attempts()`, `reset_for_retry()` all target it |
| `KEY order_id` | `order_id` | `exists()` and `requeue()` look rows up by order |
| `KEY status` | `status` | `get_pending_batch()`, `count_by_status()` and `clear()` all filter on it |

> **dbDelta formatting is not stylistic.** Each column must be on its own line, `PRIMARY KEY`
> must be followed by **two spaces** before `(id)`, and every `KEY` needs an index name.
> `dbDelta()` parses this SQL with regular expressions; break the rules and it silently
> mis-parses or re-issues the same `ALTER` on every load.

### There is no `UNIQUE` index on `order_id`

Deduplication is a **check-then-insert** in `Sync_Queue::enqueue()`:

```php
if ( self::exists( $order_id ) ) { return 0; }
```

Between the check and the insert there is a theoretical race — two simultaneous requests for
the same order could both pass the check. In practice `Order_Listener`'s per-request
`$handled` guard makes this very unlikely, and the consequence is mild: two queue rows for
one order, which the sheet-side lookup in `Sheets_Client::find_rows_by_order_id()` would
still reconcile.

`readme.txt` suggests adding a `UNIQUE` index on `order_id` for a hard guarantee. If you do,
note that `enqueue()` would then need to tolerate a failed insert rather than treating it as
an error.

### Status lifecycle

```mermaid
stateDiagram-v2
    [*] --> pending: Sync_Queue::enqueue()
    pending --> processing: Sync_Processor claims the row
    processing --> success: sheet write succeeded
    processing --> pending: failed, attempts < 5
    processing --> failed: failed, attempts >= 5
    processing --> failed: order no longer exists
    success --> pending: Sync_Queue::requeue() on a status change
    failed --> pending: Sync_Queue::reset_for_retry() via the Retry link
    success --> [*]: Clear Log
    failed --> [*]: Clear Log
```

The two transitions **out of** `success` and `failed` are what make the plugin feel live: a
finished row goes back to `pending` when the order changes again, or when an admin clicks
Retry.

`Clear Log` deletes only `success` and `failed` rows — never `pending` or `processing`.

### Row volume

One row **per order**, not per product. A 3-product order is a single queue row that becomes
3 rows in the Google Sheet. The table only grows with order count, and `Clear Log` trims it.

---

## Options

Both live in `wp_options`. Full field-by-field detail in `08-settings-reference.md`.

| Option | Written by | Read by | Shape |
|---|---|---|---|
| `wtg_settings` | `Settings::set()` / `update()`, and `options.php` via `Settings_Page::sanitize()` | `Settings::all()` | Serialized array of 8 keys |
| `wtg_db_version` | `Activator::create_table()` | *nothing currently* | String, e.g. `0.1.0` |

`wtg_db_version` is written but never read. It exists so a future schema change can compare
it against `WTG_VERSION` and run a migration — see `10-extending-the-plugin.md`.

Both are deleted by `uninstall.php`.

### Transients

| Key | Set by | Life | Purpose |
|---|---|---|---|
| `wtg_notice_{user_id}` | `OAuth_Controller::set_notice()`, `Queue_Controller::set_notice()` | 60s | One-shot admin notice; deleted on first render |

Per-user by design, so one admin's "Connected successfully" never appears for another.

---

## Cron entries

Stored by WordPress in the `cron` option, not by this plugin.

| Hook | Type | Scheduled by | Cleared by |
|---|---|---|---|
| `wtg_process_sync_queue` | recurring, `wtg_five_minutes` (300s) | `Activator::schedule_cron()` | `Deactivator`, `uninstall.php` |
| `wtg_process_sync_queue_now` | single event, due immediately | `Order_Listener::schedule_immediate_run()` | `Deactivator`, `uninstall.php` |

The custom `wtg_five_minutes` recurrence is added by `Plugin::register_cron_schedule()`,
registered on the `cron_schedules` filter from the bootstrap file.

---

## Inspecting the table directly

```sql
-- current state
SELECT id, order_id, status, attempts, last_error, updated_at
FROM wp_wtg_sync_log
ORDER BY updated_at DESC
LIMIT 20;

-- the counters shown on the Sync Log tab
SELECT status, COUNT(*) FROM wp_wtg_sync_log GROUP BY status;

-- does the table even exist? (a missing table fails silently on insert)
SHOW TABLES LIKE 'wp_wtg_sync_log';
```

That last query is the first thing to run when orders are not appearing in the Sync Log at
all — `$wpdb->insert()` into a non-existent table returns `false` with no error surfaced
anywhere.
