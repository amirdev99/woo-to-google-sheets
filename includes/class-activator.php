<?php
/**
 * Runs once on plugin activation.
 *
 * Creates the sync-queue table and schedules the recurring cron event. Kept
 * separate from the Plugin class because this is one-time, side-effect-heavy
 * setup that must never run on a normal request.
 *
 * @package WTG
 */

namespace WTG;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 */
class Activator {

	/**
	 * The option key under which we store the installed DB schema version.
	 *
	 * On a future schema change we bump this and re-run dbDelta() to migrate
	 * existing installs non-destructively.
	 */
	const DB_VERSION_OPTION = 'wtg_db_version';

	/**
	 * The SCHEMA version — deliberately separate from WTG_VERSION.
	 *
	 * Bump this only when the table definition below changes. Tying it to the
	 * plugin version instead would re-run dbDelta on every release for no reason,
	 * and would give no way to signal a schema change within one release.
	 *
	 * 1.0.0 — initial table.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Entry point fired by register_activation_hook().
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Make sure the table exists and the cron is scheduled, on a NORMAL request.
	 *
	 * activate() runs only when someone clicks Activate. It does NOT run when the
	 * plugin's files are replaced over FTP, which is a completely normal way to
	 * deploy — and it is exactly how this plugin gets updated. If the table is
	 * missing for any reason (a failed activation, files copied onto a fresh site,
	 * a restored database), every enqueue fails silently: $wpdb->insert() into a
	 * table that does not exist returns false, and nothing surfaces anywhere. The
	 * order simply never appears in the Sync Log.
	 *
	 * So we check cheaply on admin requests and repair if needed. The version
	 * option is autoloaded, so the common case costs no query at all — only a
	 * version mismatch triggers the SHOW TABLES check and dbDelta.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		self::install();
	}

	/**
	 * Create/upgrade the table and schedule the cron. Safe to run repeatedly:
	 * dbDelta only issues the statements needed to reconcile the schema, and
	 * schedule_cron() is guarded by wp_next_scheduled().
	 *
	 * @return void
	 */
	public static function install() {
		self::create_table();
		self::schedule_cron();
	}

	/**
	 * Does the queue table actually exist right now?
	 *
	 * Worth having as its own check because a missing table is invisible
	 * everywhere else — see maybe_install().
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = Plugin::table_name();

		// SHOW TABLES LIKE takes the name as a STRING literal, so %s is correct
		// here — unlike the FROM clauses elsewhere, where a table name cannot be
		// a placeholder and must be interpolated.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Create (or update) the wtg_sync_log queue table via dbDelta.
	 *
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		$table = Plugin::table_name();

		// Match the site's charset/collation (e.g. utf8mb4) so we can store any
		// character an order might contain. Never hard-code a charset here.
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * dbDelta parses this SQL with regular expressions, so its formatting
		 * rules are strict and intentional:
		 *   - each field/key on its OWN line,
		 *   - exactly TWO spaces between "PRIMARY KEY" and "(id)",
		 *   - every KEY has an index name (e.g. "KEY status (status)").
		 * Breaking these makes dbDelta silently mis-parse or endlessly re-run
		 * ALTERs. See the phase intro for the full "why".
		 *
		 * Columns:
		 *   id         - surrogate primary key.
		 *   order_id   - the WooCommerce order this queue row represents.
		 *   status     - queue state: pending | processing | success | failed.
		 *   attempts   - how many times the processor has tried this row.
		 *   last_error - the most recent failure message (NULL until one occurs).
		 *   created_at - when the row was enqueued.
		 *   updated_at - when the row last changed state.
		 */
		$sql = "CREATE TABLE {$table} (
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
		) {$charset_collate};";

		// dbDelta() lives in an admin-only file that is NOT loaded on normal
		// requests, so we include it explicitly before calling it.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// dbDelta compares this desired schema against the live table and issues
		// only the CREATE/ALTER statements needed to reconcile them.
		dbDelta( $sql );

		// Record the SCHEMA version we just installed. maybe_install() compares
		// against this on later requests to decide whether to re-run dbDelta.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Schedule the recurring queue-draining cron event, if not already set.
	 *
	 * @return void
	 */
	private static function schedule_cron() {
		// wp_next_scheduled() returns the next run timestamp, or false if the
		// hook is not scheduled. Guarding on it means re-activating the plugin
		// will not stack multiple duplicate events.
		if ( ! wp_next_scheduled( Plugin::CRON_HOOK ) ) {
			// Start the first run now (time()), then repeat on our custom
			// 5-minute recurrence. The recurrence name is known because the
			// bootstrap registered the cron_schedules filter unconditionally.
			wp_schedule_event( time(), Plugin::CRON_SCHEDULE, Plugin::CRON_HOOK );
		}
	}
}
