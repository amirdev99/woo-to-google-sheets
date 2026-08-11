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
use WTG\Queue\Sync_Runner;

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
	 * Get the queue drained as soon as possible.
	 *
	 * This used to call spawn_cron() and hope. spawn_cron() fires a non-blocking
	 * loopback request to wp-cron.php — and that request is blocked outright in
	 * plenty of normal setups (self-signed certificates on local and staging
	 * sites, HTTP auth, hosts that firewall their own IP, DISABLE_WP_CRON). It is
	 * sent with 'blocking' => false, so a total failure looks exactly like
	 * success and nothing is ever logged. It also refuses to fire more than once
	 * per 60 seconds, and takes its lock BEFORE trying, so one silently failed
	 * loopback suppresses the next order's attempt as well.
	 *
	 * That is why orders appeared to need the manual "Process Queue Now" button.
	 *
	 * So the loopback is now the FALLBACK, not the plan. First choice is for this
	 * very request to drain the queue itself once the response has been sent —
	 * nothing in between to be blocked. Sync_Runner::run_soon() reports whether it
	 * can do that; only when it cannot do we fall back to poking cron.
	 *
	 * Either way the one-off cron event is still scheduled, as a third line of
	 * defence: if this request dies before shutdown, the row is not stranded.
	 *
	 * @return void
	 */
	private function schedule_immediate_run() {
		// Already queued up by an earlier order in this same request? Then the
		// pending event will process this row too — one run drains the batch.
		if ( ! wp_next_scheduled( Plugin::CRON_HOOK_NOW ) ) {
			wp_schedule_single_event( time(), Plugin::CRON_HOOK_NOW );
		}

		if ( Sync_Runner::run_soon() ) {
			return;
		}

		// No way to detach the response on this server and we are on the front end,
		// so draining here would leave the customer watching a spinner while we
		// talk to Google. Poke cron instead and let the admin catch-up and the
		// five-minute event cover us — the row is never lost, only delayed.
		spawn_cron();
	}
}
