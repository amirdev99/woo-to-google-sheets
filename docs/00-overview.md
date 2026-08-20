# 00 — Overview

**Plugin:** WooCommerce to Google Sheets
**Text domain:** `woo-to-gsheet`
**Namespace root:** `WTG\`
**Version:** `0.1.0` (from `WTG_VERSION` in `woo-to-google-sheets.php`)
**Requires:** PHP 7.4, WordPress 5.8, WooCommerce

## What it does, in one paragraph

When a WooCommerce order is created or changes status, the plugin writes a **row per
product** into a Google Sheet you own. It never calls Google during checkout. Instead the
order ID is dropped into a small database table, and a background WP-Cron job does the
slow, failure-prone work of talking to Google. If an order is already in the sheet, its
rows are **overwritten in place**, so changing an order from `processing` to `completed`
updates the existing rows instead of adding duplicates.

Which columns are written is configurable on the **Fields** tab, and saving that tab also
rewrites row 1 of the sheet with matching labels — so the sheet's header, its data, and the
settings all describe the same columns without the user having to ask for it. Include no
per-product field and each order collapses to a single row instead of one per product.

Optionally — **off by default** — each order status can also get its own tab. With that on, an
order is additionally written to the tab for its current status and physically **moved** between
those tabs as the status changes. The main tab keeps every order regardless; status tabs are in
addition to it, never instead of it. See `11-status-tabs.md`.

## Full file tree

```
woo-to-google-sheets/
├── woo-to-google-sheets.php          Bootstrap: constants, autoloader, lifecycle hooks
├── uninstall.php                     Destructive cleanup, only on plugin delete
├── readme.txt                        WordPress.org style readme (partly out of date — see below)
├── docs/                             ← this documentation
└── includes/
    ├── class-autoloader.php          Maps WTG\… class names to files
    ├── class-plugin.php              Singleton; owns constants + per-request hook wiring
    ├── class-activator.php           Creates the table, schedules cron (on activation)
    ├── class-deactivator.php         Clears cron (on deactivation)
    ├── class-settings.php            Read/write wrapper around the single wtg_settings option
    ├── Admin/
    │   ├── class-settings-page.php   Top-level admin page, tabs, Settings API fields
    │   ├── class-oauth-controller.php  connect / callback / disconnect / test actions
    │   └── class-queue-controller.php  process-now / retry / clear-log actions
    ├── Google/
    │   ├── class-oauth-client.php    OAuth2 authorization-code flow + token storage
    │   ├── class-sheets-client.php   Sheets API v4: append, find, update, read, list tabs
    │   └── class-drive-client.php    Drive API v3: list the account's spreadsheets
    ├── Queue/
    │   ├── class-sync-queue.php      All SQL against {prefix}wtg_sync_log
    │   └── class-sync-processor.php  The cron callback that actually syncs
    ├── Sheets/
    │   └── class-status-tabs.php     Which tab an order's status belongs on
    └── WooCommerce/
        ├── class-order-listener.php  Hooks WooCommerce order events → queue
        └── class-order-mapper.php    WC_Order → array of spreadsheet rows
```

16 files under `includes/` plus the bootstrap, uninstaller and readme. The docs from
`09-development-timeline.md` were originally reconstructed by reading the code rather than a
history — the project was not under version control when they were written — so treat the
phase story there as inference, not as a commit log.

## Where to start reading

| If you want to… | Read |
|---|---|
| Understand how the folders divide responsibility | `01-architecture.md` |
| Follow WordPress booting the plugin | `02-bootstrap-and-core.md` |
| Change what gets written to the sheet | `04-woocommerce.md` (`Order_Mapper`) |
| Debug a sync that failed | `05-queue.md` then `03-google.md` |
| Add a settings field | `06-admin.md` and `08-settings-reference.md` |
| Add a column, status, or destination | `10-extending-the-plugin.md` |
| Understand the per-status tabs, or test them | `11-status-tabs.md` |

## Diagram 1 — the main runtime flow

Every arrow below is a real method call or hook registration found in the code.

```mermaid
flowchart TD
    A["Customer checks out, or admin edits an order"] --> B{"WooCommerce fires a hook"}

    B -->|"woocommerce_new_order"| C["Order_Listener::on_new_order()"]
    B -->|"woocommerce_order_status_changed"| D["Order_Listener::on_status_changed()"]

    C --> E["Order_Listener::handle()"]
    D --> E

    E -->|"auto-draft / checkout-draft / trash"| X["Ignored"]
    E -->|"order not seen before"| F["Sync_Queue::enqueue()"]
    E -->|"order already has a row"| G["Sync_Queue::requeue()"]

    F --> H[("{prefix}wtg_sync_log<br/>status = pending")]
    G --> H

    E --> I["Order_Listener::schedule_immediate_run()<br/>wp_schedule_single_event + spawn_cron"]

    I --> J["WP-Cron: wtg_process_sync_queue_now"]
    K["WP-Cron: wtg_process_sync_queue<br/>recurring, every 5 minutes"] --> M
    L["Admin clicks Process Queue Now<br/>Queue_Controller::handle_process_now()"] --> M
    J --> M

    M["Sync_Processor::process()"]
    H --> M

    M --> N["OAuth_Client::get_valid_access_token()"]
    N -->|"expired? refresh"| O["POST oauth2.googleapis.com/token"]

    M --> P["Order_Mapper::map()<br/>one row per line item"]
    M --> Q["Sheets_Client::find_rows_by_order_id()<br/>GET values/A:A"]

    Q --> R["Sync_Processor::write()"]
    P --> R

    R -->|"0 rows found"| S["Sheets_Client::append_rows()<br/>values:append"]
    R -->|"row count matches"| T["Sheets_Client::update_rows()<br/>values:batchUpdate"]
    R -->|"more products than rows"| U["update_rows() then append_rows()"]
    R -->|"fewer products than rows"| V["WP_Error: wtg_row_count_shrunk"]

    S --> W[("Google Sheet")]
    T --> W
    U --> W

    S --> AB
    T --> AB
    U --> AB

    AB{"Per-status tabs enabled?"}
    AB -->|"no (default)"| Y
    AB -->|"yes"| AC["Sync_Processor::route()<br/>see 11-status-tabs.md"]

    AC --> AD["1. find_rows_in_tabs()<br/>one batchGet across the status tabs"]
    AD --> AE["2. write() to the tab for the current status<br/>sheet_id_for() creates it, with a header, if new"]
    AE --> AF["3. delete_rows() from every OTHER status tab"]
    AE --> W
    AF --> W
    AF --> Y

    AD -->|"WP_Error"| Z
    AE -->|"WP_Error — nothing deleted"| Z
    AF -->|"WP_Error"| Z

    Y["Sync_Queue::mark SUCCESS"]
    V --> Z["Sync_Queue::mark PENDING to retry,<br/>or FAILED after MAX_ATTEMPTS = 5"]

    Y --> H
    Z --> H

    H --> AA["Settings_Page::render_sync_log_tab()<br/>via Sync_Queue::get_rows(100)"]
