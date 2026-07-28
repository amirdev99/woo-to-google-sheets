# 09 — Development timeline

## How this was reconstructed

**There is no `.git` folder in this project.** Nothing here comes from commit history. The
order below is reconstructed from two kinds of evidence that *are* in the code:

1. **Explicit phase numbers left in docblocks.** The author numbered the build in comments,
   and those references survive. They are quoted below.
2. **Hard dependency order.** A file cannot be written before the thing it calls. The
   `use` statements and constant references form a partial ordering that the phase numbers
   agree with.

Where the two disagree with the current code, the code wins — several comments are now stale
(see the end of this file).

---

## Phase 1 — Boilerplate and the plugin skeleton

**Files:** `woo-to-google-sheets.php`, `includes/class-autoloader.php`,
`includes/class-plugin.php`, `includes/class-activator.php`,
`includes/class-deactivator.php`, `includes/class-settings.php`

**Evidence.** `Plugin::run()` still carries the comment:

> "Later phases will add more registrations here (admin menu, WooCommerce order hooks, the
> cron processor callback). **For Phase 1 we only need the custom cron schedule and the text
> domain.**"

And `Settings::defaults()` already declares keys it would not use for several phases:

> "Declaring every key up front (including the OAuth token fields **populated later in Phase
> 3**)…"

**What was built.** The constants, the PSR-4-style autoloader, the Singleton, the queue table
(so the schema existed before anything wrote to it), the cron recurrence, and the one-option
settings wrapper.

**The bug found in this phase.** The `cron_schedules` filter had to be moved out of
`Plugin::run()` and into the bootstrap at file scope. `run()` fires on `plugins_loaded`, but
during an activation request that action has **already fired** by the time the activation
hook runs — so `wp_schedule_event()` would be handed an unknown recurrence name and silently
do nothing. The long comment in `woo-to-google-sheets.php` above `add_filter( 'cron_schedules', … )`
documents this. It is the kind of bug that produces no error at all, just a cron that never
runs.

---

## Phase 2 — The settings screen

**Files:** `includes/Admin/class-settings-page.php` (first version)

**Evidence.** `class-settings.php` says sanitizing "happens at the input boundary (the
Settings API **sanitize_callback in Phase 2**)". `Settings::defaults()` labels four keys
"Connection (**Phase 2 settings form**)".

**What was built.** `register_setting()` against `wtg_settings_group`, one settings section,
and the five fields. The `sanitize()` method's "start from the existing array" strategy was
established here, and the Client Secret's write-only rendering.

At this point the page was a **submenu of Settings** (`add_options_page`) and the Sync Log tab
was a placeholder — its docblock still says "placeholder until Phase 6".

---

## Phase 3 — OAuth connect

**Files:** `includes/Google/class-oauth-client.php`,
`includes/Admin/class-oauth-controller.php`

**Evidence.** `OAuth_Client::SCOPE` says "we only READ during the **Phase 3 Test
Connection**". `Settings_Page::sanitize()` warns that "the option also holds OAuth tokens
(**Phase 3**)".

**What was built.** The full authorization-code flow, token storage, transparent refresh, the
`invalid_grant` → `reauth_needed` path, and the four `admin-post.php` actions including Test
Connection.

`OAuth_Controller::fetch_spreadsheet_title()` was written here as a small inline Sheets read,
with the comment "**Phase 5 introduces the full Sheets_Client** for writing rows; this small
inline read is all Test Connection needs." That explains why `Admin/` still contains one
direct Google call — it predates the Google client for writes.

**The critical discovery of this phase** is recorded in `sanitize()`: `register_setting()`
filters `sanitize_option_wtg_settings`, so the callback runs on the plugin's **own**
programmatic token writes too. Without the explicit passthrough loop, connecting appeared to
succeed while the refresh token was stripped before saving.

---

## Phase 4 — Capturing orders

