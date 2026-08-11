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
	 * Row counts for every status, in ONE query.
	 *
	 * Returns all four keys even when a status has no rows, so callers can index
	 * the result without guarding. Previously this took a status and ran once per
	 * status, which meant four queries to draw one line of the Sync Log.
	 *
	 * @return array<string,int> status => count
	 */
	public static function count_by_status() {
		global $wpdb;

		$table = self::table();

		$counts = array(
			self::STATUS_PENDING    => 0,
			self::STATUS_PROCESSING => 0,
			self::STATUS_SUCCESS    => 0,
			self::STATUS_FAILED     => 0,
		);

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				// An unexpected status (hand-edited row) is ignored rather than
				// added, so the caller's four keys stay exactly four.
				if ( array_key_exists( $row->status, $counts ) ) {
					$counts[ $row->status ] = (int) $row->total;
				}
			}
		}

		return $counts;
	}

	/**
	 * Seconds of cool-off added per failed attempt.
	 *
	 * With the five-minute cron this spaces retries at roughly 5, 10, 15 and 20
	 * minutes. Without it, a row that fails keeps being retried on every run, so a
	 * sheet that is temporarily rejecting writes burns the whole attempt budget in
	 * about twenty-five minutes and parks the order as failed.
	 */
	const RETRY_BACKOFF = 300;

	/**
	 * Fetch a batch of pending rows for the processor to send.
	 *
	 * Oldest first, so a backlog drains in the order it arrived.
	 *
	 * Rows that have already failed wait longer each time. `attempts = 0` covers
	 * everything fresh — a new order, a requeue after a status change, and a manual
	 * Retry all reset attempts — so none of those are ever delayed.
	 *
	 * TIMESTAMPDIFF is used rather than DATE_SUB with an INTERVAL expression
	 * because it takes the column on the left and needs no interval-unit
	 * arithmetic, which keeps it portable across MySQL and MariaDB.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array Row objects with id, order_id, attempts.
	 */
	public static function get_pending_batch( $limit ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_id, attempts FROM {$table}
				 WHERE status = %s
				   AND ( attempts = 0 OR TIMESTAMPDIFF( SECOND, updated_at, %s ) >= attempts * %d )
				 ORDER BY created_at ASC, id ASC
				 LIMIT %d",
				self::STATUS_PENDING,
				current_time( 'mysql', true ),
				self::RETRY_BACKOFF,
				(int) $limit
			)
		);
	}

	/**
	 * Is anything waiting to be sent?
	 *
	 * Kept separate from count_by_status(), which GROUPs over the whole table.
	 * This runs on ordinary admin page loads (see Sync_Runner::catch_up), so it
	 * has to be as close to free as possible: LIMIT 1 against the `status` index,
	 * stopping at the first hit.
	 *
	 * @return bool
	 */
	public static function has_pending() {
		global $wpdb;

		$table = self::table();

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s LIMIT 1",
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * How long has the oldest pending row been waiting?
	 *
	 * The signal behind the "sync looks stalled" warning in the admin. A backlog
	 * is normal for a few seconds; a row that has been pending for many minutes
	 * means every layer of Sync_Runner failed, which the shop owner needs told
	 * rather than left to discover from a stale spreadsheet.
	 *
	 * Rows that have already failed an attempt are excluded — those are waiting
	 * on RETRY_BACKOFF by design, not because the automation is broken.
	 *
	 * @return int Seconds, or 0 when nothing is waiting.
	 */
	public static function oldest_pending_age() {
		global $wpdb;

		$table = self::table();

		$seconds = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT TIMESTAMPDIFF( SECOND, MIN( created_at ), %s )
				 FROM {$table}
				 WHERE status = %s AND attempts = 0",
				current_time( 'mysql', true ),
				self::STATUS_PENDING
			)
		);

		return max( 0, (int) $seconds );
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
	 * Increment a row's attempt counter and return the NEW value.
	 *
	 * The count is read back from the database rather than computed in PHP. The
	 * caller's row object was loaded before the increment, so `$row->attempts + 1`
	 * would be stale if two runs ever overlapped — and both would then believe they
	 * were on the same attempt, giving the row more retries than MAX_ATTEMPTS allows.
	 *
	 * @param int $id Row ID.
	 * @return int The attempt count after incrementing.
	 */
	public static function bump_attempts( $id ) {
		global $wpdb;

		$table = self::table();
		$id    = (int) $id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = attempts + 1, updated_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$id
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $id )
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
