# 08 — Settings reference

Every configuration value the plugin stores, where it comes from, and who reads it.

## The storage rule

All plugin settings live in **one** option, `wtg_settings`, holding an array of thirteen keys.
`includes/class-settings.php` is the only file that calls `get_option()` / `update_option()`
for it — nothing else touches WordPress options directly.

Defaults come from `Settings::defaults()`, merged over stored values by `wp_parse_args()` in
`Settings::all()`, so a key that was never saved still returns a predictable type.

---

## `wtg_settings` — the thirteen keys

### User-entered (the Connection tab form)

| Key | Default | Type | Written by | Read by |
|---|---|---|---|---|
| `client_id` | `''` | string | `Settings_Page::sanitize()` | `OAuth_Client::has_credentials()`, `get_authorize_url()`, `exchange_code()`, `refresh_access_token()` |
| `client_secret` | `''` | string | `Settings_Page::sanitize()` | `OAuth_Client::has_credentials()`, `exchange_code()`, `refresh_access_token()` |
| `spreadsheet_id` | `''` | string | `Settings_Page::sanitize()` | `Sync_Processor::process()`, `OAuth_Controller::handle_test()` |
| `sheet_name` | `'Sheet1'` | string | `Settings_Page::sanitize()` | `Sync_Processor::process()` |

**`client_id`** — the OAuth client ID from Google Cloud Console, ending
`.apps.googleusercontent.com`. Rendered by `render_field_client_id()` as a normal text input
showing its current value.

**`client_secret`** — rendered by `render_field_client_secret()` as a **password** input with
`value=""`, always. The stored secret is never echoed into page source. Because the field is
always blank, `sanitize()` treats a blank submission as *"keep the existing secret"*:

```php
$submitted_secret = isset( $input['client_secret'] ) ? trim( $input['client_secret'] ) : '';
if ( '' !== $submitted_secret ) {
    $output['client_secret'] = sanitize_text_field( $submitted_secret );
}
```

There is deliberately **no way to clear the secret from the form** — you would disconnect, or
overwrite it with a new value.

**`spreadsheet_id`** — the long ID from the sheet URL,
`docs.google.com/spreadsheets/d/`**`THIS_PART`**`/edit`. If empty, `Sync_Processor::process()`
returns early and rows stay `pending`.

**`sheet_name`** — the tab name. `sanitize()` falls back to `'Sheet1'` when submitted empty,
so the processor always has a target. Passed through `Sheets_Client::a1_sheet()`, which quotes
it — so tab names with spaces work.

### Column selection (the Fields tab)

| Key | Default | Type | Written by | Read by |
|---|---|---|---|---|
| `fields` | `array()` | array of field keys | `Settings_Page::sanitize_fields()` | `Order_Mapper::selected_keys()` |
| `field_labels` | `array()` | key => label map | `Settings_Page::sanitize_fields()` | `Order_Mapper::header()` |

**`fields`** — an ordered list of `Order_Mapper::fields()` keys. **An empty array means "all
fields"**, which is why a site that never opens the Fields tab keeps the original twelve
columns with no migration. `order_id` is always forced in, because
`Sheets_Client::ORDER_ID_COLUMN` is `'A'` and the update-in-place lookup depends on it.

The stored order is irrelevant — `selected_keys()` walks the canonical registry and filters by
this list, so a scrambled or stale value cannot produce scrambled columns.

**`field_labels`** — only labels that **differ from the default** are stored. If a default
label is improved in a future version, columns the user never renamed pick up the new wording
automatically.

### Per-status tabs (the Status Tabs tab)

| Key | Default | Type | Written by | Read by |
|---|---|---|---|---|
| `status_tabs_enabled` | `false` | bool | `Settings_Page::sanitize_status_tabs()` | `Status_Tabs::is_enabled()`, and so `Sync_Processor::process()` |
| `status_tab_statuses` | `array()` | list of status slugs | `Settings_Page::sanitize_status_tabs()` | `Status_Tabs::tracked_statuses()` |
| `status_tab_names` | `array()` | slug => name map | `Settings_Page::sanitize_status_tabs()` | `Status_Tabs::raw_name_for()` |