**Files:** `includes/WooCommerce/class-order-listener.php`,
`includes/Queue/class-sync-queue.php` (enqueue half)

**Evidence.** `Sync_Queue`'s class docblock: "The listener enqueues through it (**Phase 4**);
the processor will claim and mark rows through it (Phase 5); the admin Sync Log will read
through it (Phase 6)." Written in the future tense — a comment authored before phases 5 and 6
existed.

**What was built.** The order hooks, and `Sync_Queue::exists()` / `enqueue()` with
check-then-insert dedupe.

The original listener hooked `woocommerce_order_status_processing` and
`woocommerce_order_status_completed` directly. That is why `Sync_Queue::exists()`'s docblock
still explains dedupe in terms of "**the processing and the completed hooks**" — a sentence
that no longer describes the current hooks.

---

## Phase 5 — Background sync

**Files:** `includes/Queue/class-sync-processor.php`,
`includes/Google/class-sheets-client.php`, `includes/WooCommerce/class-order-mapper.php`,
plus the claim/mark half of `Sync_Queue`

**Evidence.** `OAuth_Client::SCOPE`: "**Phase 5 appends rows**, so we request the write scope
now to avoid a second consent later" — the scope was chosen in Phase 3 *for* Phase 5.

**What was built.** The cron callback, batching (`BATCH_SIZE = 20`), the claim-then-count
ordering, the retry ladder to `MAX_ATTEMPTS = 5`, the `WC_Order` → row mapper, and the Sheets
`values:append` wrapper.

Original shape: **one row per order**, ten columns A–J, with all products collapsed into a
single "Products" cell. `readme.txt` still describes this version.

---

## Phase 6 — Sync Log UI and uninstall

**Files:** `includes/Admin/class-queue-controller.php`, the Sync Log half of
`class-settings-page.php`, `uninstall.php`, `readme.txt`

**Evidence.** `Sync_Queue::count_by_status()` is documented as "used by the admin Sync Log
**later**", and `get_rows()` exists solely for the display.

**What was built.** The counters, the table, Process Queue Now, per-row Retry, and the
destructive uninstall routine.

---

## Post-phase work

Everything past this point has no phase number in the comments; the ordering comes from
dependencies and from what each change had to react to.

### 7. Immediate sync instead of waiting for cron

**Files:** `class-plugin.php` (added `CRON_HOOK_NOW`), `class-order-listener.php`
(`schedule_immediate_run()`), `class-deactivator.php`, `uninstall.php`

The 5-minute cron made the plugin *look* broken on a quiet site, since WP-Cron only runs on
traffic. Fixed by scheduling a one-off event and calling `spawn_cron()`.

This required a **second hook name**: `wp_schedule_single_event()` refuses an identical hook
already due within ±10 minutes, and the recurring hook is always inside that window. Reusing
it would have made every kick a silent no-op.

### 8. Update in place instead of appending

**Files:** `class-sheets-client.php`, `class-sync-processor.php`, `class-sync-queue.php`

Requirement: changing an order's status should update its existing sheet row, not add a
second one.

Design choice worth recording: the plugin looks the order up **in the sheet** by ID rather
than storing a row number. A stored number would go stale the moment a human sorted or
deleted rows — and the lookup approach also worked for rows synced before the feature
existed, so no migration was needed. It added `find_rows_by_order_id()`, `update_rows()`, and
`Sync_Queue::requeue()`.

### 9. One row per product

**Files:** `class-order-mapper.php`, `class-sheets-client.php`, `class-sync-processor.php`

`map()` changed from returning one row to returning a **list** of rows; `append()` became
`append_rows()`; `find_row_by_order_id()` became `find_rows_by_order_id()` (returning every
match); `update_rows()` moved to `values:batchUpdate` so non-adjacent rows work.

`Sync_Processor::write()` was added for the reconcile logic, including the deliberate refusal
to delete rows when an order loses a product.

