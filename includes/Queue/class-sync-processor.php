<?php
/**
 * Drains the sync queue: the WP-Cron callback that actually sends orders.
 *
 * For each pending row it gets a valid access token, maps the order, appends it
 * to the sheet, and records the outcome — retrying failures until MAX_ATTEMPTS,
 * then marking them failed. This is the ONLY place the Sheets API is called for
 * real order data, deliberately far away from checkout.
 *
 * When per-status tabs are switched on there is a SECOND phase per order: the
 * order is also routed onto the one tab matching its current status, and removed
 * from any other status tab it was previously on. The master tab is unaffected by
 * that phase — status tabs are in addition to it, never instead of it.
 *
 * @package WTG
 */

namespace WTG\Queue;

use WTG\Settings;
use WTG\Google\OAuth_Client;
use WTG\Google\Sheets_Client;
use WTG\Sheets\Status_Tabs;
use WTG\WooCommerce\Order_Mapper;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sync_Processor
 */
class Sync_Processor {

	/**
	 * How many rows to attempt per run, so a big backlog can't exhaust the
	 * request time limit in one go.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Process a batch of pending rows.
	 *
	 * Safe to call from cron (no args) or the manual "Process Queue Now" button.
	 *
	 * @return array Counts: processed, success, failed, retry, skipped.
	 */
	public function process() {
		$counts = array(
			'processed' => 0,
			'success'   => 0,
			'failed'    => 0,
			'retry'     => 0,
			'skipped'   => 0,
		);

		// A single valid token is reused for the whole batch. If we cannot get
		// one (not connected, expired refresh token), leave rows pending and
		// bail — they will be retried next run once the admin reconnects.
		$oauth = new OAuth_Client();
		$token = $oauth->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $counts;
		}

		$spreadsheet_id = Settings::get( 'spreadsheet_id', '' );
		$sheet_name     = Settings::get( 'sheet_name', 'Sheet1' );
		if ( '' === $spreadsheet_id ) {
			return $counts;
		}

		$rows = Sync_Queue::get_pending_batch( self::BATCH_SIZE );
		if ( empty( $rows ) ) {
			return $counts;
		}

		$sheets = new Sheets_Client();
		$mapper = new Order_Mapper();

		// Built ONCE for the whole batch, outside the loop, because the instance
		// caches the spreadsheet's tab map — twenty orders then cost one map lookup
		// instead of twenty. Null when the feature is off, which is also the flag
		// the loop below tests, so a disabled install does no extra work at all.
		$tabs = Status_Tabs::is_enabled() ? new Status_Tabs( $sheets, $spreadsheet_id, $token ) : null;

		foreach ( $rows as $row ) {
			$counts['processed']++;

			// Claim the row and count this attempt up front, so a fatal mid-loop
			// cannot leave it stuck "pending" with an uncounted try.
			Sync_Queue::mark( $row->id, Sync_Queue::STATUS_PROCESSING );
			// Use the count the database reports back, not $row->attempts + 1 —
			// $row was read before the increment, so a concurrent run would make
			// both callers think they were on the same attempt.
			$attempts = Sync_Queue::bump_attempts( $row->id );

			// The order may have been deleted since it was queued.
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $row->order_id ) : false;
			if ( ! $order ) {
				Sync_Queue::mark( $row->id, Sync_Queue::STATUS_FAILED, __( 'Order not found.', 'woo-to-gsheet' ) );
				$counts['failed']++;
				continue;
			}

			// One order becomes one row PER PRODUCT.
			$new_rows = $mapper->map( $order );

			// Upsert: which rows (if any) does this order already occupy? If it is
			// there we overwrite those rows so the Status cell follows WooCommerce;
			// if not we append. This is what stops a processing -> completed change
			// from producing a second set of lines for the same order.
			$existing = $sheets->find_rows_by_order_id( $spreadsheet_id, $sheet_name, $row->order_id, $token );

