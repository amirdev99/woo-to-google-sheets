<?php
/**
 * Decides WHEN the queue gets drained, and makes sure it actually happens.
 *
 * Sync_Processor knows how to send orders. This class is the layer above it: it
 * answers "run the batch now, reliably, without making a customer wait".
 *
 * The problem it solves
 * --------------------
 * WP-Cron is not a clock. An event only runs when an HTTP request arrives, and
 * the usual trick for forcing one — spawn_cron() — fires a non-blocking loopback
 * request to wp-cron.php. That loopback fails silently in very ordinary setups:
 * a self-signed certificate on a local/staging site, HTTP auth, a host that
 * firewalls its own IP, DISABLE_WP_CRON. wp_remote_post() is called with
 * 'blocking' => false, so a total failure is indistinguishable from success.
 *
 * spawn_cron() also refuses to fire more than once every 60 seconds, and sets
 * its lock BEFORE attempting the request — so one failed loopback suppresses the
 * next order's attempt too.
 *
 * The result is a plugin that works when you press "Process Queue Now" and looks
 * broken the rest of the time.
 *
 * The approach
 * ------------
 * Four independent layers, so no single mechanism has to be the one that works:
 *
 *  1. IN-PROCESS (primary). The request that created the order sends its response
 *     to the browser, detaches, and then drains the queue itself. No loopback, no
 *     cron, no second request — nothing left to be blocked.
 *  2. LOOPBACK. Only used when layer 1 cannot detach the response (see run_soon()).
 *  3. ADMIN CATCH-UP. Any admin page load drains a backlog. Throttled, and free
 *     when the queue is empty.
 *  4. The recurring five-minute WP-Cron event, unchanged, as a floor.
 *
 * Everything funnels through run(), so the cross-request lock and the "last run"
 * timestamp apply no matter which layer triggered it.
 *
 * @package WTG
 */

namespace WTG\Queue;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sync_Runner
 */
class Sync_Runner {

	/**
	 * Option holding the timestamp of the currently running batch.
	 *
	 * An OPTION rather than a transient because we need the write to be atomic
	 * across concurrent requests: add_option() is an INSERT that fails when the
	 * row already exists, which gives us a real mutex. A transient read/write is
	 * check-then-set and two simultaneous checkouts would both win it.
	 */
	const LOCK_OPTION = 'wtg_queue_lock';

	/**
	 * Seconds after which a held lock is treated as abandoned.
	 *
	 * A run that dies mid-batch (PHP timeout, fatal) never releases its lock, so
	 * without an expiry the queue would stop forever. Comfortably longer than a
	 * batch of 20 orders should ever take.
	 */
	const LOCK_TTL = 180;

	/**
	 * Option holding the UTC timestamp of the last completed automatic run.
	 *
	 * Drives the "Last automatic sync" line in the admin, which is how a shop
	 * owner can see the plugin is alive without pressing anything.
	 */
	const LAST_RUN_OPTION = 'wtg_queue_last_run';

	/**
	 * Transient that rate-limits the admin catch-up (layer 3).
	 */
	const CATCHUP_THROTTLE = 'wtg_queue_catchup';

	/**
	 * How often, in seconds, the admin catch-up may look at the queue.
	 */
	const CATCHUP_INTERVAL = 60;

