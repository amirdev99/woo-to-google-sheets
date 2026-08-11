# 06 — `includes/Admin/`

## Why this folder exists

**Everything here is about a human clicking something**: rendering HTML, checking nonces,
checking capabilities, setting admin notices, redirecting. It deliberately contains **no
business logic**.

`Queue_Controller::handle_process_now()` is the clearest illustration — it checks the nonce,
calls `( new Sync_Processor() )->process()`, formats the returned counts into a notice, and
redirects. Four lines of real work; the sync logic lives entirely in `Queue/`.

The folder splits into a **page** and two **controllers**:

- **`Settings_Page`** renders. It draws the tabs, the Settings API form, the connection
  status panel and the Sync Log table.
- **`OAuth_Controller`** and **`Queue_Controller`** act. Each handler does its work and
  **redirects** — the Post/Redirect/Get pattern. Because the actions live on
  `admin-post.php` rather than inside page rendering, refreshing the browser after
  connecting or clearing the log cannot repeat the action.

All three are instantiated inside `if ( is_admin() )` in `Plugin::run()`, so none of this
code loads on the front end. `is_admin()` is true for `wp-admin`, `admin-ajax.php` **and**
`admin-post.php`, which is why the OAuth callback works from inside that branch.

Both controllers redirect via `Settings_Page::url()`, so the page's location is defined in
exactly one place.

---

## `class-settings-page.php` — `WTG\Admin\Settings_Page`

**Purpose.** The plugin's top-level admin screen: two tabs, the Settings API form, and the
Sync Log.

**Depends on:** `WTG\Settings`, `WTG\Google\OAuth_Client`, `WTG\Queue\Sync_Queue`. It also
calls `OAuth_Controller` and `Queue_Controller` static methods — no `use` needed, same
namespace.

### Constants

| Constant | Value | Notes |
|---|---|---|
| `MENU_SLUG` | `woo-to-google-sheets` | The `?page=` value |
| `MENU_POSITION` | `56` | Sits just below WooCommerce (55.x) |
| `SETTINGS_GROUP` | `wtg_settings_group` | Must match between `register_setting()` and `settings_fields()` |
| `CONNECTION_SECTION` | `wtg_connection_section` | The Settings-API section on the Connection tab |
| `FORM_MARKER` | `_form` | Hidden input telling `sanitize()` which form was submitted |

There are **three tabs**: `connection`, `fields`, `sync_log`. `render_page()` validates
`$_GET['tab']` against exactly that list.

### Hooks registered

| Hook | Callback |
|---|---|
| `admin_menu` | `add_menu()` |
| `admin_init` | `register_settings()` |

### `add_menu()`

Uses **`add_menu_page()`**, not `add_options_page()` — the plugin has its own top-level item
with the `dashicons-media-spreadsheet` icon.

**The consequence to remember:** a top-level page lives at `admin.php?page=<slug>`, *not*
`options-general.php?page=<slug>`. Anything linking or redirecting here must go through
`url()`.

### `url( $tab = 'connection' )` — static

The single source of truth for the page URL:

```php
return add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
```

`OAuth_Controller::redirect_to_settings()` and `Queue_Controller::redirect_to_log()` both use
it. When the page was moved out of the Settings submenu, this method is why only one file
needed changing.

### `register_settings()`

Registers `wtg_settings` against `SETTINGS_GROUP` with `sanitize` as its callback, adds one
section, then loops five fields:

`client_id`, `client_secret`, `redirect_uri`, `spreadsheet_id`, `sheet_name`

Each maps by convention to a method named `render_field_` + key. Every input is named
`wtg_settings[<key>]`, so the whole form arrives as one array.

### `sanitize( $input )` — the trickiest method in the plugin

It does **not** return only the submitted keys. It starts from what is already stored:

```php
$existing = get_option( Settings::OPTION_KEY, array() );
$output   = $existing;
```

**Why:** the form submits only the connection fields, but the same option also holds OAuth
tokens. Returning just the submitted keys would wipe them on every Save.

Then the field-specific rules:

- `client_id`, `spreadsheet_id` — straightforward `sanitize_text_field()`.
- `sheet_name` — falls back to `'Sheet1'` if cleared, so the processor always has a target.
- `client_secret` — **a blank submission means "keep the existing secret"**, not "erase it".
  The field renders blank for security, so blank cannot mean deletion.

And then the part that is genuinely surprising:

```php
foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'reauth_needed' ) as $internal_key ) {
    if ( array_key_exists( $internal_key, $input ) ) {
        $output[ $internal_key ] = $input[ $internal_key ];
    }
}
```

