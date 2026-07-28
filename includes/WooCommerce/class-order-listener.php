<?php
/**
 * Listens for WooCommerce order status changes and (re)queues orders.
 *
 * Does the bare minimum on the hook: put a pending row into the queue, then ask
 * WP-Cron to drain it immediately. All the slow/failable work (mapping, the
 * Sheets API call) still happens in the processor, so checkout is never blocked
 * or broken by Google being slow.
 *
 * @package WTG
 */

namespace WTG\WooCommerce;

use WTG\Plugin;
use WTG\Queue\Sync_Queue;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Listener
 */
class Order_Listener {

	/**
	 * Statuses that must NEVER reach the sheet.
	 *
	 * Every real status now syncs — pending, on-hold, processing, completed,
	 * cancelled, refunded, failed and any custom status a plugin adds. Only
	 * WordPress/WooCommerce's internal placeholders are excluded:
	 *
	 *  - auto-draft / checkout-draft: an order shell that WooCommerce creates
	 *    BEFORE the customer has finished. It usually has no line items yet, so
	 *    syncing it would write a blank product row that we would then have to
	 *    reconcile away. The real status arrives moments later and syncs properly.
	 *  - trash: a deleted order.
	 *
	 * @var string[]
	 */
	const EXCLUDED_STATUSES = array( 'auto-draft', 'checkout-draft', 'trash' );

	/**
	 * Order IDs already handled in THIS request, to avoid doing the work twice.
	 *
	 * WooCommerce can fire both `woocommerce_new_order` and
	 * `woocommerce_order_status_changed` for the same order in one request, so
	 * without this guard we would queue and spawn cron twice.
	 *
	 * @var array<int,bool>
	 */
	private static $handled = array();

	/**
	 * Register the order hooks. Called from Plugin::run() (all contexts, because
	 * these fire on the front-end during checkout).
	 *
	 * @return void
	 */
	public function hooks() {
		// Two hooks, because neither alone sees everything:
		//
		// `woocommerce_order_status_changed` fires only when there is a PREVIOUS
		// status (WC_Order::status_transition skips it when 'from' is empty), so
		// it misses the moment an order is first created.
		//
		// `woocommerce_new_order` covers exactly that gap. Together they catch an
		// order's opening status AND every later transition, whatever it is — no
		// need to enumerate status names.
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 10, 2 );
		// accepted_args of 4 so we receive the "to" status directly.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Handler for a newly created order.
	 *
	 * @param int       $order_id The new order.
	 * @param \WC_Order $order    The order object, when WooCommerce passes one.
	 * @return void
	 */
	public function on_new_order( $order_id, $order = null ) {
		$status = ( $order instanceof \WC_Order ) ? $order->get_status() : '';

		$this->handle( (int) $order_id, $status );
	}

	/**
	 * Handler for the generic transition hook.
	 *
	 * @param int    $order_id The order whose status changed.
	 * @param string $from     Previous status slug (unused).
	 * @param string $to       New status slug.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from = '', $to = '' ) {
		// Trust the "to" argument WooCommerce hands us rather than re-reading the
		// order, whose cached copy can still hold the old status mid-transition.
		$this->handle( (int) $order_id, (string) $to );
	}

	/**
	 * Queue the order for syncing.
	 *
	 * @param int    $order_id The order to queue.
	 * @param string $status   The status it is now in.
	 * @return void
	 */
	private function handle( $order_id, $status ) {
		if ( $order_id <= 0 || isset( self::$handled[ $order_id ] ) ) {
			return;
		}

		// Bail BEFORE marking it handled: a draft becomes a real order moments
		// later in the same request, and that transition must still be caught.
		if ( in_array( $status, self::EXCLUDED_STATUSES, true ) ) {
			return;
		}

		self::$handled[ $order_id ] = true;

		if ( Sync_Queue::exists( $order_id ) ) {
			// Already known: flip the existing row back to pending so the
			// processor re-sends it. The processor overwrites the SAME sheet rows,
			// so this updates the status in place instead of adding duplicates.
			Sync_Queue::requeue( $order_id );
		} else {
			// First time we have seen this order. Every non-draft status earns a
			// row now, so there is nothing further to check.
			Sync_Queue::enqueue( $order_id );
		}

		$this->schedule_immediate_run();
	}

	/**
	 * Ask WP-Cron to drain the queue as soon as possible.
	 *
	 * WP-Cron is not a real clock: events only run when a request comes in. On a
	 * quiet site (and especially a local dev site) the recurring 5-minute event
	 * may not fire for a long time, which is why the queue previously appeared to
	 * need the manual "Process Queue Now" button.
	 *
	 * So we schedule a one-off event due NOW and call spawn_cron(), which fires a
	 * non-blocking loopback request to the site. The sheet updates a second or
	 * two after the order, and the customer's request is never held up waiting on
	 * Google.
	 *
	 * @return void
	 */
	private function schedule_immediate_run() {
		// Already queued up by an earlier order in this same request? Then the
		// pending event will process this row too — one run drains the batch.
		if ( ! wp_next_scheduled( Plugin::CRON_HOOK_NOW ) ) {
			wp_schedule_single_event( time(), Plugin::CRON_HOOK_NOW );
		}

		// spawn_cron() self-limits to once every WP_CRON_LOCK_TIMEOUT (60s) and
		// does nothing while cron is already running. If it declines, the event
		// simply waits for the next request or the 5-minute recurring run — the
		// row is never lost, only delayed.
		spawn_cron();
	}
}
