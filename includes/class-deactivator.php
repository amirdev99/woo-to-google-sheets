<?php
/**
 * Runs once on plugin deactivation.
 *
 * Non-destructive by design: it stops runtime activity (the cron) but never
 * deletes user data. Dropping the table and deleting options is reserved for
 * uninstall.php, which only runs when the user explicitly deletes the plugin.
 *
 * @package WTG
 */

namespace WTG;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivator
 */
class Deactivator {

	/**
	 * Entry point fired by register_deactivation_hook().
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::clear_cron();
	}

	/**
	 * Remove our recurring cron event from WordPress's schedule.
	 *
	 * Once the plugin is inactive its code no longer loads, so a still-scheduled
	 * hook would be an orphan WP-Cron keeps trying to fire with nothing to run.
	 * wp_clear_scheduled_hook() unschedules EVERY instance of the given hook,
	 * which is exactly what we want here.
	 *
	 * We intentionally do NOT drop the wtg_sync_log table or delete options:
	 * deactivation is frequently temporary (troubleshooting, updates), and
	 * destroying queued orders on a toggle would be silent data loss. That
	 * cleanup belongs in uninstall.php.
	 *
	 * @return void
	 */
	private static function clear_cron() {
		wp_clear_scheduled_hook( Plugin::CRON_HOOK );
		// The one-off "sync now" event may also be pending when the plugin is
		// switched off; leaving it behind would be the same kind of orphan.
		wp_clear_scheduled_hook( Plugin::CRON_HOOK_NOW );
	}
}