`register_setting()` installs a `sanitize_option_wtg_settings` filter, and WordPress applies
that filter on **every** `update_option()` of the key — including the plugin's own
programmatic token writes from `OAuth_Client::store_tokens()` and `disconnect()`. Those
writes are the only source of these four keys (the form has no inputs for them), so they must
be passed through when present. **Without this loop, connecting would appear to succeed and
the refresh token would be silently stripped before it was ever saved.**

`redirect_uri` is intentionally not stored — it is display-only and always computed live.

### The form marker — why `sanitize()` branches

**Three** forms now write this option: the Connection tab, the Fields tab and the Status Tabs
tab. An unchecked checkbox is **not submitted at all**, so saving the Connection tab looks
exactly like "the user unticked every field" — and would wipe the column selection.

Each form therefore posts a hidden `wtg_settings[_form]`, and `sanitize()` only rewrites the
keys belonging to that form:

```php
$form = isset( $input[ self::FORM_MARKER ] ) ? sanitize_key( $input[ self::FORM_MARKER ] ) : '';

if ( 'connection' === $form )  { /* client_id, client_secret, spreadsheet_id, sheet_name */ }
if ( 'fields' === $form )      { $output = $this->sanitize_fields( $input, $output, $existing ); }
if ( 'status_tabs' === $form ) { $output = $this->sanitize_status_tabs( $input, $output ); }
```

Programmatic token writes carry no marker, so they fall through **both** branches and leave
every user-facing key exactly as stored — which is the safest possible behaviour. The marker
itself is never copied into `$output`, so it is never persisted.

### `sanitize_fields( $input, $output, $existing )` — private

Handles the Fields tab. Three things worth noting:

1. It walks the **canonical** registry order and keeps what was ticked — the same loop
   direction as `Order_Mapper::selected_keys()`, which is what makes scrambled column order
   and unknown stored keys impossible.
2. Locked fields (`order_id`) are forced in regardless of what was submitted.
3. It stores **only labels that differ from the default**, so improved default wording in a
   future version reaches columns the user never renamed.

If the selection actually changed it raises an `add_settings_error()` warning telling the user
to click Write Header Row, and that rows already in the sheet keep the old layout. That call is
guarded with `function_exists()` — `add_settings_error()` lives in
`wp-admin/includes/template.php`, which is not loaded on every request, and `sanitize()` can
run from any context including the OAuth callback on `admin-post.php`.

### `sanitize_status_tabs( $input, $output )` — private

Handles the Status Tabs tab: the switch, the routed statuses, the names. Four behaviours that
are not obvious from the code:

1. **It walks `Status_Tabs::available_statuses()` and keeps what was ticked** — the same
   direction of travel as `sanitize_fields()`, so an unknown slug can never be stored and the
   saved order always matches WooCommerce's own.
2. **All ticked stores `array()`, not the full list.** An empty value *means* "all statuses" to
   `Status_Tabs::tracked_statuses()`, so storing empty records the **intent** rather than a
   snapshot of today's list — a status added later by WooCommerce or another plugin gets a tab
   automatically.
3. **Nothing ticked forces `enabled = false`**, with an `add_settings_error()` warning saying so.
   Because empty already means "all", *"none"* is literally unstorable — and a feature that is
   switched on but routes nothing would be a lie in the UI. Untick-everything is therefore read
   as "switch it off", out loud rather than quietly.
4. **WooCommerce inactive saves only the switch.** With no `wc_get_order_statuses()` the form
   rendered no rows, so reading them would wipe a configuration this request could not even see.
   The statuses and names are left exactly as stored.

Names are stored **only where they differ from WooCommerce's label**, for the same reason
`field_labels` is — improved default wording later reaches tabs the admin never renamed.

### Field renderers

| Method | Renders |
|---|---|
| `render_section_intro()` | Section blurb. ⚠️ Text is stale: "You will connect the account in the next phase" |
| `render_field_client_id()` | Text input |
| `render_field_client_secret()` | **Password** input, always `value=""`. Placeholder changes to "•••••••• (leave blank to keep saved secret)" when a secret exists — never echoes the stored secret into the page source |
| `render_field_redirect_uri()` | Read-only input showing `OAuth_Client::redirect_uri()`, with `onclick="this.select();"` for easy copying. Not part of the saved option |
| `render_field_spreadsheet_id()` | **Dropdown** of the account's spreadsheets by name, with a Refresh button |
| `render_field_sheet_name()` | **Dropdown** of the selected spreadsheet's tabs, with a Refresh button |

### The two dropdowns

