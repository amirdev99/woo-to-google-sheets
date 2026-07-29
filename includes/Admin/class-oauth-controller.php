<?php
/**
 * Admin-post controller for the Google OAuth flow.
 *
 * Owns the connect / callback / disconnect / test actions. These are actions,
 * not pages: each does its work and redirects (Post/Redirect/Get), which is why
 * they live on admin-post.php rather than inside the settings page render.
 *
 * @package WTG
 */

namespace WTG\Admin;

use WTG\Settings;
use WTG\Google\OAuth_Client;
use WTG\Google\Sheets_Client;
use WTG\WooCommerce\Order_Mapper;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OAuth_Controller
 */
class OAuth_Controller {

	/**
	 * admin-post action that starts the connect flow.
	 */
	const ACTION_CONNECT = 'wtg_oauth_connect';

	/**
	 * admin-post action that disconnects (revokes + clears tokens).
	 */
	const ACTION_DISCONNECT = 'wtg_oauth_disconnect';

	/**
	 * admin-post action that runs the read-only Test Connection.
	 */
	const ACTION_TEST = 'wtg_test_connection';

	/**
	 * admin-post action that writes the column labels into row 1 of the sheet.
	 */
	const ACTION_WRITE_HEADER = 'wtg_write_header';

	/**
	 * Nonce action used for the OAuth `state` parameter (CSRF for the callback).
	 */
	const STATE_ACTION = 'wtg_oauth_state';

	/**
	 * Sheets API base for the read-only spreadsheets.get used by Test Connection.
	 */
	const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets/';