The keys are declared as constants on `Status_Tabs` (`SETTING_ENABLED`, `SETTING_STATUSES`,
`SETTING_NAMES`) so the settings page, the sanitizer and the readers cannot drift apart.

**`status_tabs_enabled`** — defaults to **false**, so upgrading changes nothing: no tabs appear,
no rows move, and `Sync_Processor` makes not one extra request. See `11-status-tabs.md`.

**`status_tab_statuses`** — **an empty array means "all statuses"**, exactly as `fields` does,
and for the same reason: it records the intent rather than a snapshot, so a status added later
by WooCommerce or another plugin is routed too. That also makes *"none"* unstorable, which is
why unticking everything switches the feature off instead (with a warning).

Slugs are stored **without** the `wc-` prefix — the form `WC_Order::get_status()` returns.
`tracked_statuses()` walks the available list and filters by this one, so a stale slug left by a
removed plugin is ignored rather than acted on.

**`status_tab_names`** — only names that **differ from WooCommerce's own label** are stored, so
a status the admin never renamed follows the label if WooCommerce rewords it. A name matching
`sheet_name` (case-insensitively) is stored but **never used** — `Status_Tabs::tab_name_for()`
refuses it, because the move logic deletes rows from every tab that is not the target and must
never be pointed at the master tab. The settings screen flags such a row in red.

### Written only by the OAuth flow

These four have **no form fields**. They are written exclusively by `OAuth_Client`.

| Key | Default | Type | Written by | Read by |
|---|---|---|---|---|
| `access_token` | `''` | string | `OAuth_Client::store_tokens()`, cleared by `disconnect()` / `flag_reauth_needed()` | `OAuth_Client::get_valid_access_token()` |
| `refresh_token` | `''` | string | `OAuth_Client::store_tokens()`, cleared by `disconnect()` / `flag_reauth_needed()` | `OAuth_Client::is_connected()`, `refresh_access_token()`, `disconnect()` |
| `token_expires` | `0` | int (Unix ts) | `OAuth_Client::store_tokens()` | `OAuth_Client::is_token_expired()` |
| `reauth_needed` | `false` | bool | `OAuth_Client::exchange_code()`, `disconnect()`, `flag_reauth_needed()` | `OAuth_Controller::render_reauth_notice()` |

**`access_token`** — short-lived (about an hour). Never used directly; callers go through
`get_valid_access_token()`, which refreshes transparently.

**`refresh_token`** — the important one. `OAuth_Client::is_connected()` is defined purely as
*"is this non-empty?"*, so **this key is the definition of "connected"**. Losing it means
reconnecting.

**`token_expires`** — stored as an **absolute** timestamp (`time() + expires_in`), not a
duration, so expiry is a simple comparison. `is_token_expired()` applies a 60-second buffer.

**`reauth_needed`** — set true only when a refresh fails with Google's `invalid_grant`,
meaning the refresh token is dead. Drives the persistent admin warning. Cleared on a
successful connect and on an intentional disconnect.

### ⚠️ The sanitize passthrough

Because `Settings_Page::register_settings()` registers the option with a `sanitize_callback`,
WordPress runs `Settings_Page::sanitize()` on **every** `update_option( 'wtg_settings', … )` —
including `OAuth_Client`'s own token writes, via the `sanitize_option_wtg_settings` filter.

That is why `sanitize()` contains this loop:

```php
foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'reauth_needed' ) as $internal_key ) {
    if ( array_key_exists( $internal_key, $input ) ) {
        $output[ $internal_key ] = $input[ $internal_key ];
    }
}
```

**If you add a new non-form setting written programmatically, you must add its key here**, or
it will be silently stripped every time it is saved.

