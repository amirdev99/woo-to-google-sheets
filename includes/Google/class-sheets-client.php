<?php
/**
 * Thin wrapper around the Google Sheets values endpoints.
 *
 * Three jobs, all using a caller-provided access token: append a row, find the
 * row holding a given order, and overwrite a row in place. Together they let the
 * processor "upsert" — a new order is appended, a status change rewrites the row
 * that order already occupies. Token acquisition/refresh is the OAuth_Client's
 * job, kept separate.
 *
 * @package WTG
 */

namespace WTG\Google;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sheets_Client
 */
class Sheets_Client {

	/**
	 * Sheets API v4 base URL.
	 */
	const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets/';

	/**
	 * The column holding the Order ID, used to locate an order's existing row.
	 * Must match Order_Mapper::map(), where the order ID is the first value.
	 */
	const ORDER_ID_COLUMN = 'A';

	/**
	 * Append one or more rows to the given sheet.
	 *
	 * Takes a LIST of rows because one order can produce several lines (one per
	 * product). Sending them in a single call keeps an order's rows adjacent in
	 * the sheet and costs one request instead of one per product.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param string $sheet_name     Sheet/tab name (e.g. "Sheet1"); Google appends
	 *                               after the last populated row in that sheet.
	 * @param array  $rows           List of rows, each a flat array of cell values.
	 * @param string $access_token   Valid OAuth access token.
	 * @return true|\WP_Error
	 */
	public function append_rows( $spreadsheet_id, $sheet_name, array $rows, $access_token ) {
		if ( empty( $rows ) ) {
			return true; // Nothing to do is not an error.
		}

		// Quote the tab name so a sheet called "Order Log" does not break A1 parsing.
		$url = self::API_BASE . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $this->a1_sheet( $sheet_name ) ) . ':append';

		// USER_ENTERED = parse values like the Sheets UI would (numbers/dates),
		// INSERT_ROWS = push existing data down rather than overwrite.
		$url = add_query_arg(
			array(
				'valueInputOption' => 'USER_ENTERED',
				'insertDataOption' => 'INSERT_ROWS',
			),
			$url
		);

		$body = $this->encode( array( 'values' => $this->normalize_rows( $rows ) ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
			)
		);

		$error = $this->response_error( $response, 'wtg_append_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return true;
	}

	/**
	 * Find the sheet row number that already holds a given order.
	 *
	 * Reads just column A and scans it for an exact match on the order ID. We
	 * look the row up every time instead of remembering a row number, because the
	 * sheet is a document a human edits: rows get sorted, inserted and deleted,
	 * and any number we stored would quietly go stale. A lookup always tells the
	 * truth, and it also works for orders synced before this feature existed.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param string $sheet_name     Sheet/tab name (e.g. "Sheet1").
	 * @param int    $order_id       WooCommerce order ID to find.
	 * @param string $access_token   Valid OAuth access token.
	 * @return array|\WP_Error Ascending list of 1-based row numbers; empty if absent.
	 */
	public function find_rows_by_order_id( $spreadsheet_id, $sheet_name, $order_id, $access_token ) {
		$range = $this->a1_sheet( $sheet_name ) . '!' . self::ORDER_ID_COLUMN . ':' . self::ORDER_ID_COLUMN;

		$url = self::API_BASE . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $range );

