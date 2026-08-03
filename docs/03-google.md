# 03 — `includes/Google/`

> **Three classes now:** `OAuth_Client` (credentials and tokens), `Sheets_Client` (spreadsheet
> data), and `Drive_Client` (listing the account's spreadsheets). All three are **pure HTTP** —
> no caching, no options, no WordPress state beyond the HTTP helpers. The transients that back
> the settings-page dropdowns live in `Admin\Settings_Page`, deliberately, so this folder stays
> a plain transport layer.

## Why this folder exists

**Everything that speaks HTTP to Google lives here, and nothing here knows what a
WooCommerce order is.**

`Sheets_Client::append_rows()` receives `array $rows` — plain arrays of scalars. It has no
idea a row represents a product line, and it never will. That is what lets the row format
change (it went from 10 columns to 13 and back to 12 during the build) without touching a
single line of API code.

The folder splits internally along a second real seam:

- **`OAuth_Client`** owns *credentials and tokens*. It is the only class that reads or writes
  `access_token`, `refresh_token`, `token_expires` and `reauth_needed`.
- **`Sheets_Client`** owns *spreadsheet data*. It **never fetches a token** — every public
  method takes `$access_token` as its final parameter.

That separation means token-refresh logic exists in exactly one place, and a Sheets call can
never have the surprise side effect of mutating your stored credentials.

**Neither file registers any WordPress hooks.** They are plain service classes, called
directly.

---

## `class-oauth-client.php` — `WTG\Google\OAuth_Client`

**Purpose.** Implements Google's OAuth2 authorization-code flow: build the consent URL,
exchange the returned code for tokens, refresh an expired access token, and hand callers a
guaranteed-valid token.

**Depends on:** `WTG\Settings` (its only `use` statement).
**Called by:** `Queue\Sync_Processor`, `Admin\OAuth_Controller`, `Admin\Settings_Page`.

### Constants

| Constant | Value |
|---|---|
| `AUTH_URL` | `https://accounts.google.com/o/oauth2/v2/auth` |
| `TOKEN_URL` | `https://oauth2.googleapis.com/token` |
| `REVOKE_URL` | `https://oauth2.googleapis.com/revoke` |
| `SCOPE` | `https://www.googleapis.com/auth/spreadsheets` |
| `ACTION_CALLBACK` | `wtg_oauth_callback` |
| `EXPIRY_BUFFER` | `60` |

`ACTION_CALLBACK` is the single source of truth for the callback: `redirect_uri()` builds a
URL from it, and `OAuth_Controller::hooks()` listens on `admin_post_` + the same constant.
They can never drift apart.

### `redirect_uri()` — static

```php
return add_query_arg( 'action', self::ACTION_CALLBACK, admin_url( 'admin-post.php' ) );
```

Produces e.g. `https://example.com/wp-admin/admin-post.php?action=wtg_oauth_callback`.

**Why `admin-post.php`?** It is a real WordPress endpoint that fires `admin_init` and runs
before any HTML output, so the handler can redirect cleanly. Building the URL from
`admin_url()` means it automatically respects the site's real scheme and host.

**Google requires an exact string match** against the URI registered in the Cloud Console.
If the site URL changes (http→https, or domain change), the registered URI must be updated
too or connecting fails.

### `has_credentials()` vs `is_connected()`

Two different questions, easy to confuse:

- `has_credentials()` — has the admin entered a Client ID **and** Secret? This gates whether
  the Connect button is even shown.
- `is_connected()` — do we hold a **refresh token**? This is the definition of "connected".

Note it is the *refresh* token, not the access token, that defines connection. An access
token lasts an hour; a refresh token is what allows unattended syncing forever.

### `get_authorize_url( $state )`

Builds the consent URL. Three parameters deserve attention:

- **`access_type=offline`** — asks Google to issue a refresh token at all. Without it you get
  an access token that expires in an hour and no way to renew it.
- **`prompt=consent`** — forces Google to *return* the refresh token even on re-authorization.
  By default Google only sends one on the very first consent, so a user who disconnects and
  reconnects would otherwise get no refresh token and the plugin would break.
- **`state`** — an opaque anti-CSRF value. `OAuth_Controller` passes a
  `wp_create_nonce( 'wtg_oauth_state' )` here and verifies it on the way back.

### `exchange_code( $code )`

`POST` to `TOKEN_URL` with `grant_type=authorization_code`, then:

```php
if ( empty( $data['refresh_token'] ) ) {
    return new \WP_Error( 'wtg_no_refresh_token', ... );
}
```

**This guard is important.** A successful exchange that returns no refresh token would look
like success but leave the plugin unable to sync unattended. Failing loudly here — with a
message telling the user to disconnect and reconnect — is far better than a connection that
silently dies in an hour.

On success it calls `store_tokens( $data )` and then `Settings::set( 'reauth_needed', false )`,
clearing any prior "you must reconnect" flag.

### `refresh_access_token()`

`POST` to `TOKEN_URL` with `grant_type=refresh_token`. The interesting part is the failure
branch:

```php
if ( 'invalid_grant' === $this->google_error_code( $data ) ) {
    $this->flag_reauth_needed();
    return new \WP_Error( 'wtg_reauth_required', ... );
}
```

`invalid_grant` is Google's code for a dead refresh token — revoked by the user, or expired
because the OAuth consent screen is in **Testing** mode (Google expires those after 7 days).
This is not a transient error and retrying will never help, so the plugin treats it
specially: clear the tokens, set `reauth_needed = true`, and let
`OAuth_Controller::render_reauth_notice()` show a persistent warning until the user
reconnects. Every other error falls through to the normal retry path.

Google does **not** return a `refresh_token` on a refresh, which is exactly why
`store_tokens()` only writes that key when it is non-empty.

### `get_valid_access_token()`

The method everything else calls. Three steps:

1. Not connected → `WP_Error( 'wtg_not_connected' )`.
2. No access token stored, **or** `is_token_expired()` → refresh first, propagating any error.
3. Return the stored `access_token`.

Callers never have to think about expiry.

### `is_token_expired()`

```php
return $expires <= ( time() + self::EXPIRY_BUFFER );
```

The 60-second buffer means a token that is *about* to expire is treated as already expired.
Without it, a token with 3 seconds left would pass the check and then fail mid-request.

### `disconnect()`

Best-effort `POST` to `REVOKE_URL`, **result deliberately ignored**, then clears
`access_token`, `refresh_token`, `token_expires`, and sets `reauth_needed = false`.

The result is ignored on purpose: if Google is unreachable, the user still expects
Disconnect to work locally. Setting `reauth_needed = false` matters too — this was an
intentional disconnect, so no "please reconnect" nag should appear.

### Private helpers

**`parse_token_response( $response )`** — centralises transport + JSON + API error handling
for both token calls. Note it treats `isset( $data['error'] )` as a failure even on HTTP 200,
because Google sometimes does that. It stashes Google's machine-readable code in the
`WP_Error` data:

```php
return new \WP_Error( 'wtg_oauth_error', $message, array( 'google_error' => ... ) );
```

That third argument is what makes the `invalid_grant` detection above possible.

**`google_error_code( \WP_Error $error )`** — pulls `google_error` back out of the error data.

**`flag_reauth_needed()`** — clears tokens and sets `reauth_needed = true`.

**`store_tokens( array $data )`** — writes `access_token`, converts `expires_in` (a duration)
into `token_expires` (an absolute timestamp, easier to compare later), and writes
`refresh_token` **only if non-empty**, so a refresh never clobbers the existing one.

---

## `class-sheets-client.php` — `WTG\Google\Sheets_Client`

**Purpose.** Four operations against the Sheets API v4 `values` endpoints. Three of them let
the processor "upsert" — append new rows, find an order's existing rows, overwrite rows in
place — and the fourth reads a row back, which the Write Header Row button needs.

**Depends on:** nothing. **Zero `use` statements** — the most isolated class in the plugin.
**Called by:** `Queue\Sync_Processor`, and `Admin\OAuth_Controller` for the header button.

### Constants

- `API_BASE` = `https://sheets.googleapis.com/v4/spreadsheets/`
- `ORDER_ID_COLUMN` = `'A'`

`ORDER_ID_COLUMN` is a hard coupling to `Order_Mapper::map()`, where the order ID is the
first value in every row. **If you reorder the mapper's columns so Order ID is no longer
first, update this constant** — otherwise `find_rows_by_order_id()` searches the wrong column
and every sync silently starts appending duplicates.

### `append_rows( $spreadsheet_id, $sheet_name, array $rows, $access_token )`

`POST {API_BASE}{id}/values/{sheet}:append`

Takes a **list** of rows because one order produces one row per product. Sending them in a
single request keeps an order's rows adjacent in the sheet and costs one call instead of N.

Query parameters:
- `valueInputOption=USER_ENTERED` — Google parses values as if typed in the UI, so numbers
  and dates become real numbers and dates rather than text.
- `insertDataOption=INSERT_ROWS` — push existing data down rather than overwrite anything.

Returns `true` early when `$rows` is empty — nothing to do is not an error.

### `find_rows_by_order_id( $spreadsheet_id, $sheet_name, $order_id, $access_token )`

`GET {API_BASE}{id}/values/{sheet}!A:A?majorDimension=COLUMNS`

**Why look up every time instead of storing a row number?** The comment in the file says it
best: the sheet is a document a human edits. Rows get sorted, inserted and deleted, and any
number the plugin remembered would quietly go stale. A lookup always tells the truth — and
it also works for orders synced before this feature existed, so no migration was needed.

`majorDimension=COLUMNS` makes Google return the whole column as **one** inner array
(`values[0]`), which is far easier to scan than a list of one-cell rows.

Three behaviours worth knowing:

1. An empty sheet omits `values` entirely — handled as `return array()`, not an error.
2. It collects **every** match, not just the first, because a 3-product order occupies 3 rows
   and a status change has to update all of them.
3. Comparison is `trim( (string) $cell ) === (string) $order_id` — string comparison, because
   the API may hand back `"14670"` or `14670` depending on how the cell was entered.

Returns an ascending list of **1-based** row numbers (`$index + 1`).

### `read_row( $spreadsheet_id, $sheet_name, $row_number, $access_token )`

`GET {API_BASE}{id}/values/{sheet}!1:1`

Reads one whole row back. Used by the **Write Header Row** button, which must tell an empty
row from an existing header from real order data before it writes anything.

The range is `1:1` rather than `A1:L1` deliberately — it reads the entire row without this
class needing to know how many columns `Order_Mapper` produces, which keeps `Sheets_Client`
independent of the mapper.

Returns the cell values left to right, or an empty array when the row is blank (Google omits
`values` entirely for an empty range — the same case `find_rows_by_order_id()` handles).

### `update_rows( $spreadsheet_id, $sheet_name, array $row_numbers, array $rows, $access_token )`

`POST {API_BASE}{id}/values:batchUpdate`

**Why batchUpdate rather than a series of PUTs?** Two reasons: an order whose rows are *not*
adjacent (because someone sorted the sheet) still updates correctly, and the whole order
costs exactly one request.

Each entry names its own bounded range, built by `sprintf`:

```
'Sheet1'!A7:L7
```

**Bounding the range to exactly the columns being written matters.** If the range were open
ended, a user's own extra columns to the right of the plugin's data would be wiped. The end
column comes from `column_letter( count( $values ) - 1 )`, so it grows automatically if the
mapper ever emits more columns.

It writes **whole rows**, not just the status cell — the same single request either way, and
it means a corrected phone number or adjusted total gets refreshed too instead of the sheet
slowly drifting from WooCommerce.

Guards: returns `true` for empty input; returns `WP_Error` if `$row_numbers` and `$rows` are
different lengths (a programming error, caught early).

### Private helpers

**`normalize_rows( array $rows )`** — runs `array_values()` over each row. A row array with
non-sequential keys would serialize to a JSON **object** (`{"0":…,"2":…}`) instead of an
array, and Google would reject it with an unhelpful error. Cheap insurance against an
obscure bug.

**`encode( array $payload )`** — wraps `wp_json_encode()` and converts its `false` return
into a real error:

```php
if ( false === $json ) {
    return new \WP_Error( 'wtg_encode_failed', 'Could not encode the order … invalid characters …' );
}
```

`wp_json_encode()` returns `false` on malformed UTF-8, which a product name imported from a
bad source really can contain. The original code passed that `false` straight into
`wp_remote_post()`, sending an **empty request body** and producing a baffling HTTP 400 from
Google. This guard names the real cause instead.

**`response_error( $response, $code )`** — shared by all three public methods so error
reporting is identical everywhere. Returns `null` on 2xx, otherwise a `WP_Error` carrying
Google's own `error.message` when present, or `Sheets API returned HTTP %d.` as a fallback.

**`a1_sheet( $sheet_name )`** — quotes a tab name for A1 notation:

```php
return "'" . str_replace( "'", "''", (string) $sheet_name ) . "'";
```

Unquoted names break the moment they contain a space (`Order Log`). Doubling an embedded
apostrophe is A1 notation's own escaping rule.

**`column_letter( $index )`** — 0 → `A`, 25 → `Z`, 26 → `AA`. A `do…while` with
`intdiv( $index, 26 ) - 1`; the `-1` is what makes the roll-over produce `AA` rather than
`BA`.

---

### `list_sheet_titles( $spreadsheet_id, $access_token )`

`GET {API_BASE}{id}?fields=sheets.properties.title`

Returns the tab names inside a spreadsheet, in sheet order. Backs the **Sheets List**
dropdown.

The `fields` mask matters: without it, `spreadsheets.get` returns the entire document, which
for a large sheet is an enormous response when all we want is a handful of strings.

**Needs no extra permission.** `auth/spreadsheets` already covers reading a spreadsheet whose
ID we hold — only the *file listing* below requires Drive.

---

## `class-drive-client.php` — `WTG\Google\Drive_Client`

**Purpose.** List the spreadsheets in the connected Google account, so the settings page can
offer them by name instead of asking for a raw ID.

**Depends on:** nothing. **Called by:** `Admin\Settings_Page` only — never by the sync.

### `list_spreadsheets( $access_token )`

`GET drive/v3/files`

| Parameter | Value | Why |
|---|---|---|
| `q` | `mimeType='application/vnd.google-apps.spreadsheet' and trashed=false` | Spreadsheets only, nothing in the bin |
| `fields` | `files(id,name)` | A full Drive file record is enormous |
| `orderBy` | `name` | Alphabetical, so the dropdown is scannable |
| `pageSize` | `200` | One page is plenty; no pagination loop |
| `supportsAllDrives` / `includeItemsFromAllDrives` | `true` | Include Shared Drives, not just My Drive |

**Why Drive at all?** The Sheets API cannot list files — it only works with an ID you already
have. There is no way to build this dropdown without Drive. MetForm Pro solves it the same
way, requesting full `auth/drive`; this plugin uses `drive.metadata.readonly`, which exposes
names and IDs but never file contents.

**401 and 403 get their own error code.** They almost always mean the stored token predates
the Drive scope, which is fixed by reconnecting rather than retrying — so
`wtg_drive_scope_missing` carries a message saying exactly that, and the settings page renders
a Disconnect button beside it.

## Error codes this folder produces

| Code | Raised by | Meaning |
|---|---|---|
| `wtg_not_connected` | `refresh_access_token()`, `get_valid_access_token()` | No refresh token stored |
| `wtg_no_refresh_token` | `exchange_code()` | Google returned no refresh token; reconnect |
| `wtg_reauth_required` | `refresh_access_token()` | `invalid_grant` — token revoked or expired |
| `wtg_oauth_error` | `parse_token_response()` | Any other OAuth failure; carries `google_error` |
| `wtg_bad_token_response` | `parse_token_response()` | Non-JSON reply from Google |
| `wtg_append_failed` | `append_rows()` | Non-2xx from `values:append` |
| `wtg_lookup_failed` | `find_rows_by_order_id()` | Non-2xx from the column read |
| `wtg_read_failed` | `read_row()` | Non-2xx when reading a row back |
| `wtg_sheet_list_failed` | `list_sheet_titles()` | Non-2xx when listing a spreadsheet's tabs |
| `wtg_drive_scope_missing` | `Drive_Client::list_spreadsheets()` | 401/403 — token predates the Drive scope; reconnect |
| `wtg_drive_list_failed` | `Drive_Client::list_spreadsheets()` | Any other non-2xx from Drive |
| `wtg_update_failed` | `update_rows()` | Non-2xx from `values:batchUpdate`, or count mismatch |
| `wtg_encode_failed` | `encode()` | Order data is not valid UTF-8 |

All of these surface in the Sync Log's **Last Error** column via
`Sync_Queue::mark( $id, $status, $result->get_error_message() )`.
