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

		// One entry per row, each naming its own bounded range. Bounding the range
		// to exactly the columns we write (e.g. A7:L7) means we can never clobber
		// extra columns a user added to the right of our data.
		$data = array();
		foreach ( $rows as $i => $values ) {
			$data[] = array(
				'range'  => sprintf(
					'%s!%s%d:%s%d',
					$this->a1_sheet( $sheet_name ),
					self::ORDER_ID_COLUMN,
					(int) $row_numbers[ $i ],
					$this->column_letter( count( $values ) - 1 ),
					(int) $row_numbers[ $i ]
				),
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

	/* ---------------------------------------------------------------------- *
	 * Helpers.
	 * ---------------------------------------------------------------------- */

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
