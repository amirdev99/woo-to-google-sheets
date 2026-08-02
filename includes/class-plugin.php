<?php
/**
 * The central plugin object (Singleton).
 *
 * Owns the plugin's shared constants and registers the hooks that run on every
 * request. One instance only, reachable anywhere via Plugin::instance().
 *
 * @package WTG
 */

namespace WTG;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * The single instance of this class.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * The action hook fired by WP-Cron to drain the sync queue.
	 *
	 * Kept here as the single source of truth: the Activator schedules THIS
	 * hook, the Deactivator clears it, and Phase 5 will attach the processor
	 * callback to it. One constant, no typo-prone duplicate strings.
	 */
	const CRON_HOOK = 'wtg_process_sync_queue';

	/**
	 * A SECOND hook, used for the one-off "drain the queue right now" event that
	 * the order listener schedules the moment an order changes.
	 *
	 * Why not reuse CRON_HOOK? wp_schedule_single_event() refuses to schedule an
	 * event when an identical hook is already due within ±10 minutes (see
	 * wp-includes/cron.php). Our recurring CRON_HOOK runs every 5 minutes, so it
	 * is ALWAYS inside that window — reusing it would make every immediate kick
	 * be silently rejected. A distinct hook name avoids the collision entirely.
	 *
	 * Both hooks run the exact same Sync_Processor::process().
	 */
	const CRON_HOOK_NOW = 'wtg_process_sync_queue_now';

	/**
	 * The name of our custom cron recurrence (registered via cron_schedules).
	 */
	const CRON_SCHEDULE = 'wtg_five_minutes';

	/**
	 * How often, in seconds, that recurrence fires (5 minutes).
	 */
	const CRON_INTERVAL = 300;

	/**
	 * Base name of our custom queue table, WITHOUT the wpdb prefix.
	 *
	 * Always combine with table_name() so the site's table prefix is applied.
	 */
	const TABLE_SUFFIX = 'wtg_sync_log';

	/**
	 * Return the single instance, creating it on first call.
	 *
	 * This is the ONLY way to obtain a Plugin object. Every later caller
	 * (activator, admin page, etc.) reuses this same object.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — blocks `new Plugin()` from outside the class.
	 *
	 * Intentionally empty: object construction must stay cheap and side-effect
	 * free. Hook registration happens in run(), called explicitly by the
	 * bootstrap on plugins_loaded, so we control exactly when it occurs.
	 */
	private function __construct() {}

	/**
	 * Block cloning — `clone $plugin` would otherwise create a second instance.
	 */
	private function __clone() {}

	/**
	 * Block unserialization — rebuilding the object from a string would bypass
	 * the Singleton. WordPress moves serialized data through caches/transients,
	 * so we defend against it explicitly.
	 *
	 * @throws \Exception Always, if something attempts to unserialize.
	 */
	public function __wakeup() {
		throw new \Exception( 'Plugin is a singleton and cannot be unserialized.' );
	}

	/**
	 * The fully-prefixed name of the sync-queue table for THIS site.
	 *
	 * Reads the live wpdb prefix (which differs per site, and per blog in
	 * multisite), so we never hard-code "wp_".
	 *
	 * @return string e.g. "wp_wtg_sync_log"
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Register the hooks that must run on every request.
	 *
	 * Called once by the bootstrap on `plugins_loaded`. Later phases will add
	 * more registrations here (admin menu, WooCommerce order hooks, the cron
	 * processor callback). For Phase 1 we only need the custom cron schedule
	 * and the text domain.
	 *
	 * @return void
	 */
	public function run() {
		// NOTE: the `cron_schedules` filter that defines our "Every 5 Minutes"
		// recurrence is intentionally registered UNCONDITIONALLY in the bootstrap
		// file, NOT here. run() only fires on `plugins_loaded`, but during a
		// plugin-activation request that action has already passed by the time our
		// activation hook runs — so a filter registered here would not exist yet
		// when Activator::activate() calls wp_schedule_event(). See the bootstrap
		// for the full explanation.

		// Load translations. Hooked on `init` because loading a text domain too
		// early triggers a _doing_it_wrong notice in modern WordPress.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// --- All-context wiring -------------------------------------------------
		// The order listener must run on the FRONT-END too (checkout), so it is
		// registered unconditionally. Its hooks simply never fire without
		// WooCommerce present.
		( new WooCommerce\Order_Listener() )->hooks();

		// The cron events drain the queue. Attach the processor in every context,
		// since WP-Cron runs in its own request. One processor instance serves
		// both the recurring 5-minute safety net and the immediate one-off kick.
		$processor = new Queue\Sync_Processor();
		add_action( self::CRON_HOOK, array( $processor, 'process' ) );
		add_action( self::CRON_HOOK_NOW, array( $processor, 'process' ) );

		// --- Admin-only wiring --------------------------------------------------
		// is_admin() is true for wp-admin, admin-ajax and admin-post requests, and
		// false on the front-end. (Admin\ resolves to WTG\Admin\ because this
		// class is in the WTG namespace.)
		if ( is_admin() ) {
			// Self-heal the table/cron if the plugin's files were updated over FTP
			// rather than re-activated, which never fires the activation hook.
			// Admin-only: a broken install should be repaired by an admin visiting
			// wp-admin, not by a front-end visitor's request.
			add_action( 'admin_init', array( 'WTG\\Activator', 'maybe_install' ) );

			( new Admin\Settings_Page() )->hooks();
			// Connect/callback/disconnect/test admin-post actions + their notices.
			( new Admin\OAuth_Controller() )->hooks();
			// Process Queue Now + Retry admin-post actions.
			( new Admin\Queue_Controller() )->hooks();
		}
	}

	/**
	 * Add our custom recurrence to WordPress's cron schedule list.
	 *
	 * WP-Cron only accepts a recurrence name that exists in this filtered array,
	 * so both runtime scheduling and the Activator depend on this being present.
	 *
	 * Declared STATIC so the bootstrap can register it as a plain callable
	 * (array( 'WTG\Plugin', 'register_cron_schedule' )) at file-load time,
	 * before any Plugin instance exists.
	 *
	 * @param array $schedules Existing schedules keyed by name.
	 * @return array Modified schedules.
	 */
	public static function register_cron_schedule( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => self::CRON_INTERVAL,
			// Human-readable label shown on Tools > Site Health / cron viewers.
			'display'  => __( 'Every 5 Minutes', 'woo-to-gsheet' ),
		);
		return $schedules;
	}

	/**
	 * Load the plugin's translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'woo-to-gsheet',
			false,
			dirname( WTG_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
