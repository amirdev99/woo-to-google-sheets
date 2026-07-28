=== WooCommerce to Google Sheets ===
Contributors: amir
Tags: woocommerce, google sheets, orders, export, sync
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Append every processing/completed WooCommerce order to a Google Sheet via the
Google Sheets API v4 (OAuth2), using a reliable background queue.

== Description ==

When a WooCommerce order reaches the *processing* or *completed* status, this
plugin records it and appends a row to a Google Sheet you own. Authorization is
per-site, using your own Google Cloud OAuth client (Client ID + Secret + consent
screen) — not Apps Script and not a service account.

= How it works (architecture) =

The plugin never calls Google during checkout. Instead:

1. **Queue on the hook.** When an order becomes processing/completed, a row is
   inserted into a custom table (`{prefix}wtg_sync_log`) as `pending`. This is a
   single fast local write, so checkout is never slowed or broken by Google.
   Each order is de-duplicated, so it is only ever queued once.
2. **Drain on a schedule.** A WP-Cron job (every 5 minutes) picks up pending
   rows in batches, gets a valid OAuth access token (refreshing transparently),
   maps each order to a row, and appends it via the Sheets `values:append`
   endpoint.
3. **Retry + log.** A failed row is retried up to 5 times, then marked failed
   with its last error. The Sync Log tab shows every row's status, attempts, and
   error, with a Retry link and a "Process Queue Now" button.

= The columns written (A–J) =

Order ID, Date, Status, Customer Name, Email, Phone, Products, Total, Currency,
Payment Method. The plugin appends **data rows only** — add a matching header
row manually in row 1 of your sheet if you want headers.

== Installation ==

1. Upload the plugin and activate it. Activation creates the queue table and
   schedules the cron.
2. In the Google Cloud Console: create a project, enable the **Google Sheets
   API**, configure the **OAuth consent screen** (External; add yourself as a
   Test user), and create an **OAuth client ID** of type **Web application**.
3. Copy the exact **Redirect URI** shown on the plugin's Connection tab into the
   Google client's *Authorized redirect URIs*. (Google requires HTTPS except for
   localhost.)
4. Paste the **Client ID** and **Client Secret** into the Connection tab and
   Save, then click **Connect Google Account** and approve access.
5. Enter your **Spreadsheet ID** (from the sheet URL) and **Sheet Name**, Save,
   and click **Test Connection** to confirm.

== Frequently Asked Questions ==

= Orders are not appearing in the sheet immediately =

WP-Cron only runs when the site receives traffic, so on a quiet site the queue
may wait until the next visit. Use **Process Queue Now** on the Sync Log tab to
flush it on demand, or configure a real system cron calling wp-cron.php.

= My connection stopped working after about a week =

If your Google OAuth consent screen is in **Testing** mode, Google expires
refresh tokens after 7 days. Set the publishing status to **In production**
(verification is optional for this per-site use) to avoid weekly reconnects. The
plugin detects an expired token and shows a "reconnect" notice.

== Hardening notes ==

* The de-dupe on enqueue is a check-then-insert. For a bulletproof guarantee,
  add a `UNIQUE` index on `order_id` to the `wtg_sync_log` table so the database
  itself rejects duplicates.

== Changelog ==

= 0.1.0 =
* Initial build: boilerplate, settings, OAuth2 connect/test, order queue,
  background sync with retry, sync log, uninstall.