			if ( is_wp_error( $existing ) ) {
				// The lookup itself failed (network, permissions). Treat it like any
				// other send failure so the normal retry path applies — never append
				// blindly here, or a transient error would duplicate the order.
				$result = $existing;
			} else {
				$result = $this->write( $sheets, $spreadsheet_id, $sheet_name, $existing, $new_rows, $token );
			}

			// Second phase: put the order on the status tab it belongs to now, and
			// take it off any other. Only after the master write succeeded — if the
			// master is broken (bad token, deleted spreadsheet) there is no point
			// spending more requests discovering the same thing again.
			if ( ! is_wp_error( $result ) && null !== $tabs ) {
				$result = $this->route( $tabs, $sheets, $spreadsheet_id, $order, $new_rows, $token );
			}

			if ( is_wp_error( $result ) ) {
				if ( $attempts >= Sync_Queue::MAX_ATTEMPTS ) {
					// Out of retries — mark terminally failed (manual Retry resets it).
					Sync_Queue::mark( $row->id, Sync_Queue::STATUS_FAILED, $result->get_error_message() );
					$counts['failed']++;
				} else {
					// Back to pending; the next run will try again.
					Sync_Queue::mark( $row->id, Sync_Queue::STATUS_PENDING, $result->get_error_message() );
					$counts['retry']++;
				}
				continue;
			}

