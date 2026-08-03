<?php
/**
 * Thin wrapper around the Google Drive files:list endpoint.
 *
 * One job: list the spreadsheets in the connected account, so the settings page
 * can offer them by name. The Sheets API cannot do this — it only works with a
 * spreadsheet whose ID you already know — which is the only reason Drive is
 * involved at all.
 *
 * Like Sheets_Client this class is pure HTTP: no caching, no options, no
 * WordPress state beyond the HTTP helpers. Caching belongs to the caller, so the
 * Google/ folder stays a plain transport layer.
 *
 * @package WTG
 */

namespace WTG\Google;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Drive_Client
 */
class Drive_Client {

	/**
	 * Drive API v3 file listing endpoint.
	 */
	const API_BASE = 'https://www.googleapis.com/drive/v3/files';

	/**
	 * Google's MIME type for a Sheets document, used to filter out documents,
	 * PDFs, images and everything else in the user's Drive.
	 */
	const SPREADSHEET_MIME = 'application/vnd.google-apps.spreadsheet';

	/**
	 * List the account's spreadsheets.
	 *
	 * @param string $access_token Valid OAuth access token.
	 * @return array|\WP_Error List of array( 'id' => ..., 'name' => ... ), name-sorted.
	 */
	public function list_spreadsheets( $access_token ) {
		$url = add_query_arg(
			array(
				// Drive query syntax: spreadsheets only, nothing in the bin.
				'q'                         => "mimeType='" . self::SPREADSHEET_MIME . "' and trashed=false",
				// Only the two fields we use; a full file record is enormous.
				'fields'                    => 'files(id,name)',
				'orderBy'                   => 'name',
				'pageSize'                  => 200,
				// Include spreadsheets in Shared Drives, not just My Drive.
				'supportsAllDrives'         => 'true',
				'includeItemsFromAllDrives' => 'true',
			),
			self::API_BASE
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			// Google's own message is far more useful than anything we can guess —
			// when the Drive API is switched off it even includes the console URL
			// that turns it on. Always surface it.
			$google_message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';

			// Google puts a machine-readable cause here, which distinguishes the two
			// very different 403s: "the API is not enabled for this project" versus
			// "this token was not granted the Drive scope".
			$reason = isset( $data['error']['errors'][0]['reason'] ) ? (string) $data['error']['errors'][0]['reason'] : '';

			if ( 'accessNotConfigured' === $reason ) {
				return new \WP_Error(
					'wtg_drive_api_disabled',
					sprintf(
						/* translators: %s: Google's own error message, which contains the console URL. */
						__( 'The Google Drive API is not enabled in your Google Cloud project. Enabling the Sheets API is not enough — Drive must be switched on separately. Google said: %s', 'woo-to-gsheet' ),
						$google_message
					)
				);
			}

			if ( 401 === $status || 403 === $status ) {
				return new \WP_Error(
					'wtg_drive_scope_missing',
					sprintf(
						/* translators: %s: Google's own error message. */
						__( 'Google refused to list your spreadsheets, which usually means this connection was not granted the Drive permission. Disconnect and reconnect once. If it still fails, the Drive API may be disabled in your Google Cloud project, or the scope may be missing from your OAuth consent screen. Google said: %s', 'woo-to-gsheet' ),
						$google_message
					)
				);
			}

			$message = '' !== $google_message
				? $google_message
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Drive API returned HTTP %d.', 'woo-to-gsheet' ), $status );

			return new \WP_Error( 'wtg_drive_list_failed', $message );
		}

		$files = array();

		if ( isset( $data['files'] ) && is_array( $data['files'] ) ) {
			foreach ( $data['files'] as $file ) {
				if ( empty( $file['id'] ) ) {
					continue;
				}
				$files[] = array(
					'id'   => (string) $file['id'],
					'name' => isset( $file['name'] ) ? (string) $file['name'] : __( '(untitled)', 'woo-to-gsheet' ),
				);
			}
		}

		return $files;
	}
}