Both replace what used to be text boxes, and both keep the **same input `name`** —
`wtg_settings[spreadsheet_id]` and `wtg_settings[sheet_name]`. That is why `sanitize()`
needed no changes at all: a `<select>` posts exactly what an `<input>` did.

**Every failure path falls back to the original text input**, so the page is never a dead end:
not connected, missing Drive permission, an API error, or an empty account. The fallback keeps
the same field name, so saving still works normally.

Three details worth knowing:

- **A saved spreadsheet that is not in the list is preserved** as an extra selected option —
  typically one shared by link rather than owned. Without that, re-saving would silently clear
  it. The same applies to a tab name that has been renamed or deleted, which is shown as
  "*(not found in this spreadsheet)*" rather than quietly switching the sync to a different tab.
- **The tab list needs a saved spreadsheet**, so first-time setup is two steps: choose a
  spreadsheet → Save → the tab dropdown populates. The field says so explicitly. Avoiding that
  would need JavaScript, which this plugin does not currently ship.
- **`wtg_drive_scope_missing` renders a Disconnect button** beside the error, because a missing
  scope is only fixable by reconnecting.

### Caching — and why it lives here, not in `Google/`

`Settings_Page` owns two transients: `wtg_spreadsheet_list`, and
`wtg_sheet_titles_{md5(spreadsheet_id)}` per spreadsheet, both for 10 minutes. `md5()` keeps
the transient name within the option-name length limit whatever the ID looks like.

The Google clients stay pure HTTP with no WordPress state — caching is a display concern, so it
belongs to the layer doing the displaying.

`access_token()` memoises the token on the instance, because both dropdowns need one and
without it a single page render could trigger two refresh round-trips to Google.

`maybe_refresh_lists()` (hooked on `admin_init`) handles the Refresh button: verify capability
and nonce, delete both transients, then `wp_safe_redirect()` to a clean URL so reloading does
not repeat the action.

The spreadsheet help text is worth noting because it demonstrates a real escaping fix:

```php
echo '<p class="description">' . wp_kses(
    __( 'The long ID from your sheet URL: docs.google.com/spreadsheets/d/<strong>THIS_PART</strong>/edit', 'woo-to-gsheet' ),
    array( 'strong' => array() )
) . '</p>';
```

It previously used `esc_html__()`, which escaped the `<strong>` tags and printed them
literally on screen. `wp_kses()` with a one-tag allowlist keeps the markup working while
still escaping anything else a translation might contain.

### `render_page()`

Re-checks `current_user_can( 'manage_options' )` even though `add_menu_page()` already gated
on it — defence in depth.

Reads `$_GET['tab']` through `sanitize_key()` and validates it against a whitelist of
`connection` / `fields` / `status_tabs` / `sync_log`, falling back to `connection`. No nonce is
needed because switching tabs changes no state.

Tab URLs are built with `menu_page_url( self::MENU_SLUG, false )`, which resolves the correct
parent file automatically — which is why the tabs needed no changes when the page moved.

### `render_connection_tab()` — private

`settings_errors()`, then the status panel, then a standard Settings API form posting to
`options.php` with `settings_fields()` + `do_settings_sections()` + `submit_button()`.

### `render_sync_log_tab()` — private

1. Four counters via `Sync_Queue::count_by_status()`.
2. **Process Queue Now** → `Queue_Controller::process_now_url()`.
3. **Clear Log** → `Queue_Controller::clear_log_url()`, shown **only when
   `$success + $failed > 0`**, so the button is never a no-op. It carries a JS
   `confirm()` built with `_n()` and `esc_js()`, stating the count, that it cannot be undone,
   and that the Google Sheet is not affected.
4. The table via `Sync_Queue::get_rows( 100 )` — columns ID, Order, Status, Attempts, Last
   Error, Updated, Action. Only `failed` rows show a **Retry** link; others show an em dash.

Note the display limit is 100 rows, but **Clear Log deletes every finished row**, not just
the visible ones.

⚠️ The empty-state message still says orders appear "once they reach processing or
completed", which is out of date — every non-draft status now syncs.

### `render_fields_tab()` — private

Rendered **by hand**, not through the Settings API, because the API is awkward for a repeating
checklist. It still posts to `options.php` under the same registered group, so nonce and
capability handling are unchanged.

One row per registry field, showing:

- an **Include** checkbox (`wtg_settings[fields][]`, value = field key)
- the **column letter** the field will land in — recomputed over *selected* fields only, so
  deselecting one visibly shifts the rest up, previewing what the sheet will look like
- the field's default name, tagged "per product" or "required" where applicable
- an editable **heading** (`wtg_settings[field_labels][<key>]`)