	/**
	 * Have we already registered the shutdown handler for THIS request?
	 *
	 * Several orders can be saved in one request (a bulk status change in the
	 * orders list). Each calls run_soon(); only the first needs to hook shutdown,
	 * and the single run it triggers drains all of them in one batch.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Ask for the queue to be drained as soon as it is safe to do so.
	 *
	 * Returns whether this request will handle it in-process. The caller uses
	 * that to decide whether a loopback fallback is still needed — see
	 * Order_Listener::schedule_immediate_run().
	 *
	 * @return bool True if this request will drain the queue itself.
	 */
	public static function run_soon() {
		if ( self::$hooked ) {
			// Already promised for this request; the pending run covers this row.
			return true;
		}

		if ( ! self::can_run_in_process() ) {
			return false;
		}

		self::$hooked = true;

		// PHP_INT_MAX so we go last: every other shutdown callback (WooCommerce's
		// own, object-cache flushes, query logging) finishes before we start
		// spending seconds on Google.
		add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), PHP_INT_MAX );

		return true;
	}

	/**
	 * Shutdown handler: release the browser, then do the slow work.
	 *
	 * @return void
	 */
	public static function on_shutdown() {
		self::close_response();

		// The customer already has their page and may well have navigated away.
		// Without this, PHP would abort the sync half-finished when it notices
		// the connection is gone.
		ignore_user_abort( true );

		self::run();
	}

	/**
	 * Drain a batch, guarded by the cross-request lock.
	 *
	 * The single entry point for every layer — cron, shutdown, admin catch-up and
	 * the manual button all come through here, so none of them can overlap.
	 * Overlapping runs are not merely wasteful: two processes claiming the same
	 * pending row would both look it up in the sheet, both find it absent, and
	 * both append it.
	 *
	 * @return array|null Counts from Sync_Processor, or null if a run was already
	 *                    in progress (in which case that run covers this work).
	 */
	public static function run() {
		if ( ! self::acquire_lock() ) {
			return null;
		}

		try {
			$counts = ( new Sync_Processor() )->process();
		} finally {
			// finally, so a fatal or an exception inside the processor still frees
			// the lock rather than stalling the queue until LOCK_TTL expires.
			self::release_lock();
		}

		// Recorded even for an empty batch: the value answers "is the automation
		// running at all?", not "when did an order last sync?".
		update_option( self::LAST_RUN_OPTION, time(), false );

		return $counts;
	}

	/**
	 * Layer 3: drain any backlog when an admin loads a wp-admin page.
	 *
	 * Hooked on `admin_init`. This is the safety net for a shop that is quiet
	 * enough that WP-Cron rarely fires — the moment anyone opens the dashboard,
	 * whatever is waiting goes out.
	 *
	 * Deliberately cheap in the normal case: one transient read, and beyond that
	 * one indexed LIMIT 1 query at most once a minute.
	 *
	 * @return void
	 */
	public static function catch_up() {
		// Cron already runs the queue directly; AJAX requests (heartbeat, autosave)
		// fire constantly and must not each drag a Google sync behind them.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( get_transient( self::CATCHUP_THROTTLE ) ) {
			return;
		}
		set_transient( self::CATCHUP_THROTTLE, 1, self::CATCHUP_INTERVAL );

		if ( ! Sync_Queue::has_pending() ) {
			return;
		}

		self::run_soon();
	}

	/**
	 * When did the last automatic run finish?
	 *
	 * @return int UTC timestamp, or 0 if the queue has never run.
	 */
	public static function last_run() {
		return (int) get_option( self::LAST_RUN_OPTION, 0 );
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * Is draining the queue inside this request safe?
	 *
	 * The in-process layer only works if we can hand the response back to the
	 * browser BEFORE the sync starts. PHP-FPM and LiteSpeed expose a function for
	 * exactly that; mod_php does not, and there the connection stays open until
	 * the script ends — so a customer would sit on a spinning tab while we talk to
	 * Google.
	 *
	 * On such a server we still run in-process for admin and CLI requests (a
	 * two-second delay on an admin page is a fair trade for a working sync) but
	 * never on the front end, where the loopback fallback takes over instead.
	 *
	 * @return bool
	 */
	private static function can_run_in_process() {
		/**
		 * Filter: disable in-process draining entirely.
		 *
		 * An escape hatch for a host where holding the PHP worker open after the
		 * response is genuinely unwanted. Falling back to loopback + cron.
		 *
		 * @param bool $enabled Whether in-process draining is allowed.
		 */
		if ( ! apply_filters( 'wtg_run_queue_in_process', true ) ) {
			return false;
		}

		if ( self::can_detach_response() ) {
			return true;
		}

		return is_admin() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Can the response be flushed and the connection closed early?
	 *
	 * @return bool
	 */
	private static function can_detach_response() {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	/**
	 * Send everything buffered so far and let the browser go.
	 *
	 * @return void
	 */
	private static function close_response() {
		// Flush our own buffers first, or the detach below would discard whatever
		// is still sitting in them and the page would arrive truncated.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Take the run lock, if it is free or stale.
	 *
	 * add_option() is the atomic part: it performs an INSERT that fails when the
	 * row exists, so of two concurrent requests exactly one can win. Autoload is
	 * off — this option is written and deleted constantly and has no business
	 * being loaded on every page.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	private static function acquire_lock() {
		$now = time();

		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$held = (int) get_option( self::LOCK_OPTION, 0 );

		// Someone is genuinely mid-run.
		if ( $held && ( $now - $held ) < self::LOCK_TTL ) {
			return false;
		}

		// Stale: the holder died without releasing it. Take it over.
		update_option( self::LOCK_OPTION, $now, false );

		return true;
	}

	/**
	 * Release the run lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}
}
