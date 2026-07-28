<?php
/**
 * Plugin Name:       WooCommerce to Google Sheets
 * Description:        Appends each processing/completed WooCommerce order to a Google Sheet via the Google Sheets API v4 (OAuth2).
 * Version:           0.1.0
 * Author:            Amir
 * Text Domain:       woo-to-gsheet
 * Requires PHP:      7.4
 * Requires at least: 5.8
 *
 * @package WTG
 */

// If this file is accessed directly (not through WordPress), stop immediately.
// ABSPATH is only defined once WordPress has booted, so its absence means the
// file was hit directly — we must not run any further.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Plugin-wide constants.
 * Defined once here so every other file can rely on absolute paths, URLs and
 * the version without recomputing them. The WTG_ prefix avoids collisions
 * with WordPress core or other plugins.
 * ---------------------------------------------------------------------------
 */

// Single source of truth for the plugin version (asset cache-busting, DB upgrades).
define( 'WTG_VERSION', '0.1.0' );

// Absolute path to THIS main file. register_activation_hook() needs it, and it
// is the canonical reference to the plugin's entry point.
define( 'WTG_PLUGIN_FILE', __FILE__ );

// Plugin folder path on disk, WITH trailing slash (e.g. ".../woo-to-google-sheets/").
// plugin_dir_path() guarantees the trailing slash on every OS.
define( 'WTG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Public URL to the plugin folder, WITH trailing slash — used to enqueue admin assets later.
define( 'WTG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// The "folder/main-file.php" identifier WordPress uses in hooks such as
// plugin_action_links_{basename} (we will use it to add a Settings link later).
define( 'WTG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * ---------------------------------------------------------------------------
 * Autoloader.
 * The autoloader is the one class we cannot autoload (it is what makes
 * autoloading work), so we require it by hand and register it. After this line,
 * referencing any WTG\... class loads its file on demand.
 * ---------------------------------------------------------------------------
 */
require_once WTG_PLUGIN_DIR . 'includes/class-autoloader.php';
WTG\Autoloader::register();

/*
 * ---------------------------------------------------------------------------
 * Custom cron recurrence — registered UNCONDITIONALLY here, at top level, on
 * purpose. Do NOT move this inside Plugin::run().
 *
 * run() is hooked to `plugins_loaded`. But during a plugin-activation request,
 * WordPress boots and fires `plugins_loaded` BEFORE it includes and activates
 * our plugin. So by the time our activation hook runs and calls
 * Activator::activate() -> wp_schedule_event( ..., 'wtg_five_minutes', ... ),
 * a run()-registered filter would not exist yet, wp_schedule_event() would
 * reject the unknown recurrence, and the cron would silently never schedule.
 *
 * Registering the filter here, at file load, guarantees the recurrence is known
 * on EVERY request that loads the plugin — the activation request, normal
 * front-end/admin requests, and direct wp-cron.php hits alike.
 * ---------------------------------------------------------------------------
 */
add_filter( 'cron_schedules', array( 'WTG\\Plugin', 'register_cron_schedule' ) );

/*
 * ---------------------------------------------------------------------------
 * Lifecycle hooks (activation / deactivation).
 * These run ONCE, when the user activates or deactivates the plugin — not on
 * every page load. Pointing them at dedicated classes keeps one-time
 * setup/teardown separate from the per-request hook wiring in the Plugin class.
 *
 * The array( 'Class', 'method' ) callables are autoloaded lazily, so the
 * Activator/Deactivator files are only ever read during these events.
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( 'WTG\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WTG\\Deactivator', 'deactivate' ) );

/*
 * ---------------------------------------------------------------------------
 * Boot the plugin.
 * We wait for `plugins_loaded` so WordPress core and other plugins — crucially
 * WooCommerce — are fully loaded before our code registers its hooks. Booting
 * earlier risks calling WooCommerce functions that do not exist yet.
 *
 * instance() returns the single Plugin object (Singleton); run() wires up all
 * of its per-request hooks exactly once.
 * ---------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function () {
		WTG\Plugin::instance()->run();
	}
);