`order_id`'s checkbox is rendered `disabled` **and** accompanied by a hidden input with the
same name — a disabled checkbox is not submitted, so without the hidden input the locked field
would appear unticked on every save. (`sanitize_fields()` forces it in anyway; the hidden input
means the two layers agree.)

### `render_status_tabs_tab()` — private

Rendered by hand for the same reason as the Fields tab, and posting to `options.php` under the
same registered group. Everything on it is answered by the **static half** of
`Status_Tabs`, so the screen never makes an HTTP call to Google.

- an enable checkbox (`wtg_settings[status_tabs_enabled]`)
- one row per `available_statuses()`: an **Include** checkbox
  (`wtg_settings[status_tab_statuses][]`), the WooCommerce label with its slug in `<code>`, and
  a **tab name** box (`wtg_settings[status_tab_names][<slug>]`)
- a `notice-warning` instead of the table when WooCommerce is inactive

Two details worth copying if you build a similar screen:

**The name box is left empty when there is no override, and the *placeholder* shows the
WooCommerce label.** That is what makes "clear the box" mean "go back to the default" — a
pre-filled value would have to be compared against the label to detect the same intent.

**Each row computes its effective name and flags a clash with the master tab in red**, using the
same case-insensitive comparison `Status_Tabs` uses internally. Without this the sync would
silently refuse that name and the admin would just see a tab that never appears. The rule itself
is enforced in `Status_Tabs`, not here — this is only the explanation.

`column_letter()` is a small private helper duplicating the same 0→A, 25→Z, 26→AA logic as
`Sheets_Client::column_letter()`. Duplicated rather than shared because the admin layer should
not reach into the Google client for a formatting detail.

### `render_connection_status()` — private

Decides which buttons to show, based on `OAuth_Client` state only:

| State | Shown |
|---|---|
| `is_connected()` | Green "Connected", **Test Connection**, **Write Header Row**, **Disconnect** |
| `has_credentials()` but not connected | Red "Not connected", **Connect Google Account** |
| Neither | Red "Not connected" plus instructions to save credentials first |

All the OAuth logic stays in the controller; this method only chooses which nonced URL to
render.

---

## `class-oauth-controller.php` — `WTG\Admin\OAuth_Controller`

**Purpose.** The four `admin-post.php` actions of the OAuth lifecycle, plus the two admin
notices.

**Depends on:** `WTG\Settings`, `WTG\Google\OAuth_Client`.

### Constants

| Constant | Value |
|---|---|
| `ACTION_CONNECT` | `wtg_oauth_connect` |
| `ACTION_DISCONNECT` | `wtg_oauth_disconnect` |
| `ACTION_TEST` | `wtg_test_connection` |
| `ACTION_WRITE_HEADER` | `wtg_write_header` |
| `STATE_ACTION` | `wtg_oauth_state` |
| `SHEETS_API` | `https://sheets.googleapis.com/v4/spreadsheets/` |

The callback action is **not** defined here — it is `OAuth_Client::ACTION_CALLBACK`, so the
URL shown on screen and the hook listened to come from the same constant.

### Hooks registered

| Hook | Callback |
|---|---|
| `admin_post_wtg_oauth_connect` | `handle_connect()` |
| `admin_post_wtg_oauth_callback` | `handle_callback()` |
| `admin_post_wtg_oauth_disconnect` | `handle_disconnect()` |
| `admin_post_wtg_test_connection` | `handle_test()` |
| `admin_post_wtg_write_header` | `handle_write_header()` |
| `admin_notices` | `render_notice()` |
| `admin_notices` | `render_reauth_notice()` |

`admin_post_{action}` (without `nopriv`) fires only for logged-in users — exactly right, since
every one of these requires an authenticated admin.

### `handle_connect()`

Gates via `authorize()`, refuses if `has_credentials()` is false, then:

```php
$state = wp_create_nonce( self::STATE_ACTION );
wp_redirect( $oauth->get_authorize_url( $state ) );
```

**`wp_redirect()`, not `wp_safe_redirect()`** — the latter blocks off-site hosts, and this
redirect goes to Google on purpose.

### `handle_callback()` — the security-critical one

This request arrives **from Google**, so it carries no WordPress form nonce. Four checks, in
order:

1. `current_user_can( 'manage_options' )` — still requires an admin session.
2. `wp_verify_nonce( $state, self::STATE_ACTION )` — **the `state` parameter is the nonce.**
   Google echoes back whatever we sent, which is what protects the callback against a forged
   redirect.
3. `$_GET['error']` — the user may have denied consent.
4. Empty `$_GET['code']`.

Only then does it call `exchange_code()`, set a success or error notice, and redirect.

### `handle_disconnect()`
`authorize()`, `OAuth_Client::disconnect()`, success notice, redirect.