	/**
	 * Register the admin-post handlers and the admin notice printer.
	 *
	 * @return void
	 */
	public function hooks() {
		// admin_post_{action} fires only for logged-in users, which is exactly
		// what we want — every one of these requires an authenticated admin.
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . OAuth_Client::ACTION_CALLBACK, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::ACTION_TEST, array( $this, 'handle_test' ) );
		add_action( 'admin_post_' . self::ACTION_WRITE_HEADER, array( $this, 'handle_write_header' ) );

		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		// Persistent warning shown until the user reconnects an expired account.
		add_action( 'admin_notices', array( $this, 'render_reauth_notice' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * URL builders (used by the settings page to render buttons).
	 * ---------------------------------------------------------------------- */

	/**
	 * Nonced URL that starts the connect flow.
	 *
	 * @return string
	 */
	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CONNECT ), self::ACTION_CONNECT );
	}

	/**
	 * Nonced URL that disconnects.
	 *
	 * @return string
	 */
	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISCONNECT ), self::ACTION_DISCONNECT );
	}

	/**
	 * Nonced URL that runs Test Connection.
	 *
	 * @return string
	 */
	public static function test_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_TEST ), self::ACTION_TEST );
	}

	/**
	 * Nonced URL that writes the header row.
	 *
	 * @return string
	 */
	public static function write_header_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_WRITE_HEADER ), self::ACTION_WRITE_HEADER );
	}

	/* ---------------------------------------------------------------------- *
	 * Handlers.
	 * ---------------------------------------------------------------------- */

	/**
	 * Start the OAuth flow: redirect the admin to Google's consent screen.
	 *
	 * @return void
	 */
	public function handle_connect() {
		$this->authorize( self::ACTION_CONNECT );

		$oauth = new OAuth_Client();
		if ( ! $oauth->has_credentials() ) {
			$this->set_notice( 'error', __( 'Enter your Client ID and Client Secret first, then Save.', 'woo-to-gsheet' ) );
			$this->redirect_to_settings();
		}

		// The state is a nonce: Google echoes it back and the callback verifies
		// it, protecting the callback against forged redirects.
		$state = wp_create_nonce( self::STATE_ACTION );

		// External redirect to Google — wp_redirect (not wp_safe_redirect, which
		// would block the off-site host).
		wp_redirect( $oauth->get_authorize_url( $state ) );
		exit;
	}

	/**
	 * Handle Google's redirect back: verify state, exchange the code for tokens.
	 *
	 * This request comes FROM Google, so it carries no WP form nonce — the
	 * `state` parameter is our nonce instead. We still require an admin session.
	 *
	 * @return void
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'woo-to-gsheet' ), 403 );
		}

		// Verify the state nonce Google echoed back.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, self::STATE_ACTION ) ) {
			$this->set_notice( 'error', __( 'Security check failed (invalid state). Please try connecting again.', 'woo-to-gsheet' ) );
			$this->redirect_to_settings();
		}

		// The user may have denied consent, or Google may report an error.
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			/* translators: %s: error code from Google. */
			$this->set_notice( 'error', sprintf( __( 'Google returned an error: %s', 'woo-to-gsheet' ), $error ) );
			$this->redirect_to_settings();
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->set_notice( 'error', __( 'No authorization code was returned by Google.', 'woo-to-gsheet' ) );
			$this->redirect_to_settings();
		}

		$result = ( new OAuth_Client() )->exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice( 'success', __( 'Google account connected successfully.', 'woo-to-gsheet' ) );
		}

		$this->redirect_to_settings();
	}

	/**
	 * Disconnect the connected Google account.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->authorize( self::ACTION_DISCONNECT );

		( new OAuth_Client() )->disconnect();
		$this->set_notice( 'success', __( 'Google account disconnected.', 'woo-to-gsheet' ) );

		$this->redirect_to_settings();
	}

	/**
	 * Run a real read-only Sheets call to confirm the token + spreadsheet work.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->authorize( self::ACTION_TEST );

		$oauth = new OAuth_Client();

		// get_valid_access_token() refreshes transparently if needed.
		$token = $oauth->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			$this->set_notice( 'error', $token->get_error_message() );
			$this->redirect_to_settings();
		}

		$spreadsheet_id = Settings::get( 'spreadsheet_id', '' );
		if ( '' === $spreadsheet_id ) {
			$this->set_notice( 'error', __( 'Enter a Spreadsheet ID first, then Save.', 'woo-to-gsheet' ) );
			$this->redirect_to_settings();
		}

		$title = $this->fetch_spreadsheet_title( $token, $spreadsheet_id );
		if ( is_wp_error( $title ) ) {
			$this->set_notice( 'error', $title->get_error_message() );
		} else {
			/* translators: %s: spreadsheet title. */
			$this->set_notice( 'success', sprintf( __( 'Success! Connected to spreadsheet: %s', 'woo-to-gsheet' ), $title ) );
		}

		$this->redirect_to_settings();
	}

	/**
	 * Write the column labels into row 1 of the configured sheet.
	 *
	 * The labels come from Order_Mapper::header(), the SAME source the sync uses
	 * to build data rows, so the header can never disagree with what is written
	 * beneath it.
	 *
	 * Row 1 may already hold something, so we read it first and branch four ways:
	 *
	 *   - blank            -> write the header
	 *   - the same header  -> do nothing, report success (clicking twice is safe)
	 *   - a different header -> overwrite it; that is the point of the button
	 *   - ORDER DATA       -> refuse
	 *
	 * That last case matters: if syncing started before a header existed, row 1 is
	 * a real order, and blindly writing over it would destroy a record. Refusing
	 * matches how the processor already behaves when it cannot reconcile safely.
	 *
	 * @return void
	 */
	public function handle_write_header() {
		$this->authorize( self::ACTION_WRITE_HEADER );

		// get_valid_access_token() refreshes transparently if needed.
		$token = ( new OAuth_Client() )->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			$this->set_notice( 'error', $token->get_error_message() );
			$this->redirect_to_settings();
		}

		$spreadsheet_id = Settings::get( 'spreadsheet_id', '' );
		if ( '' === $spreadsheet_id ) {
			$this->set_notice( 'error', __( 'Enter a Spreadsheet ID first, then Save.', 'woo-to-gsheet' ) );
			$this->redirect_to_settings();
		}

		$sheet_name = Settings::get( 'sheet_name', 'Sheet1' );
		$sheets     = new Sheets_Client();

		$existing = $sheets->read_row( $spreadsheet_id, $sheet_name, 1, $token );
		if ( is_wp_error( $existing ) ) {
			$this->set_notice( 'error', $existing->get_error_message() );
			$this->redirect_to_settings();
		}

		$header = Order_Mapper::header();

		// Already correct — nothing to do.
		if ( $this->header_matches( $existing, $header ) ) {
			$this->set_notice( 'success', __( 'The header row is already up to date.', 'woo-to-gsheet' ) );
			$this->redirect_to_settings();
		}

		// Column A always holds the Order ID, so a NUMBER in A1 means row 1 is a
		// synced order rather than a header. Text means it is a header we may replace.
		if ( isset( $existing[0] ) && is_numeric( trim( (string) $existing[0] ) ) ) {
			$this->set_notice(
				'error',
				__( 'Row 1 of your sheet contains order data, not a header. Insert a blank row above it in Google Sheets, then click Write Header Row again.', 'woo-to-gsheet' )
			);
			$this->redirect_to_settings();
		}

		// Reuses the same bounded write the sync uses; row 1, one row of values.
		$result = $sheets->update_rows( $spreadsheet_id, $sheet_name, array( 1 ), array( $header ), $token );

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_notice(
				'success',
				sprintf(
					/* translators: %d: number of column labels written. */
					__( 'Header row written: %d columns.', 'woo-to-gsheet' ),
					count( $header )
				)
			);
		}

		$this->redirect_to_settings();
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers.
	 * ---------------------------------------------------------------------- */

	/**
	 * Is the sheet's row 1 already exactly our header?
	 *
	 * Compares trimmed strings. Google trims trailing empty cells, so a shorter
	 * returned row simply means "not a match" rather than needing special care.
	 *
	 * @param array $existing Cells read back from the sheet.
	 * @param array $header   Labels from Order_Mapper::header().
	 * @return bool
	 */
	private function header_matches( array $existing, array $header ) {
		if ( count( $existing ) !== count( $header ) ) {
			return false;
		}

		foreach ( $header as $i => $label ) {
			$cell = isset( $existing[ $i ] ) ? trim( (string) $existing[ $i ] ) : '';
			if ( $cell !== (string) $label ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A read-only spreadsheets.get for just the title — proves auth + ID work.
	 *
	 * (Phase 5 introduces the full Sheets_Client for writing rows; this small
	 * inline read is all Test Connection needs.)
	 *
	 * @param string $token          Valid access token.
	 * @param string $spreadsheet_id Target spreadsheet.
	 * @return string|\WP_Error Spreadsheet title, or WP_Error.
	 */
	private function fetch_spreadsheet_title( $token, $spreadsheet_id ) {
		$url = self::SHEETS_API . rawurlencode( $spreadsheet_id ) . '?fields=properties.title';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) ) {
			$message = isset( $data['error']['message'] )
				? $data['error']['message']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Sheets API returned HTTP %d.', 'woo-to-gsheet' ), $code );
			return new \WP_Error( 'wtg_sheets_error', $message );
		}

		return isset( $data['properties']['title'] ) ? $data['properties']['title'] : __( '(untitled)', 'woo-to-gsheet' );
	}

	/**
	 * Capability + nonce gate shared by connect/disconnect/test.
	 *
	 * @param string $action Nonce action to verify.
	 * @return void
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'woo-to-gsheet' ), 403 );
		}
		// check_admin_referer() verifies $_REQUEST['_wpnonce'] and wp_die()s if
		// it is missing or invalid.
		check_admin_referer( $action );
	}

	/**
	 * Stash a one-time admin notice for the current user.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			'wtg_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Print (once) and clear the current user's stashed admin notice.
	 *
	 * @return void
	 */
	public function render_notice() {
		$key    = 'wtg_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$class = ( isset( $notice['type'] ) && 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Persistent warning while a reconnect is required (refresh token died).
	 *
	 * Unlike render_notice() (a one-time transient), this shows on every admin
	 * page until the connection is restored, because syncing stays paused.
	 *
	 * @return void
	 */
	public function render_reauth_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Settings::get( 'reauth_needed', false ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html__( 'WooCommerce to Google Sheets:', 'woo-to-gsheet' );
		echo '</strong> ';
		echo esc_html__( 'Your Google connection has expired. Order syncing is paused until you reconnect.', 'woo-to-gsheet' );

		// Offer a direct reconnect link only if credentials are still present.
		if ( ( new OAuth_Client() )->has_credentials() ) {
			echo ' <a href="' . esc_url( self::connect_url() ) . '">' . esc_html__( 'Reconnect now', 'woo-to-gsheet' ) . '</a>';
		}

		echo '</p></div>';
	}

	/**
	 * Redirect back to the plugin's settings page (Connection tab) and stop.
	 *
	 * @return void
	 */
	private function redirect_to_settings() {
		// Settings_Page::url() owns the parent file, so moving the page in the menu
		// cannot leave this redirect pointing at a screen that no longer exists.
		wp_safe_redirect( Settings_Page::url( 'connection' ) );
		exit;
	}
}