### ⚠️ The form marker

There are **three** forms writing this option — the Connection tab, the Fields tab and the
Status Tabs tab — and an unchecked checkbox is simply not submitted. Without a way to tell the
forms apart, saving the Connection tab would be indistinguishable from "the user unticked every
field", and would wipe the column selection.

So each form posts a hidden `wtg_settings[_form]` value (`Settings_Page::FORM_MARKER`), and
`sanitize()` only touches the keys belonging to the form that was submitted:

| `_form` value | What `sanitize()` rewrites |
|---|---|
| `connection` | `client_id`, `client_secret`, `spreadsheet_id`, `sheet_name` |
| `fields` | `fields`, `field_labels` |
| `status_tabs` | `status_tabs_enabled`, `status_tab_statuses`, `status_tab_names` |
| absent — programmatic token writes | nothing user-facing; only the passthrough loop above runs |

`_form` is never copied into `$output`, so it is never persisted.

**If you add a fourth form, give it its own marker value and its own branch in `sanitize()`.**

### Not stored: `redirect_uri`

The Connection tab shows a Redirect URI field, but it is **read-only and never saved**. It is
computed live by `OAuth_Client::redirect_uri()` on each render, so it always matches the
site's current URL. `sanitize()` has an explicit comment saying so.

---

## `wtg_db_version` — the second option

| | |
|---|---|
| Key | `wtg_db_version` (`Activator::DB_VERSION_OPTION`) |
| Value | `WTG_VERSION`, currently `'0.1.0'` |
| Written by | `Activator::create_table()` |
| Read by | **nothing** |
| Deleted by | `uninstall.php` |

Written on every activation, read by no code today. It exists so a future schema change can
compare it against `WTG_VERSION` and run a migration — see `10-extending-the-plugin.md`.

It is also a useful diagnostic: if `wtg_db_version` exists but `wtg_settings` does not, the
plugin was activated but never configured.

---

## Reading and writing settings in your own code

```php
use WTG\Settings;

$sheet = Settings::get( 'sheet_name', 'Sheet1' );   // single value
$all   = Settings::all();                           // whole array, defaults merged

Settings::set( 'sheet_name', 'Orders 2026' );       // one key
Settings::update( array(                            // several at once
    'sheet_name'     => 'Orders 2026',
    'spreadsheet_id' => '1abc…',
) );

Settings::delete( 'sheet_name' );                   // revert to default
```

Three things to know:

1. **`set()` and `update()` both trigger `sanitize()`** because of the registered callback.
   Unknown keys survive (the callback starts from the existing array), but any key that
   `sanitize()` explicitly rewrites will be normalised.
2. **`get()` uses `array_key_exists()`, not `isset()`** — so a stored `null` is returned as
   `null` rather than falling through to the default.
3. **`update()` merges, it does not replace.** Keys you omit are left alone.

---

## Quick diagnostics

```sql
SELECT option_value FROM wp_options WHERE option_name = 'wtg_settings';
SELECT option_value FROM wp_options WHERE option_name = 'wtg_db_version';
```

| Symptom | Likely meaning |
|---|---|
| `wtg_settings` row missing entirely | Never saved, or deleted outside the plugin. Everything falls back to defaults, `is_connected()` is false |
| `refresh_token` empty but `client_id` set | Credentials saved, account never connected (or disconnected) |
| `reauth_needed` is `true` | Refresh token died — Google returned `invalid_grant`. Reconnect |
| `token_expires` in the past | Normal. The next `get_valid_access_token()` refreshes automatically |
| `wtg_db_version` present, `wtg_settings` absent | Plugin activated but never configured |
| `status_tabs_enabled` true, `status_tab_statuses` is `[]` | Normal — empty means **all** statuses, not none |
| A status tab never appears in the sheet | Its name matches `sheet_name`, or the status is not in `status_tab_statuses`. Tabs are also only created when an order actually reaches that status |
