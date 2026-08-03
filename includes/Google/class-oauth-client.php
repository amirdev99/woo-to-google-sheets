<?php
/**
 * Google OAuth2 client (authorization-code flow).
 *
 * Handles building the consent URL, exchanging the returned code for tokens,
 * refreshing an expired access token, and handing callers a guaranteed-valid
 * access token. All credentials and tokens are read/written through Settings.
 *
 * @package WTG
 */

namespace WTG\Google;

use WTG\Settings;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OAuth_Client
 */
class OAuth_Client {

	/**
	 * Google's OAuth2 consent (authorization) endpoint.
	 */
	const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Google's OAuth2 token endpoint (code exchange + refresh).
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Google's token-revocation endpoint (used on disconnect, best effort).
	 */
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/**
	 * Google's token-introspection endpoint. Tells us what a token was ACTUALLY
	 * granted, which is the only way to settle "did the reconnect pick up the new
	 * scope or not" without guessing.
	 */
	const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

	/**
	 * The scopes we request, space separated (Google's required format).
	 *
	 *  - auth/spreadsheets: read/write a spreadsheet we know the ID of. This is
	 *    what the sync itself uses, and what lists a spreadsheet's tabs.
	 *
	 *  - auth/drive.metadata.readonly: permission to LIST the account's
	 *    spreadsheets, so the settings page can offer them by name instead of
	 *    making the user paste a raw ID. The Sheets API cannot do this — it only
	 *    works with an ID you already have — so Drive is unavoidable here.
	 *
	 * This is the NARROWEST Drive scope that can list files: it exposes names and
	 * IDs only, never file contents. (MetForm Pro requests full auth/drive for the
	 * same feature; this is deliberately tighter.)
	 *
	 * IMPORTANT: adding a scope does not upgrade an existing connection. A token
	 * issued under the old scope keeps the old permissions, and Drive calls will
	 * fail with 403 until the user disconnects and reconnects once. The settings
	 * page detects exactly that and explains it.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.metadata.readonly';

	/**
	 * admin-post.php action Google redirects back to. Single source of truth:
	 * the settings page displays redirect_uri() and the controller listens on
	 * this same action.
	 */
	const ACTION_CALLBACK = 'wtg_oauth_callback';

	/**
	 * Seconds of safety margin when checking access-token expiry, so we never
	 * try to use a token that is about to expire mid-request.
	 */
	const EXPIRY_BUFFER = 60;

	/**
	 * The exact redirect URI registered with Google.
	 *
	 * Built from admin-post.php so it respects the site's real scheme/host.
	 * Google requires an EXACT string match, so this must never vary.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return add_query_arg(
			'action',
			self::ACTION_CALLBACK,
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Whether the site owner has entered the app credentials.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== Settings::get( 'client_id', '' ) && '' !== Settings::get( 'client_secret', '' );
	}

	/**
	 * Whether we hold a refresh token (i.e. an account is connected).
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== Settings::get( 'refresh_token', '' );
	}

	/**
	 * Build the Google consent URL to send the admin to.
	 *
	 * @param string $state Opaque anti-CSRF value (a nonce) echoed back to us.
	 * @return string
	 */
	public function get_authorize_url( $state ) {
		$params = array(
			'client_id'              => Settings::get( 'client_id', '' ),
			'redirect_uri'           => self::redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => self::SCOPE,
			// access_type=offline asks Google for a refresh token; prompt=consent
			// forces it to be RETURNED even on re-authorization (otherwise Google
			// only sends one on the very first consent).
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
		);

		return self::AUTH_URL . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for access + refresh tokens and store them.
	 *
	 * @param string $code The authorization code from the callback.
	 * @return true|\WP_Error True on success, WP_Error describing the failure.
	 */
	public function exchange_code( $code ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => Settings::get( 'client_id', '' ),
					'client_secret' => Settings::get( 'client_secret', '' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$data = $this->parse_token_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// A refresh token MUST be present at exchange time; without it we cannot
		// sync unattended later. If it is missing, surface a clear error.
		if ( empty( $data['refresh_token'] ) ) {
			return new \WP_Error(
				'wtg_no_refresh_token',
				__( 'Google did not return a refresh token. Disconnect, then reconnect and approve access again.', 'woo-to-gsheet' )
			);
		}

		$this->store_tokens( $data );
		// A fresh connection clears any prior "reconnect needed" flag.
		Settings::set( 'reauth_needed', false );
		return true;
	}

	/**
	 * Use the stored refresh token to obtain a new access token.
	 *
	 * @return true|\WP_Error
	 */
	public function refresh_access_token() {
		$refresh_token = Settings::get( 'refresh_token', '' );
		if ( '' === $refresh_token ) {
			return new \WP_Error( 'wtg_not_connected', __( 'No refresh token stored; connect a Google account first.', 'woo-to-gsheet' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'refresh_token' => $refresh_token,
					'client_id'     => Settings::get( 'client_id', '' ),
					'client_secret' => Settings::get( 'client_secret', '' ),
					'grant_type'    => 'refresh_token',
				),
			)
		);

		$data = $this->parse_token_response( $response );
		if ( is_wp_error( $data ) ) {
			// A dead refresh token (expired after 7 days in Testing mode, or
			// revoked) comes back as invalid_grant. Treat it as "must reconnect":
			// clear the tokens so the UI shows disconnected and raise a flag that
			// drives a persistent reconnect notice.
			if ( 'invalid_grant' === $this->google_error_code( $data ) ) {
				$this->flag_reauth_needed();
				return new \WP_Error(
					'wtg_reauth_required',
					__( 'Your Google connection has expired or was revoked. Please reconnect your Google account.', 'woo-to-gsheet' )
				);
			}
			return $data;
		}

		// Google does NOT return a refresh_token on a refresh, so store_tokens()
		// keeps the existing one untouched.
		$this->store_tokens( $data );
		return true;
	}

	/**
	 * Return a valid access token, refreshing first if necessary.
	 *
	 * This is the method background code (Phase 5 cron) and Test Connection call.
	 *
	 * @return string|\WP_Error Access token string, or WP_Error on failure.
	 */
	public function get_valid_access_token() {
		if ( ! $this->is_connected() ) {
			return new \WP_Error( 'wtg_not_connected', __( 'No Google account is connected.', 'woo-to-gsheet' ) );
		}

		if ( '' === Settings::get( 'access_token', '' ) || $this->is_token_expired() ) {
			$refreshed = $this->refresh_access_token();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		return Settings::get( 'access_token', '' );
	}

	/**
	 * The scopes Google actually granted this token.
	 *
	 * Diagnostic only — nothing in the sync path depends on it. It exists because
	 * "I reconnected but Drive still fails" has several possible causes, and this
	 * is the one call that distinguishes them: if the Drive scope is listed here,
	 * the consent worked and the problem is elsewhere (usually the Drive API being
	 * switched off in the Google Cloud project).
	 *
	 * @param string $access_token Token to inspect.
	 * @return array|\WP_Error List of granted scope URLs.
	 */
	public function granted_scopes( $access_token ) {
		$response = wp_remote_get(
			add_query_arg( 'access_token', rawurlencode( $access_token ), self::TOKENINFO_URL ),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['scope'] ) ) {
			return new \WP_Error( 'wtg_tokeninfo_failed', __( 'Google did not report this token\'s permissions.', 'woo-to-gsheet' ) );
		}

		// tokeninfo returns them space separated, the same format we send.
		return array_values( array_filter( explode( ' ', (string) $data['scope'] ) ) );
	}

	/**
	 * Whether the stored access token is expired (with a safety buffer).
	 *
	 * @return bool
	 */
	public function is_token_expired() {
		$expires = (int) Settings::get( 'token_expires', 0 );
		return $expires <= ( time() + self::EXPIRY_BUFFER );
	}

	/**
	 * Disconnect: best-effort revoke at Google, then clear all local tokens.
	 *
	 * @return void
	 */
	public function disconnect() {
		$refresh_token = Settings::get( 'refresh_token', '' );
		if ( '' !== $refresh_token ) {
			// Best effort: tell Google to invalidate the grant. We ignore the
			// result — even if this fails, we still clear our local copy below.
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $refresh_token ),
				)
			);
		}

