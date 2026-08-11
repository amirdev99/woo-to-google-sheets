<?php
/**
 * Storage wrapper for all plugin settings.
 *
 * The entire plugin reads and writes configuration through this class, so no
 * other file ever touches get_option()/update_option() directly. Everything is
 * kept in ONE option array (wtg_settings) rather than many separate options.
 *
 * This class only stores and retrieves — it does not sanitize. Sanitizing
 * happens at the input boundary (the Settings API sanitize_callback in Phase 2).
 *
 * @package WTG
 */

namespace WTG;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	/**
	 * The single wp_options row that holds the whole settings array.
	 */
	const OPTION_KEY = 'wtg_settings';

	/**
	 * Default value for every setting the plugin uses.
	 *
	 * Declaring every key up front (including the OAuth token fields populated
	 * later in Phase 3) means callers always get a predictable type back and
	 * never have to guard against a missing key.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// --- Connection (Phase 2 settings form) ---------------------------
			'client_id'      => '',   // Google OAuth Client ID.
			'client_secret'  => '',   // Google OAuth Client Secret.
			'spreadsheet_id' => '',   // Target Google Sheet ID (from its URL).
			'sheet_name'     => 'Sheet1', // Tab/worksheet name rows are appended to.

			// --- OAuth tokens (populated in Phase 3) --------------------------
			'access_token'   => '',   // Short-lived token for API calls.
			'refresh_token'  => '',   // Long-lived token used to mint new access tokens.
			'token_expires'  => 0,    // Unix timestamp when access_token expires.

			// --- Connection health -------------------------------------------
			'reauth_needed'  => false, // True when a refresh failed and the user must reconnect.

			// --- Column selection (Fields tab) --------------------------------
			// Ordered list of Order_Mapper field keys to write. An EMPTY array
			// means "all fields", so a site that never opens the Fields tab keeps
			// the original twelve columns and needs no migration.
			'fields'         => array(),
			// Per-field column heading overrides, keyed by field key. Only values
			// that differ from the default are stored, so if a default label is
			// improved in a later version, non-overridden columns follow it.
			'field_labels'   => array(),

			// --- Per-status tabs (Status Tabs tab) ----------------------------
			// Off by default, so upgrading an existing install creates no tabs and
			// moves no rows until the admin opts in. The meaning of these values
			// (notably "empty statuses list = every status") belongs to
			// WTG\Sheets\Status_Tabs, which owns the constants naming these keys —
			// they are spelled out here only so every read has a predictable type.
			'status_tabs_enabled' => false,
			'status_tab_statuses' => array(), // Status slugs that get their own tab.
			'status_tab_names'    => array(), // Slug => admin's tab name override.
		);
	}

	/**
	 * Return the complete settings array with defaults filled in.
	 *
	 * wp_parse_args merges the stored values over the defaults, so any key that
	 * was never saved still comes back with its default instead of being absent.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		// Defensive: if the option was somehow corrupted into a non-array, fall
		// back to an empty array so wp_parse_args does not choke.
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Optional override if the key is unknown. When null,
	 *                        the key's declared default (or null) is returned.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Write a single setting value.
	 *
	 * Reads the RAW stored array (not the defaults-merged one) so we only ever
	 * persist values that were actually set, then writes the whole array back.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Value to store.
	 * @return bool Whether the option was updated.
	 */
	public static function set( $key, $value ) {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored[ $key ] = $value;

		return update_option( self::OPTION_KEY, $stored );
	}

	/**
	 * Write several settings at once.
	 *
	 * @param array $values Key => value pairs to merge into stored settings.
	 * @return bool Whether the option was updated.
	 */
	public static function update( array $values ) {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// array_merge lets the incoming values overwrite matching stored keys
		// while leaving untouched keys intact.
		$stored = array_merge( $stored, $values );

		return update_option( self::OPTION_KEY, $stored );
	}

	/**
	 * Remove a single stored setting (it will revert to its default on read).
	 *
	 * @param string $key Setting name.
	 * @return bool Whether the option was updated.
	 */
	public static function delete( $key ) {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return false;
		}

		unset( $stored[ $key ] );

		return update_option( self::OPTION_KEY, $stored );
	}
}
