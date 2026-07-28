<?php
/**
 * Data-access layer for the wtg_sync_log queue table.
 *
 * The single place any code touches the queue table. The listener enqueues
 * through it (Phase 4); the processor will claim and mark rows through it
 * (Phase 5); the admin Sync Log will read through it (Phase 6).
 *
 * @package WTG
 */

namespace WTG\Queue;

use WTG\Plugin;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sync_Queue
 */
class Sync_Queue {

	/**
	 * Queued, waiting to be sent.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Claimed by the processor for this run (prevents double-processing).
	 */
	const STATUS_PROCESSING = 'processing';

	/**
	 * Successfully written to the sheet.
	 */
	const STATUS_SUCCESS = 'success';

	/**
	 * Failed after exhausting retries.
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * Maximum send attempts before a row is marked terminally failed.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Plugin::table_name();
	}

	/**
	 * Is there already a queue row for this order?
	 *
	 * Dedupe guard: one order must only ever produce one queue row (and thus one
	 * sheet row), even though WooCommerce may fire both the processing and the
	 * completed hooks for it.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool
	 */
	public static function exists( $order_id ) {
		global $wpdb;

		$table = self::table();

		// $wpdb->prepare guards against injection; table name is our own constant
		// so it is safe to interpolate directly (prepare cannot bind identifiers).
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE order_id = %d LIMIT 1",
				$order_id
			)
		);

		return null !== $found;
	}

	/**
	 * Insert a pending row for an order, unless one already exists.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return int Inserted row ID, or 0 if skipped (duplicate) or on failure.
	 */
	public static function enqueue( $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return 0;
		}

		// Dedupe: never queue the same order twice.
		if ( self::exists( $order_id ) ) {
			return 0;
		}

		// Store timestamps in GMT (true) so they are timezone-independent; the
		// admin UI can localize them for display later.
		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'order_id'   => $order_id,
				'status'     => self::STATUS_PENDING,
				'attempts'   => 0,
				'last_error' => null,
				'created_at' => $now,
				'updated_at' => $now,
			),
			// Column formats, in the same order as the data above.
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		// insert() returns false on error, or the number of affected rows (1).
		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Flip an existing order's row back to pending so it is sent again.
	 *
	 * This is what makes a status change UPDATE the sheet instead of adding a
	 * second line: we deliberately reuse the order's one queue row rather than
	 * inserting another. The processor looks the order up in the sheet by its ID
	 * and overwrites that row in place.
	 *
	 * Attempts and last_error are cleared, because this is a genuinely new send
	 * (a fresh status), not a retry of the previous failed one.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool Whether a row was updated.
	 */
	public static function requeue( $order_id ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_PENDING,
				'attempts'   => 0,
				'last_error' => '',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'order_id' => (int) $order_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		return (bool) $updated;
	}

	/**
	 * Delete every row in the given statuses. Backs the admin "Clear Log" button.
	 *
	 * Deliberately takes a status list rather than emptying the table, because
	 * rows that are pending or processing are UNFINISHED WORK — deleting those
	 * would silently drop orders that never reached the sheet.
	 *
	 * Clearing finished rows is safe. The dedupe that stops an order being added
	 * to the sheet twice does NOT depend on this table: the processor looks the
	 * order up in the sheet itself by its ID, so even if a cleared order is later
	 * re-queued, it updates its existing rows rather than duplicating them.
	 *
	 * @param array $statuses STATUS_* values to remove.
	 * @return int Number of rows deleted.
	 */
	public static function clear( array $statuses ) {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return 0;
		}

		$table = self::table();

		// Build one placeholder per status so the whole list stays parameterised.
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL
				$statuses
			)
		);

		return (int) $deleted;
	}

	/**
	 * Count rows in a given status (used by the admin Sync Log later).
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				$status
			)
		);
	}

	/**
	 * Fetch a batch of pending rows for the processor to send.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array Row objects with id, order_id, attempts.
	 */
	public static function get_pending_batch( $limit ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_id, attempts FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
				self::STATUS_PENDING,
				(int) $limit
			)
		);
	}

	/**
	 * Set a row's status (and its last_error message + updated_at timestamp).
	 *
	 * We store '' rather than NULL for last_error to keep $wpdb->update simple
	 * and predictable across MySQL configurations.
	 *
	 * @param int    $id     Row ID.
	 * @param string $status One of the STATUS_* constants.
	 * @param string $error  Error message ('' clears it, e.g. on success).
	 * @return void
	 */
	public static function mark( $id, $status, $error = '' ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'last_error' => (string) $error,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Increment a row's attempt counter.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public static function bump_attempts( $id ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = attempts + 1, updated_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				(int) $id
			)
		);
	}

	/**
	 * Reset a (usually failed) row to pending so it will be retried, clearing
	 * its attempt count and error. Backs the admin Retry link.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public static function reset_for_retry( $id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_PENDING,
				'attempts'   => 0,
				'last_error' => '',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fetch the most recently updated rows for the Sync Log display.
	 *
	 * @param int $limit Maximum rows.
	 * @return array Full row objects.
	 */
	public static function get_rows( $limit = 100 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d",
				(int) $limit
			)
		);
	}
}