		Settings::update(
			array(
				'access_token'  => '',
				'refresh_token' => '',
				'token_expires' => 0,
				// Intentional disconnect — no reconnect prompt needed.
				'reauth_needed' => false,
			)
		);
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * Decode a token-endpoint HTTP response into a data array, or a WP_Error.
	 *
	 * Centralizes the transport + JSON + API-error handling shared by
	 * exchange_code() and refresh_access_token().
	 *
	 * @param array|\WP_Error $response Result of wp_remote_post().
	 * @return array|\WP_Error
	 */
	private function parse_token_response( $response ) {
		// Transport-level failure (DNS, timeout, no route...).
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'wtg_bad_token_response', __( 'Unexpected (non-JSON) response from Google.', 'woo-to-gsheet' ) );
		}

		// Google returns HTTP 4xx with { error, error_description } on failure.
		if ( $code < 200 || $code >= 300 || isset( $data['error'] ) ) {
			$message = isset( $data['error_description'] )
				? $data['error_description']
				: ( isset( $data['error'] ) ? $data['error'] : __( 'Unknown OAuth error.', 'woo-to-gsheet' ) );
			// Carry Google's machine-readable error code in the WP_Error data so
			// callers (e.g. refresh) can react to specific codes like invalid_grant.
			return new \WP_Error(
				'wtg_oauth_error',
				$message,
				array( 'google_error' => isset( $data['error'] ) ? $data['error'] : '' )
			);
		}

		return $data;
	}

	/**
	 * Extract Google's machine-readable error code from a token-response WP_Error.
	 *
	 * @param \WP_Error $error Error returned by parse_token_response().
	 * @return string The google_error code, or '' if none.
	 */
	private function google_error_code( \WP_Error $error ) {
		$data = $error->get_error_data();
		return ( is_array( $data ) && isset( $data['google_error'] ) ) ? $data['google_error'] : '';
	}

	/**
	 * Clear tokens and flag that the user must reconnect.
	 *
	 * @return void
	 */
	private function flag_reauth_needed() {
		Settings::update(
			array(
				'access_token'  => '',
				'refresh_token' => '',
				'token_expires' => 0,
				'reauth_needed' => true,
			)
		);
	}

	/**
	 * Persist tokens from a decoded token response.
	 *
	 * refresh_token is only written when present (exchange returns it; refresh
	 * does not), so an existing refresh token is never clobbered.
	 *
	 * @param array $data Decoded token response.
	 * @return void
	 */
	private function store_tokens( array $data ) {
		$values = array();

		if ( isset( $data['access_token'] ) ) {
			$values['access_token'] = sanitize_text_field( $data['access_token'] );
		}

		if ( isset( $data['expires_in'] ) ) {
			// Store the absolute expiry timestamp for easy comparison later.
			$values['token_expires'] = time() + (int) $data['expires_in'];
		}

		if ( ! empty( $data['refresh_token'] ) ) {
			$values['refresh_token'] = sanitize_text_field( $data['refresh_token'] );
		}

		Settings::update( $values );
	}
}