### `handle_test()`

Proves the whole chain end to end: `get_valid_access_token()` (refreshing transparently),
then a check that `spreadsheet_id` is set, then `fetch_spreadsheet_title()`. On success the
notice names the actual spreadsheet — strong confirmation that the token, the ID and the
permissions all work.

### `handle_write_header()`

Writes the column labels into row 1. The labels come from `Order_Mapper::header()` — the same
source the sync uses for data rows — so the header can never disagree with what sits beneath it.

Row 1 may already hold something, so it reads it first via `Sheets_Client::read_row()` and
branches four ways:

| Row 1 holds | Action |
|---|---|
| Nothing | Write the header |
| Exactly this header | Do nothing, report success — clicking twice is safe |
| A different or older header | Overwrite it |
| **Order data** | **Refuse**, and tell the user to insert a blank row first |

It tells order data from a header by checking whether **A1 is numeric**. Column A is always
Order ID, so a number means a synced order and text means a header. Refusing there matches how
`Sync_Processor::write()` refuses rather than deleting rows.

The write itself reuses `Sheets_Client::update_rows()` with row number `1` — no new write path
exists for the header.

`header_matches()` is the private helper comparing trimmed strings; a differing cell count is
simply "not a match", which handles Google trimming trailing empty cells.

### `fetch_spreadsheet_title( $token, $spreadsheet_id )` — private

A small read-only `GET {SHEETS_API}{id}?fields=properties.title`.

> **Architectural wrinkle.** This duplicates HTTP-calling responsibility that
> `03-google.md` says belongs in `Sheets_Client`. It exists because Test Connection was
> built before `Sheets_Client` did — the docblock still says so. It works, but it is the one
> place `Admin/` talks to Google directly. See `10-extending-the-plugin.md` for how to fold
> it in.

### `authorize( $action )` — private
Capability check plus `check_admin_referer( $action )`, which `wp_die()`s on a bad nonce.
Shared by connect, disconnect and test — but **not** by the callback, which uses `state`.

### `set_notice()` / `render_notice()`

A one-shot notice stored in a **per-user transient** keyed `wtg_notice_{user_id}` with a
60-second life. `render_notice()` prints it and immediately `delete_transient()`s it, so it
shows exactly once and never leaks between users.

`Queue_Controller` writes to this **same transient key**, which is why its notices render
without any duplicate notice code.

### `render_reauth_notice()`

Unlike the one-shot notice, this shows on **every** admin page for as long as
`reauth_needed` is true, because syncing stays paused until the user acts. It offers a direct
"Reconnect now" link, but only when `has_credentials()` still holds.

---

## `class-queue-controller.php` — `WTG\Admin\Queue_Controller`

**Purpose.** The three queue actions.

**Depends on:** `WTG\Queue\Sync_Queue`, `WTG\Queue\Sync_Processor`.

### Hooks registered

| Hook | Callback |
|---|---|
| `admin_post_wtg_process_now` | `handle_process_now()` |
| `admin_post_wtg_retry_row` | `handle_retry()` |
| `admin_post_wtg_clear_log` | `handle_clear_log()` |

Constants: `ACTION_PROCESS_NOW` = `wtg_process_now`, `ACTION_RETRY` = `wtg_retry_row`,
`ACTION_CLEAR_LOG` = `wtg_clear_log`.

### URL builders
`process_now_url()`, `retry_url( $row_id )`, `clear_log_url()` — all `wp_nonce_url()` wrapped,
all called from `Settings_Page::render_sync_log_tab()`.

### `handle_process_now()`
Runs the processor synchronously and reports the counts:
*"Queue processed: %d attempted, %d succeeded, %d to retry, %d failed."*

### `handle_retry()`
Reads `$_REQUEST['row']`, casts to int, and calls `Sync_Queue::reset_for_retry()`. Invalid
ids get an error notice rather than a silent no-op.

### `handle_clear_log()`

```php
$deleted = Sync_Queue::clear( array( Sync_Queue::STATUS_SUCCESS, Sync_Queue::STATUS_FAILED ) );
```

**Only finished rows.** `pending` and `processing` rows are orders that have not reached the
sheet yet; deleting those would be exactly the kind of quiet data loss the plugin avoids
elsewhere. The count is reported through `_n()` so singular and plural both read correctly.

### Private helpers
`authorize()` — same capability + `check_admin_referer()` pattern as `OAuth_Controller`.
`set_notice()` — writes the same `wtg_notice_{user_id}` transient.
`redirect_to_log()` — `wp_safe_redirect( Settings_Page::url( 'sync_log' ) )`.
