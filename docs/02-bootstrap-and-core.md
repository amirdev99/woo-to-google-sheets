# 02 — Bootstrap and core

Covers `woo-to-google-sheets.php`, `uninstall.php`, and the five files at the root of
`includes/`.

---

## `woo-to-google-sheets.php` — the bootstrap

**Purpose.** The file WordPress reads to discover the plugin. It defines constants,
registers the autoloader, and wires up the three lifecycle events (activate, deactivate,
boot). It contains no classes.

### The plugin header

Lines 3–11 hold the header comment WordPress parses. `Text Domain: woo-to-gsheet` must match
every `__()` call in the plugin, and `Requires PHP: 7.4` is enforced by WordPress before it
loads the file.

### Constants (lines 31–46)

| Constant | Value | Why it exists |
|---|---|---|
| `WTG_VERSION` | `'0.1.0'` | Written to `wtg_db_version` by `Activator::create_table()` |
| `WTG_PLUGIN_FILE` | `__FILE__` | `register_activation_hook()` needs the main file path |
| `WTG_PLUGIN_DIR` | `plugin_dir_path( __FILE__ )` | Used by the autoloader to build file paths; **has a trailing slash** |
| `WTG_PLUGIN_URL` | `plugin_dir_url( __FILE__ )` | For future CSS/JS enqueueing; currently unused |
| `WTG_PLUGIN_BASENAME` | `plugin_basename( __FILE__ )` | Used by `Plugin::load_textdomain()` to find `/languages` |

### Autoloader registration (lines 56–57)

```php
require_once WTG_PLUGIN_DIR . 'includes/class-autoloader.php';
WTG\Autoloader::register();
```

The autoloader is the one class that cannot be autoloaded, so it is required by hand.

### The cron schedule filter (line 76) — and why it is here, not in `Plugin::run()`

```php
add_filter( 'cron_schedules', array( 'WTG\\Plugin', 'register_cron_schedule' ) );
```

This is the most important non-obvious line in the file, and the comment above it explains
why at length. The short version:

`Plugin::run()` is hooked to `plugins_loaded`. But during a **plugin activation request**,
WordPress has already fired `plugins_loaded` before it includes and activates your plugin.
So a filter registered inside `run()` would not exist yet when `Activator::activate()` calls
`wp_schedule_event( time(), 'wtg_five_minutes', ... )`. WP-Cron rejects unknown recurrence
names silently, and the cron would never be scheduled.

Registering at file scope means the recurrence is known on **every** request that loads the
plugin — including the activation request. This is a real bug that was found and fixed
during the build.

### Lifecycle hooks (lines 89–90)

```php
register_activation_hook( __FILE__, array( 'WTG\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WTG\\Deactivator', 'deactivate' ) );
```

Passing string callables means the autoloader only loads those files during activation and
deactivation, never on a normal page load.

### Boot (lines 103–108)

```php
add_action( 'plugins_loaded', static function () {
    WTG\Plugin::instance()->run();
} );
```

`plugins_loaded` is chosen so WooCommerce is fully loaded first — `Order_Listener` registers
hooks whose names come from WooCommerce.

---

## `includes/class-autoloader.php`

**Purpose.** Translate a class name like `WTG\Google\OAuth_Client` into the file
`includes/Google/class-oauth-client.php` and `require_once` it.

### `Autoloader::register()`
Calls `spl_autoload_register( array( __CLASS__, 'autoload' ) )`. Using SPL rather than
`__autoload` means the plugin coexists with WordPress core and every other plugin's loader.

### `Autoloader::autoload( $class )`

Six steps, worth walking through because the naming convention is strict:

1. **Bail if not ours** — `strncmp( $class, 'WTG\\', 4 )`. Any other class is left for the
   next autoloader in the stack. `strncmp` is used rather than `substr` to avoid allocating.