```

## Diagram 2 — the OAuth connect flow

```mermaid
flowchart TD
    A["Admin types Client ID + Secret,<br/>clicks Save Changes"] --> B["Settings_Page::sanitize()"]
    B --> C[("wtg_settings option")]

    C --> D["Connect Google Account button<br/>OAuth_Controller::connect_url()"]
    D --> E["OAuth_Controller::handle_connect()<br/>hook: admin_post_wtg_oauth_connect"]

    E --> F["wp_create_nonce('wtg_oauth_state')"]
    F --> G["OAuth_Client::get_authorize_url()<br/>accounts.google.com/o/oauth2/v2/auth"]
    G --> H["Google consent screen"]

    H -->|"redirects back with code + state"| I["OAuth_Controller::handle_callback()<br/>hook: admin_post_wtg_oauth_callback"]

    I --> J{"wp_verify_nonce on state"}
    J -->|"fails"| K["Error notice, back to settings"]
    J -->|"passes"| L["OAuth_Client::exchange_code()<br/>POST oauth2.googleapis.com/token"]

    L -->|"no refresh_token in response"| M["WP_Error: wtg_no_refresh_token"]
    L -->|"success"| N["OAuth_Client::store_tokens()"]
    N --> C

    C --> O["Later: Sync_Processor asks for a token<br/>OAuth_Client::get_valid_access_token()"]
    O -->|"expired"| P["OAuth_Client::refresh_access_token()"]
    P -->|"Google says invalid_grant"| Q["OAuth_Client::flag_reauth_needed()<br/>reauth_needed = true, tokens cleared"]
    Q --> R["OAuth_Controller::render_reauth_notice()<br/>persistent admin warning"]
```

## Known documentation drift in the source

Several docblocks and `readme.txt` predate later changes. The **code** is authoritative;
these comments are not:

| Location | Says | Reality |
|---|---|---|
| `readme.txt` lines 36–40 | Columns A–J, one row per order, a "Products" column | Up to **12 selectable** columns, one row **per product** (`Order_Mapper::fields()`) |
| `readme.txt` line 39 | "add a matching header row manually" | Saving the Fields tab writes it (`Settings_Page::maybe_write_header_row()`) |
| `readme.txt` lines 15, 24 | Only `processing`/`completed` sync | **Every** status syncs except drafts (`Order_Listener::EXCLUDED_STATUSES`) |
| `readme.txt` line 30 | Rows are appended | Rows are **upserted** (`Sync_Processor::write()`) |
| `Settings_Page::render_section_intro()` | "You will connect the account in the next phase" | Connecting happens on this same screen |
| `Settings_Page::render_sync_log_tab()` docblock | "placeholder until Phase 6" | Fully implemented |
| `Settings_Page::render_sync_log_tab()` empty-state text | "once they reach processing or completed" | Any non-draft status |
| `Sync_Queue::exists()` docblock | "one order = one sheet row" | One order = one **queue** row, but N sheet rows |
| `Sync_Processor` class docblock | "appends it to the sheet" | Appends **or** updates |

`10-extending-the-plugin.md` lists these as good first cleanup tasks.