		// majorDimension=COLUMNS returns the whole column as ONE inner array,
		// which is far easier to scan than a list of single-cell rows.
		$url = add_query_arg( array( 'majorDimension' => 'COLUMNS' ), $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		$error = $this->response_error( $response, 'wtg_lookup_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// An empty sheet omits "values" entirely — that is not an error, it just
		// means nothing has been synced yet.
		if ( ! isset( $data['values'][0] ) || ! is_array( $data['values'][0] ) ) {
			return array();
		}

		// Collect EVERY match, not just the first: an order with three products
		// occupies three rows, and a status change has to update all of them.
		// Compare as trimmed strings, since the API may hand back "14670" or 14670
		// depending on how the cell was entered.
		$needle = (string) $order_id;
		$found  = array();

		foreach ( $data['values'][0] as $index => $cell ) {
			if ( trim( (string) $cell ) === $needle ) {
				$found[] = $index + 1; // Sheet rows are 1-based; array keys are 0-based.
			}
		}

		return $found;
	}

	/**
	 * List the tab names inside a spreadsheet.
	 *
	 * A thin wrapper over get_sheet_map() — same single request, just the titles.
	 * Kept as its own method because the settings page only ever wants names, and
	 * reading `array_keys( $map )` at every call site would leak the detail that a
	 * map exists at all.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param string $access_token   Valid OAuth access token.
	 * @return array|\WP_Error List of tab title strings, in sheet order.
	 */
	public function list_sheet_titles( $spreadsheet_id, $access_token ) {
		$map = $this->get_sheet_map( $spreadsheet_id, $access_token );

		if ( is_wp_error( $map ) ) {
			return $map;
		}

		return array_keys( $map );
	}

	/**
	 * Map every tab in a spreadsheet to its numeric sheet ID.
	 *
	 * Uses spreadsheets.get with a `fields` mask so Google returns only the tab
	 * properties rather than the entire document, which for a large sheet would be
	 * a very expensive response.
	 *
	 * The numeric ID matters because the A1 notation used everywhere else in this
	 * class can NAME a tab but cannot address it structurally. Adding or deleting
	 * rows goes through spreadsheets:batchUpdate, which identifies a tab only by
	 * its `sheetId` — so anything that reshapes a sheet has to start here.
	 *
	 * Needs no extra permission: the existing auth/spreadsheets scope already
	 * covers reading a spreadsheet whose ID we hold. Only the *file listing* on
	 * the settings page needs Drive.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param string $access_token   Valid OAuth access token.
	 * @return array|\WP_Error Title => sheetId (int), in sheet order.
	 */
	public function get_sheet_map( $spreadsheet_id, $access_token ) {
		$url = self::API_BASE . rawurlencode( $spreadsheet_id ) . '?fields=sheets.properties(title,sheetId)';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		$error = $this->response_error( $response, 'wtg_sheet_list_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$map  = array();

		if ( isset( $data['sheets'] ) && is_array( $data['sheets'] ) ) {
			foreach ( $data['sheets'] as $sheet ) {
				$props = isset( $sheet['properties'] ) ? $sheet['properties'] : array();

				// sheetId 0 is legitimate (it is the first tab of every new
				// spreadsheet), so test for PRESENCE, never for truthiness.
				if ( isset( $props['title'], $props['sheetId'] ) && '' !== $props['title'] ) {
					$map[ (string) $props['title'] ] = (int) $props['sheetId'];
				}
			}
		}

		return $map;
	}

	/**
	 * Find an order's rows across SEVERAL tabs in a single request.
	 *
	 * The per-status-tab feature has to answer "where does this order currently
	 * live?", and the honest answer requires looking in every candidate tab. Doing
	 * that with find_rows_by_order_id() would cost one HTTP request per tab, per
	 * order. values:batchGet takes many ranges at once, so the whole question costs
	 * exactly one request no matter how many tabs exist.
	 *
	 * Results are matched back to tabs BY POSITION, not by parsing the `range`
	 * string Google echoes back: it re-quotes and normalises names ("Sheet1!A1:A9"
	 * vs "'On hold'!A1:A9"), so string-matching it is fragile. The API documents
	 * valueRanges as parallel to the ranges requested, which is stable.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param array  $sheet_names    Tab names to search.
	 * @param int    $order_id       WooCommerce order ID to find.
	 * @param string $access_token   Valid OAuth access token.
	 * @return array|\WP_Error Tab name => ascending list of 1-based row numbers.
	 *                         Tabs where the order is absent are omitted entirely.
	 */
	public function find_rows_in_tabs( $spreadsheet_id, array $sheet_names, $order_id, $access_token ) {
		$sheet_names = array_values( array_unique( array_filter( $sheet_names, 'strlen' ) ) );

		if ( empty( $sheet_names ) ) {
			return array(); // Nothing to search is not an error.
		}

		$url = self::API_BASE . rawurlencode( $spreadsheet_id ) . '/values:batchGet';

		// add_query_arg cannot express a repeated key, so the ranges are appended
		// by hand — batchGet wants ?ranges=A&ranges=B, not ranges[]=A.
		$query = array( 'majorDimension=COLUMNS' );
		foreach ( $sheet_names as $name ) {
			$range   = $this->a1_sheet( $name ) . '!' . self::ORDER_ID_COLUMN . ':' . self::ORDER_ID_COLUMN;
			$query[] = 'ranges=' . rawurlencode( $range );
		}

		$response = wp_remote_get(
			$url . '?' . implode( '&', $query ),
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		$error = $this->response_error( $response, 'wtg_lookup_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$ranges = isset( $data['valueRanges'] ) && is_array( $data['valueRanges'] ) ? $data['valueRanges'] : array();

		$needle = (string) $order_id;
		$found  = array();

		foreach ( $sheet_names as $i => $name ) {
			// A tab with an empty column A omits "values" entirely — not an error.
			if ( ! isset( $ranges[ $i ]['values'][0] ) || ! is_array( $ranges[ $i ]['values'][0] ) ) {
				continue;
			}

			$rows = array();
			foreach ( $ranges[ $i ]['values'][0] as $index => $cell ) {
				// Trimmed string compare: the API hands back "14670" or 14670
				// depending on how the cell was entered. Same rule as
				// find_rows_by_order_id(), deliberately.
				if ( trim( (string) $cell ) === $needle ) {
					$rows[] = $index + 1; // Sheet rows are 1-based; array keys are 0-based.
				}
			}

			if ( ! empty( $rows ) ) {
				$found[ $name ] = $rows;
			}
		}

		return $found;
	}

	/**
	 * Create a new tab in the spreadsheet.
	 *
	 * Returns the new tab's numeric ID so the caller can immediately act on it
	 * without a second get_sheet_map() round trip.
	 *
	 * A duplicate title is reported as its own error code so the caller can treat
	 * it as "fine, it already exists" rather than a real failure — that race is
	 * genuinely possible when two cron runs overlap on the first order to reach a
	 * given status.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param string $title          Tab name to create.
	 * @param string $access_token   Valid OAuth access token.
	 * @return int|\WP_Error New sheetId.
	 */
	public function create_sheet( $spreadsheet_id, $title, $access_token ) {
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return new \WP_Error(
				'wtg_create_sheet_failed',
				__( 'Cannot create a sheet tab with an empty name.', 'woo-to-gsheet' )
			);
		}

		$result = $this->batch_update(
			$spreadsheet_id,
			array(
				array(
					'addSheet' => array(
						'properties' => array( 'title' => $title ),
					),
				),
			),
			$access_token,
			'wtg_create_sheet_failed'
		);

		if ( is_wp_error( $result ) ) {
			// Google's message for a taken name is "A sheet with the name ... already
			// exists". Re-code it so the caller can recover instead of retrying.
			if ( false !== stripos( $result->get_error_message(), 'already exists' ) ) {
				return new \WP_Error( 'wtg_sheet_exists', $result->get_error_message() );
			}

			return $result;
		}

		if ( ! isset( $result['replies'][0]['addSheet']['properties']['sheetId'] ) ) {
			return new \WP_Error(
				'wtg_create_sheet_failed',
				/* translators: %s: tab name. */
				sprintf( __( 'Google did not report an ID for the new "%s" tab.', 'woo-to-gsheet' ), $title )
			);
		}

		return (int) $result['replies'][0]['addSheet']['properties']['sheetId'];
	}

	/**
	 * Delete specific rows from a tab.
	 *
	 * This is the one destructive call in the plugin, so it is deliberately blunt:
	 * it takes explicit row numbers and does nothing clever. Callers are expected
	 * to pass ONLY row numbers returned by an order-ID lookup, which can never
	 * include a header row (the text "Order ID" does not equal an order number).
	 *
	 * Rows are deleted HIGHEST FIRST. Deleting row 3 shifts row 7 up to row 6, so
	 * working downwards would make every subsequent number wrong — descending order
	 * means the rows still waiting to be deleted have not moved yet.
	 *
	 * Ranges are half-open and 0-based here (deleteDimension), unlike the 1-based
	 * inclusive rows used everywhere else in this class, so row N becomes
	 * startIndex N-1, endIndex N.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param int    $sheet_id       Numeric tab ID from get_sheet_map().
	 * @param array  $row_numbers    1-based rows to delete.
	 * @param string $access_token   Valid OAuth access token.
	 * @return true|\WP_Error
	 */
	public function delete_rows( $spreadsheet_id, $sheet_id, array $row_numbers, $access_token ) {
		// Ignore anything that is not a real row, so a stray 0 can never be turned
		// into startIndex -1 and rejected (or worse, misread) by the API.
		$rows = array();
		foreach ( $row_numbers as $number ) {
			$number = (int) $number;
			if ( $number >= 1 ) {
				$rows[ $number ] = $number; // Keyed, so duplicates collapse.
			}
		}

		if ( empty( $rows ) ) {
			return true; // Nothing to do is not an error.
		}

		rsort( $rows ); // Highest row first — see the docblock.

		$requests = array();
		foreach ( $rows as $number ) {
			$requests[] = array(
				'deleteDimension' => array(
					'range' => array(
						'sheetId'    => (int) $sheet_id,
						'dimension'  => 'ROWS',
						'startIndex' => $number - 1,
						'endIndex'   => $number,
					),
				),
			);
		}

		$result = $this->batch_update( $spreadsheet_id, $requests, $access_token, 'wtg_delete_rows_failed' );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Read one whole row back from the sheet.
	 *
	 * Used before writing the header row, so we can tell an empty row from an
	 * existing header from real order data, and refuse to overwrite the last one.
	 *
	 * The range is "1:1" rather than "A1:L1" on purpose: it reads the entire row
	 * without this class needing to know how many columns the mapper produces,
	 * which keeps Sheets_Client independent of Order_Mapper.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param string $sheet_name     Sheet/tab name.
	 * @param int    $row_number     1-based row to read.
	 * @param string $access_token   Valid OAuth access token.
	 * @return array|\WP_Error Cell values left to right; empty array if the row is blank.
	 */
	public function read_row( $spreadsheet_id, $sheet_name, $row_number, $access_token ) {
		$row_number = max( 1, (int) $row_number );
		$range      = $this->a1_sheet( $sheet_name ) . '!' . $row_number . ':' . $row_number;

		$url = self::API_BASE . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $range );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		$error = $this->response_error( $response, 'wtg_read_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Google omits "values" entirely for a blank range — an empty row, not an
		// error. Same case find_rows_by_order_id() handles.
		if ( ! isset( $data['values'][0] ) || ! is_array( $data['values'][0] ) ) {
			return array();
		}

		return $data['values'][0];
	}

	/**
	 * Overwrite a set of existing rows with fresh values.
	 *
	 * Writes WHOLE rows rather than just the status cell: it costs the same
	 * request, and it means a corrected phone number or an adjusted total is
	 * refreshed too, instead of the sheet slowly drifting from WooCommerce.
	 *
	 * Uses values:batchUpdate rather than a series of PUTs, so an order whose rows
	 * are NOT adjacent (because someone sorted the sheet) still updates correctly,
	 * and the whole order costs exactly one request.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param string $sheet_name     Sheet/tab name.
	 * @param array  $row_numbers    1-based rows to overwrite, in order.
	 * @param array  $rows           Replacement rows, parallel to $row_numbers.
	 * @param string $access_token   Valid OAuth access token.
	 * @return true|\WP_Error
	 */
	public function update_rows( $spreadsheet_id, $sheet_name, array $row_numbers, array $rows, $access_token ) {
		$row_numbers = array_values( $row_numbers );
		$rows        = $this->normalize_rows( $rows );

		if ( empty( $rows ) ) {
			return true; // Nothing to do is not an error.
		}

		if ( count( $row_numbers ) !== count( $rows ) ) {
			return new \WP_Error(
				'wtg_update_failed',
				__( 'Internal error: row count does not match the values to write.', 'woo-to-gsheet' )
			);
		}

		// One entry per row, each naming its own bounded range — see row_range().
		$data = array();
		foreach ( $rows as $i => $values ) {
			$data[] = array(
				'range'  => $this->row_range( $sheet_name, (int) $row_numbers[ $i ], count( $values ) ),
				'values' => array( $values ),
			);
		}

		$body = $this->encode(
			array(
				'valueInputOption' => 'USER_ENTERED',
				'data'             => $data,
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$url = self::API_BASE . rawurlencode( $spreadsheet_id ) . '/values:batchUpdate';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
			)
		);

		$error = $this->response_error( $response, 'wtg_update_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return true;
	}

	/**
	 * Read the SAME row number from several tabs in one request.
	 *
	 * The multi-tab twin of read_row(), used before writing the header row: with
	 * per-status tabs switched on, row 1 has to be inspected in every tab the
	 * plugin writes to, and asking one tab at a time would cost a request each.
	 *
	 * Google returns valueRanges in the order the ranges were requested — the
	 * same guarantee find_rows_in_tabs() already relies on — so the answers are
	 * matched back to their tab by position and returned keyed by tab NAME, which
	 * is what every caller actually wants to work with.
	 *
	 * @param string   $spreadsheet_id Target spreadsheet.
	 * @param string[] $sheet_names    Tabs to read. Must all exist: a range on a
	 *                                 missing tab fails the whole batch.
	 * @param int      $row_number     1-based row to read from each tab.
	 * @param string   $access_token   Valid OAuth access token.
	 * @return array|\WP_Error Tab name => cell values (empty array for a blank row).
	 */
	public function read_row_in_tabs( $spreadsheet_id, array $sheet_names, $row_number, $access_token ) {
		$sheet_names = array_values( $sheet_names );

		if ( empty( $sheet_names ) ) {
			return array(); // Nothing to read is not an error.
		}

		$row_number = max( 1, (int) $row_number );

		// add_query_arg cannot express a repeated key, so the ranges are appended
		// by hand — batchGet wants ?ranges=A&ranges=B, not ranges[]=A.
		$query = array();
		foreach ( $sheet_names as $name ) {
			$range   = $this->a1_sheet( $name ) . '!' . $row_number . ':' . $row_number;
			$query[] = 'ranges=' . rawurlencode( $range );
		}

		$url = self::API_BASE . rawurlencode( $spreadsheet_id ) . '/values:batchGet?' . implode( '&', $query );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		$error = $this->response_error( $response, 'wtg_read_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$ranges = isset( $data['valueRanges'] ) && is_array( $data['valueRanges'] ) ? $data['valueRanges'] : array();

		$out = array();
		foreach ( $sheet_names as $i => $name ) {
			// Google omits "values" entirely for a blank range — an empty row, not
			// an error. Same case read_row() handles.
			$out[ $name ] = ( isset( $ranges[ $i ]['values'][0] ) && is_array( $ranges[ $i ]['values'][0] ) )
				? $ranges[ $i ]['values'][0]
				: array();
		}

		return $out;
	}

	/**
	 * Write the SAME row number in several tabs in one request.
	 *
	 * The multi-tab twin of update_rows(). Each tab gets its own bounded range,
	 * exactly as update_rows() builds them, so columns to the right of our data
	 * are never clobbered — see row_range().
	 *
	 * @param string $spreadsheet_id  Target spreadsheet.
	 * @param array  $values_by_sheet Tab name => the row of values to write there.
	 * @param int    $row_number      1-based row to overwrite in each tab.
	 * @param string $access_token    Valid OAuth access token.
	 * @return true|\WP_Error
	 */
	public function update_row_in_tabs( $spreadsheet_id, array $values_by_sheet, $row_number, $access_token ) {
		$row_number = max( 1, (int) $row_number );

		$data = array();
		foreach ( $values_by_sheet as $sheet_name => $values ) {
			if ( ! is_array( $values ) || empty( $values ) ) {
				continue; // A tab with nothing to write is skipped, not an error.
			}

			$values = array_values( $values );

			$data[] = array(
				'range'  => $this->row_range( $sheet_name, $row_number, count( $values ) ),
				'values' => array( $values ),
			);
		}

		if ( empty( $data ) ) {
			return true; // Nothing to do is not an error.
		}

		$body = $this->encode(
			array(
				'valueInputOption' => 'USER_ENTERED',
				'data'             => $data,
			)
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$response = wp_remote_post(
			self::API_BASE . rawurlencode( $spreadsheet_id ) . '/values:batchUpdate',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
			)
		);

		$error = $this->response_error( $response, 'wtg_update_failed' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return true;
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers.
	 * ---------------------------------------------------------------------- */

	/**
	 * POST a set of requests to spreadsheets:batchUpdate and hand back the reply.
	 *
	 * Note this is a DIFFERENT endpoint from values:batchUpdate used by
	 * update_rows(). That one edits cell contents; this one changes the structure
	 * of the document — adding tabs, deleting rows. They are easy to confuse, which
	 * is precisely why the structural calls are funnelled through one helper.
	 *
	 * Google applies the requests in order and atomically: if one fails, none are
	 * applied. That is what makes a multi-row delete safe to send as one call.
	 *
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @param array  $requests       List of request objects.
	 * @param string $access_token   Valid OAuth access token.
	 * @param string $code           Error code to report a failure under.
	 * @return array|\WP_Error Decoded response body.
	 */
	private function batch_update( $spreadsheet_id, array $requests, $access_token, $code ) {
		$body = $this->encode( array( 'requests' => array_values( $requests ) ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$response = wp_remote_post(
			self::API_BASE . rawurlencode( $spreadsheet_id ) . ':batchUpdate',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
			)
		);

		$error = $this->response_error( $response, $code );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Force a list of rows into the exact shape the API expects: a plain 2D array
	 * with sequential keys, so json_encode emits [[...],[...]] and never an object.
	 *
	 * A row array with non-sequential keys would silently serialize as a JSON
	 * object and be rejected by Google, which is an obscure failure to debug.
	 *
	 * @param array $rows List of rows.
	 * @return array
	 */
	private function normalize_rows( array $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array_values( $row );
			}
		}

		return $out;
	}

	/**
	 * JSON-encode a request body, failing loudly instead of silently.
	 *
	 * wp_json_encode() returns FALSE when handed malformed UTF-8 — which a product
	 * name imported from a bad source really can contain. Passing that false along
	 * would send an EMPTY request body and produce a baffling HTTP 400 from Google.
	 * Catching it here turns that into an error message that names the real cause.
	 *
	 * @param array $payload Body to encode.
	 * @return string|\WP_Error
	 */
	private function encode( array $payload ) {
		$json = wp_json_encode( $payload );

		if ( false === $json ) {
			return new \WP_Error(
				'wtg_encode_failed',
				__( 'Could not encode the order for Google Sheets — an order field contains invalid characters (usually a product name with broken text encoding).', 'woo-to-gsheet' )
			);
		}

		return $json;
	}

	/**
	 * Turn a raw HTTP response into a WP_Error, or null when it succeeded.
	 *
	 * Shared by all three calls so error reporting is identical everywhere.
	 *
	 * @param array|\WP_Error $response Result of a wp_remote_* call.
	 * @param string          $code     Error code to use for a bad HTTP status.
	 * @return \WP_Error|null
	 */
	private function response_error( $response, $code ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return null;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = isset( $data['error']['message'] )
			? $data['error']['message']
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'Sheets API returned HTTP %d.', 'woo-to-gsheet' ), $status );

		return new \WP_Error( $code, $message );
	}

	/**
	 * Quote a sheet/tab name for A1 notation.
	 *
	 * Unquoted names break the moment they contain a space ("Order Log"), so we
	 * always single-quote and escape any embedded apostrophe by doubling it —
	 * the escaping rule A1 notation uses.
	 *
	 * @param string $sheet_name Raw tab name.
	 * @return string e.g. "'Order Log'"
	 */
	private function a1_sheet( $sheet_name ) {
		return "'" . str_replace( "'", "''", (string) $sheet_name ) . "'";
	}

	/**
	 * The bounded A1 range for one whole row of $width columns in a tab.
	 *
	 * Bounding the range to exactly the columns we write (e.g. 'Sheet1'!A7:L7)
	 * means we can never clobber extra columns a user added to the right of our
	 * data. Shared by update_rows() and update_row_in_tabs() so the two cannot
	 * disagree about what "one row" spans.
	 *
	 * @param string $sheet_name Tab name.
	 * @param int    $row_number 1-based row.
	 * @param int    $width      Number of columns being written.
	 * @return string
	 */
	private function row_range( $sheet_name, $row_number, $width ) {
		return sprintf(
			'%s!%s%d:%s%d',
			$this->a1_sheet( $sheet_name ),
			self::ORDER_ID_COLUMN,
			(int) $row_number,
			$this->column_letter( max( 0, (int) $width - 1 ) ),
			(int) $row_number
		);
	}

	/**
	 * Convert a 0-based column index into its spreadsheet letter (0 => A, 25 =>
	 * Z, 26 => AA), so the write range grows correctly if columns are ever added.
	 *
	 * @param int $index 0-based column index.
	 * @return string
	 */
	private function column_letter( $index ) {
		$index  = max( 0, (int) $index );
		$letter = '';

		do {
			$letter = chr( 65 + ( $index % 26 ) ) . $letter;
			$index  = intdiv( $index, 26 ) - 1;
		} while ( $index >= 0 );

		return $letter;
	}
}