			Sync_Queue::mark( $row->id, Sync_Queue::STATUS_SUCCESS );
			$counts['success']++;
		}

		return $counts;
	}

	/**
	 * Put an order on the status tab matching its status, and off every other one.
	 *
	 * Three steps, in this order for a reason:
	 *
	 *  1. ASK where the order currently is — one batchGet across every status tab
	 *     that exists. Nothing is stored about an order's location, exactly as with
	 *     the master tab: a sheet is a document humans re-sort and edit, so a
	 *     remembered tab name would quietly go stale. A lookup always tells the truth.
	 *  2. WRITE it to the tab it belongs on now (creating that tab, with a header,
	 *     if this is the first order to reach the status).
	 *  3. DELETE it from the others.
	 *
	 * Write BEFORE delete, never the other way round. If the run dies between the
	 * two the order appears on two tabs at once — visible, harmless, and healed by
	 * the next sync. Deleting first and then dying would lose the row outright.
	 *
	 * An order whose status has no tab (feature on, but the status is untracked or
	 * its name clashes with the master tab) skips step 2 and still runs step 3:
	 * "belongs on no status tab" is a real destination, and the order must not be
	 * left behind on the tab it used to be on. It stays on the master tab either
	 * way — nothing here touches that.
	 *
	 * @param Status_Tabs               $tabs           Tab resolver for this batch.
	 * @param \WTG\Google\Sheets_Client $sheets         Client.
	 * @param string                    $spreadsheet_id Target spreadsheet.
	 * @param \WC_Order                 $order          The order being synced.
	 * @param array                     $new_rows       Rows the order needs now.
	 * @param string                    $token          Access token.
	 * @return true|\WP_Error
	 */
	private function route( Status_Tabs $tabs, $sheets, $spreadsheet_id, $order, array $new_rows, $token ) {
		// Only tabs that already exist can be searched: a batchGet naming a range on
		// a missing tab fails the ENTIRE request, taking the other tabs down with it.
		$searchable = $tabs->existing_tab_names();
		if ( is_wp_error( $searchable ) ) {
			return $searchable;
		}

		// get_status() returns the slug WITHOUT the "wc-" prefix, which is the form
		// Status_Tabs works in throughout. '' here means "no tab for this status".
		$target   = Status_Tabs::tab_name_for( $order->get_status() );
		$order_id = $order->get_id();

		$found = $sheets->find_rows_in_tabs( $spreadsheet_id, $searchable, $order_id, $token );
		if ( is_wp_error( $found ) ) {
			// Same rule as the master lookup: a failed search must never fall through
			// to a blind append, or a transient error would duplicate the order.
			return $found;
		}

		if ( '' !== $target ) {
			// Resolve before writing, because this is what CREATES the tab (and its
			// header) the first time a status is used. The write below addresses the
			// tab by name, but without this call the name might not exist yet.
			$sheet_id = $tabs->sheet_id_for( $target );
			if ( is_wp_error( $sheet_id ) ) {
				return $sheet_id;
			}

			// A tab we just created cannot be in $found — it was not searchable — so
			// this correctly falls through to an append for a first-time status.
			$existing = isset( $found[ $target ] ) ? $found[ $target ] : array();

			// Reuse the master reconciler verbatim, so a status tab gets identical
			// upsert behaviour: overwrite in place, append surplus products, refuse
			// to guess when products were removed.
			$result = $this->write( $sheets, $spreadsheet_id, $target, $existing, $new_rows, $token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		foreach ( $found as $name => $rows ) {
			// The target tab was just reconciled in place — it is where the order is
			// supposed to be, so it is the one tab we must NOT delete from.
			if ( $name === $target ) {
				continue;
			}

			// Deleting addresses a tab by numeric ID, not by name. These tabs came out
			// of the search so they exist; this is a cached map read, not a request.
			$sheet_id = $tabs->sheet_id_for( $name );
			if ( is_wp_error( $sheet_id ) ) {
				return $sheet_id;
			}

			$result = $sheets->delete_rows( $spreadsheet_id, $sheet_id, $rows, $token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Reconcile the rows an order already has in the sheet with the rows it needs.
	 *
	 * Three cases, because an order's product list can change after it was synced:
	 *
	 *  - Not in the sheet yet      -> append every product row.
	 *  - Same number of rows       -> overwrite them in place (the normal path for
	 *                                 a status change).
	 *  - More products than rows   -> overwrite what is there, append the rest.
	 *
	 * The fourth case — FEWER products than existing rows, i.e. a product was
	 * removed from the order — is deliberately NOT handled by deleting rows. A
	 * delete is destructive and irreversible, and if the row lookup were ever
	 * wrong it would take real data with it. Instead we report a clear error so
	 * the sheet can be corrected by hand.
	 *
	 * Note route() DOES delete, but only rows an order-ID lookup just returned, and
	 * only to move an order between status tabs — never on the master tab, and never
	 * to reconcile a product count. The two are different decisions.
	 *
	 * @param \WTG\Google\Sheets_Client $sheets         Client.
	 * @param string                    $spreadsheet_id Target spreadsheet.
	 * @param string                    $sheet_name     Target tab.
	 * @param array                     $existing       1-based rows the order holds.
	 * @param array                     $new_rows       Rows the order needs now.
	 * @param string                    $token          Access token.
	 * @return true|\WP_Error
	 */
	private function write( $sheets, $spreadsheet_id, $sheet_name, array $existing, array $new_rows, $token ) {
		$have = count( $existing );
		$need = count( $new_rows );

		// Brand new order: append all of its product rows in one call.
		if ( 0 === $have ) {
			return $sheets->append_rows( $spreadsheet_id, $sheet_name, $new_rows, $token );
		}

		// A product was removed since the last sync. Refuse rather than guess.
		if ( $have > $need ) {
			return new \WP_Error(
				'wtg_row_count_shrunk',
				sprintf(
					/* translators: 1: existing row count, 2: required row count. */
					__( 'This order has %1$d rows in the sheet but only %2$d products now. Delete its rows from the sheet and use Retry to re-add them.', 'woo-to-gsheet' ),
					$have,
					$need
				)
			);
		}

		// Overwrite the rows the order already occupies.
		$result = $sheets->update_rows(
			$spreadsheet_id,
			$sheet_name,
			$existing,
			array_slice( $new_rows, 0, $have ),
			$token
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Products were ADDED since the last sync — append the surplus rows.
		if ( $need > $have ) {
			return $sheets->append_rows( $spreadsheet_id, $sheet_name, array_slice( $new_rows, $have ), $token );
		}

		return true;
	}
}