The `Sheets_Client::encode()` guard was added here too, after multi-product orders failed:
`wp_json_encode()` returns `false` on malformed UTF-8, and the old code passed that straight
into the request body, producing an empty POST and a meaningless HTTP 400.

### 10. Column changes

Columns went A–J → A–L (one row per product) → A–M (Unit Price added) → **A–L** (Line Total
removed as redundant, since `=H2*I2` gives it). The current 12 columns are in
`Order_Mapper::header()`.

### 11. Every status syncs

**File:** `class-order-listener.php`

Orders that went to `on-hold` never reached the sheet. The fix replaced the per-status hooks
with `woocommerce_new_order` + `woocommerce_order_status_changed`, and inverted the filter
from an allow-list (`CREATE_STATUSES`) to a deny-list (`EXCLUDED_STATUSES`).

`woocommerce_new_order` was required because `woocommerce_order_status_changed` does not fire
when an order has no previous status — so a brand-new `pending` order was invisible.

A related bug was fixed in the same pass: the listener had been re-reading the order's status
with `wc_get_order()`, which can return a **cached** object mid-transition and cause an order
to be silently skipped. It now trusts the `$to` argument the hook provides.

### 12. Admin polish

**Files:** `class-settings-page.php`, `class-oauth-controller.php`,
`class-queue-controller.php`, `class-sync-queue.php`

- `esc_html__()` → `wp_kses()` for the Spreadsheet ID help text, which had been printing
  `<strong>` tags literally on screen.
- Moved from a Settings submenu to a **top-level menu** (`add_menu_page`, position 56). This
  changed the page URL from `options-general.php?page=…` to `admin.php?page=…`, which would
  have broken both controllers' redirects — so `Settings_Page::url()` was introduced as the
  single source of truth.
- Added **Clear Log** (`Sync_Queue::clear()`), restricted to finished rows only.

### A feature that was built and then removed

A spreadsheet-picker dropdown, listing the account's sheets by title via the Google Drive
API, was implemented in a `Google/class-drive-client.php` and then **reverted at the author's
request** — pasting the Spreadsheet ID was preferred. The OAuth scope went back to
`auth/spreadsheets` alone.

Nothing of it remains in the codebase. It is recorded here only so that a stray
`drive.metadata.readonly` grant on an old Google connection has an explanation.

---

## Dependency order (independent of the phase comments)

If you rebuilt this from scratch, this ordering is forced by the code itself:

```
Settings  ─────────────┐
Plugin (constants) ────┼──> Sync_Queue ──> Order_Listener
Autoloader ────────────┘         │
                                 └──> Sync_Processor <── Order_Mapper
OAuth_Client ────────────────────────────┤            <── Sheets_Client
                                         │
Settings_Page ──> OAuth_Controller ──────┘
              └─> Queue_Controller
```

`Sheets_Client` and `Order_Mapper` are leaves — nothing depends on them but
`Sync_Processor` — which is why the column format could be rewritten three times without
touching anything else.

---

## Comments that no longer match the code

Useful when reading the source, because these will mislead you:

| Location | Stale claim |
|---|---|
| `readme.txt` | Columns A–J, one row per order, append-only, processing/completed only |
| `Sync_Queue` class docblock | Phase tense: "the processor **will** claim…" |
| `Sync_Queue::exists()` | "one order must only ever produce one **sheet** row"; "the processing and the completed hooks" |
| `Sync_Processor` class docblock | "appends it to the sheet" — it upserts |
| `Settings_Page::render_section_intro()` | "You will connect the account in the next phase" |
| `Settings_Page::render_sync_log_tab()` | "placeholder until Phase 6"; empty-state text says processing/completed |
| `OAuth_Client::SCOPE` | Phase 3/5 references |
| `OAuth_Controller::fetch_spreadsheet_title()` | "Phase 5 introduces the full Sheets_Client" |
| `class-settings-page.php` file header | "Settings > Woo to Google Sheets" — it is top-level now |