2. **Strip the prefix** — `WTG\Google\OAuth_Client` → `Google\OAuth_Client`.
3. **Split on `\`** and `array_pop()` the last segment: `$class_name = 'OAuth_Client'`,
   `$parts = [ 'Google' ]`.
4. **Build the file name** — lowercase, underscores to hyphens, prefixed and suffixed:
   `OAuth_Client` → `class-oauth-client.php`.
5. **Rebuild the sub-path**, and this is the subtle part:

   ```php
   $sub_path = empty( $parts ) ? '' : implode( DIRECTORY_SEPARATOR, $parts ) . DIRECTORY_SEPARATOR;
   ```

   Sub-directories keep their **original case** — `Google`, `Queue`, `Admin`, `WooCommerce`.
   Only the file name is lowercased. **On Linux this is case-sensitive**, so if these folders
   are ever uploaded with different capitalisation (some FTP clients do this), no class will
   load and the plugin will fatal. On Windows and macOS it silently works. This is a real
   deployment trap.

6. **Load if present** — guarded by `is_file()`. If the file is missing, the autoloader does
   nothing and lets PHP raise its normal "Class not found" error, which names the class and
   is easier to debug than a failed `require`.

**Registers no WordPress hooks.**

---

## `includes/class-plugin.php`

**Purpose.** A Singleton holding the plugin's shared constants and, in `run()`, the single
place where every per-request hook is wired.

### Constants

| Constant | Value | Used by |
|---|---|---|
| `CRON_HOOK` | `'wtg_process_sync_queue'` | `Activator::schedule_cron()`, `Deactivator`, `run()`, `uninstall.php` |
| `CRON_HOOK_NOW` | `'wtg_process_sync_queue_now'` | `Order_Listener::schedule_immediate_run()`, `Deactivator`, `run()`, `uninstall.php` |
| `CRON_SCHEDULE` | `'wtg_five_minutes'` | `register_cron_schedule()`, `Activator::schedule_cron()` |
| `CRON_INTERVAL` | `300` | The recurrence interval in seconds |
| `TABLE_SUFFIX` | `'wtg_sync_log'` | `table_name()` |

**Why two cron hooks?** `wp_schedule_single_event()` silently refuses to schedule an event
when an identical hook is already due within ±10 minutes (see `wp-includes/cron.php`). The
recurring `CRON_HOOK` fires every 5 minutes, so it is *always* inside that window — reusing
it for the immediate kick would mean every kick was rejected with no error. A separate hook
name sidesteps the collision. Both hooks run the same callback.

### Singleton mechanics

- `instance()` — lazily creates and returns the one object.
- `__construct()` is **private and empty**. Construction stays free of side effects; all
  wiring happens in `run()`, so the bootstrap controls exactly when hooks are registered.
- `__clone()` is private.
- `__wakeup()` **throws** — because WordPress moves serialized data through transients and
  object caches, and unserializing would quietly create a second instance.

### `Plugin::table_name()`

```php
return $wpdb->prefix . self::TABLE_SUFFIX;
```

Reads the live prefix each call rather than caching it, so multisite (where the prefix
differs per blog) works correctly.

### `Plugin::run()` — the wiring table

| What | Context | Line |
|---|---|---|
| `add_action( 'init', [ $this, 'load_textdomain' ] )` | all | 144 |
| `( new WooCommerce\Order_Listener() )->hooks()` | all — must run on the front end during checkout | 150 |
| `add_action( CRON_HOOK, [ $processor, 'process' ] )` | all — cron runs in its own request | 156 |
| `add_action( CRON_HOOK_NOW, [ $processor, 'process' ] )` | all | 157 |
| `( new Admin\Settings_Page() )->hooks()` | `is_admin()` only | 164 |
| `( new Admin\OAuth_Controller() )->hooks()` | `is_admin()` only | 166 |
| `( new Admin\Queue_Controller() )->hooks()` | `is_admin()` only | 168 |

One `Sync_Processor` instance is shared by both cron hooks.

`is_admin()` returns true for `wp-admin`, `admin-ajax.php` **and** `admin-post.php` — which
is why the OAuth callback and the queue buttons work despite being inside that branch.

### `Plugin::register_cron_schedule( $schedules )` (static)

Adds `wtg_five_minutes` with `interval => 300`. Declared **static** so the bootstrap can
reference it as `array( 'WTG\Plugin', 'register_cron_schedule' )` at file-load time, before
any instance exists.

**Hooks registered:** `cron_schedules` (from the bootstrap, not from this class).

---

## `includes/class-activator.php`

**Purpose.** One-time setup on activation: create the table, schedule the cron.

**Hook:** `register_activation_hook` → `Activator::activate()`.

### `activate()` / `install()` / `maybe_install()`

`activate()` (the activation hook) just calls `install()`, which runs `create_table()` and
`schedule_cron()`. Both are safe to re-run: `dbDelta` only issues what is needed, and
`schedule_cron()` is guarded by `wp_next_scheduled()`.

**`maybe_install()` is the important one.** It is hooked on `admin_init` from `Plugin::run()`
and repairs a broken install on an ordinary admin request:

```php
if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION && self::table_exists() ) {
    return;
}
self::install();
```

The activation hook runs **only** when someone clicks Activate — never when the plugin's files
are replaced over FTP, which is how this plugin actually gets deployed. If the table is missing
for any reason, every `Sync_Queue::enqueue()` fails silently (`$wpdb->insert()` into a missing
table returns `false` and nothing surfaces), and orders simply never appear in the Sync Log.

The version option is autoloaded, so the healthy path costs no query beyond the `SHOW TABLES`
check.

### `DB_VERSION` — separate from `WTG_VERSION`

`DB_VERSION` (currently `1.0.0`) tracks the **schema**, not the plugin. Bump it only when the
table definition changes. Tying it to the plugin version would re-run `dbDelta` on every
release for nothing, and would give no way to signal a schema change within a release.

`wtg_db_version` therefore stores `1.0.0`, not the plugin's `0.1.0`.

### `table_exists()`
A `SHOW TABLES LIKE` check. Worth having as its own method because a missing table is
invisible everywhere else in the plugin.

### `create_table()` (private)

Builds a `CREATE TABLE` statement and runs it through `dbDelta()`. Three details matter:

1. `$wpdb->get_charset_collate()` is appended so the table matches the site's charset —
   never hard-code `utf8`, or emoji in a product name will break the insert.
2. **dbDelta's formatting rules are strict** and the comment in the file spells them out:
   each column on its own line, exactly **two spaces** between `PRIMARY KEY` and `(id)`, and
   every `KEY` needs an index name. Break these and dbDelta silently mis-parses, or re-runs
   the same `ALTER` on every load.
3. `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` — `dbDelta()` lives in an
   admin-only file that is not loaded on normal requests.

Finally: `update_option( self::DB_VERSION_OPTION, self::DB_VERSION )` records `wtg_db_version`,
which is what `maybe_install()` compares against later.

Full schema in `07-database-schema.md`.

### `schedule_cron()` (private)

```php
if ( ! wp_next_scheduled( Plugin::CRON_HOOK ) ) {
    wp_schedule_event( time(), Plugin::CRON_SCHEDULE, Plugin::CRON_HOOK );
}
```

The guard means re-activating does not stack duplicate events.

> **Previously a known gap, now closed.** An FTP file replacement never fires the activation
> hook, so a missing table used to mean silent enqueue failures forever.
> `maybe_install()` on `admin_init` now repairs it. Verified by dropping the table and
> confirming it was recreated and `enqueue()` worked again.

---

## `includes/class-deactivator.php`

**Purpose.** Stop runtime activity without destroying data.

**Hook:** `register_deactivation_hook` → `Deactivator::deactivate()`.

`clear_cron()` calls `wp_clear_scheduled_hook()` for **both** `Plugin::CRON_HOOK` and
`Plugin::CRON_HOOK_NOW`. A still-scheduled hook whose code is no longer loaded is an orphan
WP-Cron keeps trying to fire.

It deliberately does **not** drop the table or delete options — deactivation is often
temporary (troubleshooting, updates), and destroying queued orders on a toggle would be
silent data loss. That belongs in `uninstall.php`.

---

## `includes/class-settings.php`

**Purpose.** The only class in the plugin that calls `get_option()` / `update_option()`.
Everything lives in **one** option array, `wtg_settings`, rather than many separate options.

### `Settings::OPTION_KEY` = `'wtg_settings'`

### `defaults()`
Declares all eight keys up front so callers always get a predictable type and never have to
guard for a missing key. Full table in `08-settings-reference.md`.

### `all()`
```php
$stored = get_option( self::OPTION_KEY, array() );
if ( ! is_array( $stored ) ) { $stored = array(); }
return wp_parse_args( $stored, self::defaults() );
```
`wp_parse_args` merges stored values **over** defaults, so a key never saved still returns
its default. The `is_array` check guards against a corrupted option.

### `get( $key, $default = null )`
Uses `array_key_exists()` rather than `isset()` — important, because `isset()` returns false
for a value of `null`, and would wrongly fall through to the default.

### `set( $key, $value )` / `update( array $values )`
Both read the **raw** stored array (not the defaults-merged one) so that only values which
were actually set get persisted, then write the whole array back. `update()` uses
`array_merge`, so incoming keys overwrite and untouched keys survive.

> **Important side effect.** Because `Settings_Page::register_settings()` registers
> `wtg_settings` with a `sanitize_callback`, WordPress applies the filter
> `sanitize_option_wtg_settings` on **every** `update_option()` of this key — including these
> programmatic token writes from `OAuth_Client`. That is why `Settings_Page::sanitize()` has
> to explicitly pass the token keys through. See `06-admin.md`.

### `delete( $key )`
Removes a key so it reverts to its default. Currently unused by the plugin.

**Registers no hooks.**

---

## `uninstall.php`

**Purpose.** The destructive cleanup that deactivation deliberately avoids. WordPress runs
this **only** when the user deletes the plugin.

It is guarded by `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }` and deliberately does
**not** load the plugin's classes — WordPress runs uninstall in a minimal environment, so
the values are duplicated as literals:

```php
$table      = $wpdb->prefix . 'wtg_sync_log';
$options    = array( 'wtg_settings', 'wtg_db_version' );
$cron_hooks = array( 'wtg_process_sync_queue', 'wtg_process_sync_queue_now' );
```

Then it drops the table, deletes both options in a loop, and clears both cron hooks in a
loop.

> **Maintenance note.** Those literals mirror `Plugin::TABLE_SUFFIX`, `Plugin::CRON_HOOK`,
> `Plugin::CRON_HOOK_NOW`, `Settings::OPTION_KEY` and `Activator::DB_VERSION_OPTION`. If you
> rename any of them, this file will not follow automatically.
