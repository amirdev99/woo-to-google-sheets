<?php
/**
 * Drains the sync queue: the WP-Cron callback that actually sends orders.
 *
 * For each pending row it gets a valid access token, maps the order, appends it
 * to the sheet, and records the outcome — retrying failures until MAX_ATTEMPTS,
 * then marking them failed. This is the ONLY place the Sheets API is called for
 * real order data, deliberately far away from checkout.
 *
 * @package WTG
 */

namespace WTG\Queue;

use WTG\Settings;
use WTG\Google\OAuth_Client;
use WTG\Google\Sheets_Client;
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

		foreach ( $rows as $row ) {
			$counts['processed']++;

			// Claim the row and count this attempt up front, so a fatal mid-loop
			// cannot leave it stuck "pending" with an uncounted try.
			Sync_Queue::mark( $row->id, Sync_Queue::STATUS_PROCESSING );
			Sync_Queue::bump_attempts( $row->id );
			$attempts = (int) $row->attempts + 1;

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
