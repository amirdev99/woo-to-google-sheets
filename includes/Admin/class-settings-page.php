<?php
/**
 * The admin settings page (Settings > Woo to Google Sheets).
 *
 * Built on the WordPress Settings API: we describe our option and fields, and
 * WordPress renders the form and saves it via options.php (handling the nonce
 * and capability check for us). This class also owns the tab navigation.
 *
 * @package WTG
 */

namespace WTG\Admin;

use WTG\Settings;
use WTG\Google\OAuth_Client;
use WTG\Google\Drive_Client;
use WTG\Google\Sheets_Client;
use WTG\Queue\Sync_Queue;
use WTG\Queue\Sync_Runner;
use WTG\Sheets\Status_Tabs;
use WTG\WooCommerce\Order_Mapper;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Page
 */
class Settings_Page {

	/**
	 * The ?page= slug for this admin screen.
	 */
	const MENU_SLUG = 'woo-to-google-sheets';

	/**
	 * Seconds an order may sit unsent before we call the sync stalled.
	 *
	 * Ten minutes is deliberately forgiving: the recurring cron runs every five,
	 * so anything under that is just normal waiting and warning about it would
	 * train the shop owner to ignore the notice. Only rows that have never been
	 * attempted count towards this (see Sync_Queue::oldest_pending_age) — a row
	 * being retried after a Google error is spaced out on purpose, and is a
	 * different problem, reported per-row in the log.
	 */
	const STALLED_AFTER = 600;

	/**
	 * Where the top-level menu item sits in the admin sidebar.
	 *
	 * WordPress orders the menu by this number. 56 lands just below WooCommerce
	 * and its Products/Analytics/Marketing group (which occupy 55.x), so the
	 * plugin sits with the commerce tools rather than among the WordPress ones.
	 */
	const MENU_POSITION = 56;

	/**
	 * The Settings API option group. settings_fields() and register_setting()
	 * must use the SAME group string or options.php will refuse to save.
	 */
	const SETTINGS_GROUP = 'wtg_settings_group';

	/**
	 * ID of the "connection" settings section.
	 */
	const CONNECTION_SECTION = 'wtg_connection_section';

	/**
	 * Hidden input name that tells sanitize() WHICH form was submitted.
	 *
	 * Two separate forms (Connection and Fields) post to the same option. An
	 * unchecked checkbox is simply not submitted, so without this marker, saving
	 * the Connection tab would look identical to "the user unticked every field"
	 * and would wipe the column selection. sanitize() only touches the keys
	 * belonging to the form that was actually submitted.
	 */
	const FORM_MARKER = '_form';

	/**
	 * Query argument (and nonce action) for the "refresh the lists" link.
	 */
	const REFRESH_ARG = 'wtg_refresh_lists';

	/**
	 * Transient holding the account's spreadsheet list.
	 */
	const CACHE_SPREADSHEETS = 'wtg_spreadsheet_list';

	/**
	 * Prefix for the per-spreadsheet tab-title cache.
	 */
	const CACHE_SHEETS_PREFIX = 'wtg_sheet_titles_';

	/**
	 * How long a fetched list stays fresh (10 minutes).
	 *
	 * Long enough that clicking around the settings page costs no API calls,
	 * short enough that a newly created sheet appears soon. The refresh link
	 * clears both caches on demand.
	 */
	const CACHE_TTL = 600;

	/**
	 * Register the admin hooks. Called from Plugin::run() (in admin context).
	 *
	 * @return void
	 */
	public function hooks() {
		// admin_menu: add our page to the menu. admin_init: register the setting
		// and its fields. Both only ever fire inside wp-admin.
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		// Must run before any output, so admin_init rather than during render.
		add_action( 'admin_init', array( $this, 'maybe_refresh_lists' ) );

		// Deliberately on EVERY admin screen, not just ours. A shop owner who
		// thinks the plugin is working has no reason to open its settings page —
		// which is exactly when they most need telling that it is not.
		add_action( 'admin_notices', array( $this, 'maybe_render_stalled_notice' ) );
	}

	/**
	 * Warn, anywhere in wp-admin, when orders are stuck waiting to sync.
	 *
	 * Sync_Runner has four independent ways to drain the queue. If an order has
	 * still been sitting there for STALLED_AFTER seconds, all four failed, and no
	 * amount of waiting will fix it — so we say so, in the shop owner's language,
	 * with the one thing that reliably repairs it.
	 *
	 * @return void
	 */
	public function maybe_render_stalled_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$waiting = Sync_Queue::oldest_pending_age();
		if ( $waiting < self::STALLED_AFTER ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			esc_html__( 'Woo to Google Sheets:', 'woo-to-gsheet' ),
			esc_html(
				sprintf(
					/* translators: %s: human-readable duration, e.g. "22 mins". */
					__( 'orders have been waiting %s to reach your spreadsheet. Automatic syncing does not appear to be running on this site.', 'woo-to-gsheet' ),
					human_time_diff( time() - $waiting, time() )
				)
			),
			wp_kses_post(
				sprintf(
					/* translators: %s: link to the Sync Log tab. */
					__( 'This is almost always your site\'s scheduled tasks (WP-Cron) being blocked — ask your host to confirm loopback requests are allowed, or set up a real server cron job. %s', 'woo-to-gsheet' ),
					'<a href="' . esc_url( self::url( 'sync_log' ) ) . '">' . esc_html__( 'Open the Sync Log', 'woo-to-gsheet' ) . '</a>'
				)
			)
		);
	}

	/**
	 * Add the plugin's own TOP-LEVEL menu item.
	 *
	 * add_menu_page() enforces the 'manage_options' capability — users who lack
	 * it never see the menu item nor can load the page.
	 *
	 * NOTE: a top-level page lives at admin.php?page=<slug>, NOT at
	 * options-general.php?page=<slug>. Anything that links or redirects here must
	 * go through url() below rather than building that path by hand.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'WooCommerce to Google Sheets', 'woo-to-gsheet' ), // <title> text.
			__( 'Woo to Google Sheets', 'woo-to-gsheet' ),         // Menu label.
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-media-spreadsheet',
			self::MENU_POSITION
		);
	}

	/**
	 * The admin URL of this page, optionally on a given tab.
	 *
	 * Single source of truth: the controllers redirect here after connecting,
	 * disconnecting and processing the queue. Keeping the parent file in ONE place
	 * means moving the page again cannot leave a stale redirect behind.
	 *
	 * @param string $tab Tab slug to open ('connection' or 'sync_log').
	 * @return string
	 */
	public static function url( $tab = 'connection' ) {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Register the option + its sections and fields with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Announce our single option to WordPress and point it at our sanitize
		// callback. options.php will only save an option that was registered here.
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		// One visual section on the Connection tab.
		add_settings_section(
			self::CONNECTION_SECTION,
			__( 'Google Connection', 'woo-to-gsheet' ),
			array( $this, 'render_section_intro' ),
			self::MENU_SLUG
		);

		// Each field points at a callback that echoes a single input. The field
		// input names are wtg_settings[<key>], so they all land in one array.
		$fields = array(
			'client_id'      => __( 'Client ID', 'woo-to-gsheet' ),
			'client_secret'  => __( 'Client Secret', 'woo-to-gsheet' ),
			'redirect_uri'   => __( 'Redirect URI', 'woo-to-gsheet' ),
			'spreadsheet_id' => __( 'Spreadsheet ID', 'woo-to-gsheet' ),
			'sheet_name'     => __( 'Sheet Name', 'woo-to-gsheet' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_field_' . $key ),
				self::MENU_SLUG,
				self::CONNECTION_SECTION,
				array( 'label_for' => 'wtg_' . $key ) // links the <label> to the input id.
			);
		}
	}

	/**
	 * Sanitize the whole option array before it is stored.
	 *
	 * CRITICAL: this form only submits the connection fields, but the option
	 * also holds OAuth tokens (Phase 3). If we returned only the submitted keys,
	 * saving the form would wipe the tokens. So we start from the EXISTING stored
	 * array and overwrite just the connection keys, preserving everything else.
	 *
	 * @param mixed $input Raw submitted wtg_settings array (untrusted).
	 * @return array The full array to store.
	 */
	public function sanitize( $input ) {
		// Defensive: never trust the shape of $input.
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Start from what is already saved so token fields survive untouched.
		$existing = get_option( Settings::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$output = $existing;

		// Which form did this come from? Programmatic writes (the OAuth token
		// saves) carry no marker and fall through BOTH branches, leaving every
		// user-facing key exactly as stored — which is what we want.
		$form = isset( $input[ self::FORM_MARKER ] ) ? sanitize_key( $input[ self::FORM_MARKER ] ) : '';

		if ( 'connection' === $form ) {
			// Plain text fields.
			$output['client_id']      = isset( $input['client_id'] ) ? sanitize_text_field( $input['client_id'] ) : '';
			$output['spreadsheet_id'] = isset( $input['spreadsheet_id'] ) ? sanitize_text_field( $input['spreadsheet_id'] ) : '';

			// Sheet name: fall back to a sensible default if cleared.
			$sheet                = isset( $input['sheet_name'] ) ? sanitize_text_field( $input['sheet_name'] ) : '';
			$output['sheet_name'] = '' !== $sheet ? $sheet : 'Sheet1';

			// Client Secret: the field renders blank for security, so a blank
			// submission means "keep the existing secret", not "erase it".
			$submitted_secret = isset( $input['client_secret'] ) ? trim( $input['client_secret'] ) : '';
			if ( '' !== $submitted_secret ) {
				$output['client_secret'] = sanitize_text_field( $submitted_secret );
			}
		}

		if ( 'fields' === $form ) {
			$output = $this->sanitize_fields( $input, $output, $existing );
		}

		if ( 'status_tabs' === $form ) {
			$output = $this->sanitize_status_tabs( $input, $output );
		}

		// CRITICAL: register_setting() runs this callback on EVERY update of the
		// wtg_settings option — including our own programmatic token writes from
		// the OAuth flow (store_tokens/disconnect), because it filters
		// sanitize_option_wtg_settings. Those writes are the ONLY source of these
		// keys (the form has no inputs for them), so we must pass them through
		// when present; otherwise connecting would appear to succeed but the
		// refresh token would be stripped and never saved.
		foreach ( array( 'access_token', 'refresh_token', 'token_expires', 'reauth_needed' ) as $internal_key ) {
			if ( array_key_exists( $internal_key, $input ) ) {
				$output[ $internal_key ] = $input[ $internal_key ];
			}
		}

		// NOTE: redirect_uri is display-only (read-only field), never submitted,
		// so it is intentionally not stored here — it is always computed live.

		return $output;
	}

	/**
	 * Sanitize the Fields tab: which columns to write, and what to call them.
	 *
	 * @param array $input    Raw submitted array.
	 * @param array $output   Output built so far.
	 * @param array $existing Previously stored settings, for change detection.
	 * @return array
	 */
	private function sanitize_fields( array $input, array $output, array $existing ) {
		$registry  = Order_Mapper::fields();
		$submitted = ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) ? $input['fields'] : array();

		// Walk the CANONICAL order and keep what was ticked. This is the same loop
		// direction Order_Mapper::selected_keys() uses, and it is what makes the
		// column order impossible to scramble and unknown keys impossible to store.
		$chosen = array();
		foreach ( array_keys( $registry ) as $key ) {
			if ( in_array( $key, $submitted, true ) || Order_Mapper::is_locked( $key ) ) {
				$chosen[] = $key;
			}
		}
		$output['fields'] = $chosen;

		// Store ONLY labels that differ from the default. That way, if a default
		// label is improved in a future version, columns the user never renamed
		// pick up the new wording automatically.
		$labels = array();
		if ( isset( $input['field_labels'] ) && is_array( $input['field_labels'] ) ) {
			foreach ( $registry as $key => $field ) {
				if ( ! isset( $input['field_labels'][ $key ] ) ) {
					continue;
				}
				$value = sanitize_text_field( $input['field_labels'][ $key ] );
				if ( '' !== $value && $value !== $field['label'] ) {
					$labels[ $key ] = $value;
				}
			}
		}
		$output['field_labels'] = $labels;

		// If the layout actually changed, the sheet no longer matches. Say so
		// rather than letting the user discover it as misaligned columns.
		$before = isset( $existing['fields'] ) && is_array( $existing['fields'] ) ? $existing['fields'] : array();

		// add_settings_error() lives in wp-admin/includes/template.php, which is
		// NOT loaded on every request. sanitize() runs on every write to this
		// option — including from admin-post.php during the OAuth callback — so
		// guard it rather than assuming an admin-page context.
		if ( $before !== $chosen && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				Settings::OPTION_KEY,
				'wtg_layout_changed',
				__( 'Column layout changed. Click "Write Header Row" on the Connection tab to update your sheet. Rows already in the sheet still use the old layout.', 'woo-to-gsheet' ),
				'warning'
			);
		}

		return $output;
	}

	/**
	 * Sanitize the Status Tabs tab: the switch, the routed statuses, the names.
	 *
	 * @param array $input  Raw submitted array.
	 * @param array $output Output built so far.
	 * @return array
	 */
	private function sanitize_status_tabs( array $input, array $output ) {
		$enabled = ! empty( $input[ Status_Tabs::SETTING_ENABLED ] );

		$available = Status_Tabs::available_statuses();

		// No WooCommerce, no status list — so the form rendered no rows and there is
		// nothing to read. Save the switch, but leave the stored selection and names
		// alone rather than wiping a configuration this request could not even see.
		if ( empty( $available ) ) {
			$output[ Status_Tabs::SETTING_ENABLED ] = $enabled;

			return $output;
		}

		$submitted = ( isset( $input[ Status_Tabs::SETTING_STATUSES ] ) && is_array( $input[ Status_Tabs::SETTING_STATUSES ] ) )
			? $input[ Status_Tabs::SETTING_STATUSES ]
			: array();

		// Walk the AVAILABLE list and keep what was ticked, the same direction of
		// travel as sanitize_fields(): an unknown slug can never be stored, and the
		// stored order always matches WooCommerce's own.
		$chosen = array();
		foreach ( array_keys( $available ) as $slug ) {
			if ( in_array( $slug, $submitted, true ) ) {
				$chosen[] = $slug;
			}
		}

		if ( empty( $chosen ) ) {
			// An empty list MEANS "all statuses" to Status_Tabs::tracked_statuses(),
			// so "none" is not a storable state. A feature that is on but routes
			// nothing would be a lie in the UI, so untick-everything is read as
			// "switch it off" — and we say so instead of quietly doing something else.
			if ( $enabled && function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					Settings::OPTION_KEY,
					'wtg_status_tabs_none',
					__( 'No order statuses were selected, so per-status tabs have been switched off. Tick at least one status to turn the feature on.', 'woo-to-gsheet' ),
					'warning'
				);
			}

			$enabled = false;
		}

		$output[ Status_Tabs::SETTING_ENABLED ] = $enabled;

		// Storing "everything is ticked" as an EMPTY array is deliberate: it records
		// the intent ("all statuses") rather than a snapshot of today's list, so a
		// status added later by WooCommerce or another plugin gets a tab too.
		$output[ Status_Tabs::SETTING_STATUSES ] = ( count( $chosen ) === count( $available ) ) ? array() : $chosen;

		// Names: keep ONLY what differs from WooCommerce's own label, so a site that
		// never renamed a tab follows the label if WooCommerce ever rewords it.
		$names = array();
		if ( isset( $input[ Status_Tabs::SETTING_NAMES ] ) && is_array( $input[ Status_Tabs::SETTING_NAMES ] ) ) {
			foreach ( $available as $slug => $label ) {
				if ( ! isset( $input[ Status_Tabs::SETTING_NAMES ][ $slug ] ) ) {
					continue;
				}

				$value = sanitize_text_field( $input[ Status_Tabs::SETTING_NAMES ][ $slug ] );

				if ( '' !== $value && $value !== $label ) {
					$names[ $slug ] = $value;
				}
			}
		}
		$output[ Status_Tabs::SETTING_NAMES ] = $names;

		return $output;
	}

	/* -------------------------------------------------------------------------
	 * Google list fetching + caching.
	 *
	 * Caching lives HERE rather than in the Google/ clients, so those stay a pure
	 * transport layer with no WordPress state. See docs/01-architecture.md.
	 * ---------------------------------------------------------------------- */

	/**
	 * Access token for this request, fetched at most once.
	 *
	 * Both dropdowns need a token. Without memoising, rendering the page could
	 * trigger two refresh round-trips to Google for the same token.
	 *
	 * @var string|\WP_Error|null
	 */
	private $token = null;

	/**
	 * A valid access token, or a WP_Error explaining why not.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token() {
		if ( null === $this->token ) {
			$oauth = new OAuth_Client();

			$this->token = $oauth->is_connected()
				? $oauth->get_valid_access_token()
				: new \WP_Error( 'wtg_not_connected', __( 'Connect your Google account to load your spreadsheets.', 'woo-to-gsheet' ) );
		}

		return $this->token;
	}

	/**
	 * The account's spreadsheets, cached.
	 *
	 * @return array|\WP_Error
	 */
	private function cached_spreadsheets() {
		$cached = get_transient( self::CACHE_SPREADSHEETS );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$list = ( new Drive_Client() )->list_spreadsheets( $token );
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		set_transient( self::CACHE_SPREADSHEETS, $list, self::CACHE_TTL );

		return $list;
	}

	/**
	 * A spreadsheet's tab names, cached per spreadsheet.
	 *
	 * @param string $spreadsheet_id Spreadsheet to inspect.
	 * @return array|\WP_Error
	 */
	private function cached_sheet_titles( $spreadsheet_id ) {
		// md5 keeps the transient name within the option-name length limit,
		// whatever the spreadsheet ID looks like.
		$key = self::CACHE_SHEETS_PREFIX . md5( $spreadsheet_id );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$titles = ( new Sheets_Client() )->list_sheet_titles( $spreadsheet_id, $token );
		if ( is_wp_error( $titles ) ) {
			return $titles;
		}

		set_transient( $key, $titles, self::CACHE_TTL );

		return $titles;
	}

	/**
	 * Nonced URL that clears both cached lists.
	 *
	 * @return string
	 */
	public static function refresh_url() {
		return wp_nonce_url( add_query_arg( self::REFRESH_ARG, '1', self::url( 'connection' ) ), self::REFRESH_ARG );
	}

	/**
	 * Handle the refresh link: drop the caches and bounce back to a clean URL, so
	 * reloading the page does not repeat the action.
	 *
	 * @return void
	 */
	public function maybe_refresh_lists() {
		if ( ! isset( $_GET[ self::REFRESH_ARG ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::REFRESH_ARG ) ) {
			return;
		}

		delete_transient( self::CACHE_SPREADSHEETS );

		$spreadsheet_id = Settings::get( 'spreadsheet_id', '' );
		if ( '' !== $spreadsheet_id ) {
			delete_transient( self::CACHE_SHEETS_PREFIX . md5( $spreadsheet_id ) );
		}

		wp_safe_redirect( self::url( 'connection' ) );
		exit;
	}

	/**
	 * Print the permissions Google says this connection actually has.
	 *
	 * This is the diagnostic that separates the two ways the Drive listing can
	 * fail. If drive.metadata.readonly appears here, the consent screen did its
	 * job and reconnecting again will not help — the Drive API is switched off in
	 * the Google Cloud project. If it does NOT appear, the consent did not grant
	 * it, and reconnecting is the fix.
	 *
	 * @return void
	 */
	private function render_granted_scopes() {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return;
		}

		$scopes = ( new OAuth_Client() )->granted_scopes( $token );
		if ( is_wp_error( $scopes ) || empty( $scopes ) ) {
			return;
		}

		$has_drive = false;
		foreach ( $scopes as $scope ) {
			if ( false !== strpos( $scope, '/auth/drive' ) ) {
				$has_drive = true;
				break;
			}
		}

		echo '<p class="description"><strong>' . esc_html__( 'Permissions Google reports for this connection:', 'woo-to-gsheet' ) . '</strong><br />';
		echo esc_html( implode( ', ', $scopes ) ) . '</p>';

		echo '<p class="description">';
		if ( $has_drive ) {
			echo '<strong>' . esc_html__( 'A Drive permission IS present', 'woo-to-gsheet' ) . '</strong> &mdash; ';
			echo esc_html__( 'so reconnecting will not help. Enable the Google Drive API in your Google Cloud project (it is separate from the Sheets API), then click Refresh.', 'woo-to-gsheet' );
		} else {
			echo '<strong>' . esc_html__( 'No Drive permission was granted', 'woo-to-gsheet' ) . '</strong> &mdash; ';
			echo esc_html__( 'add the drive.metadata.readonly scope on your OAuth consent screen, then disconnect and reconnect.', 'woo-to-gsheet' );
		}
		echo '</p>';
	}

	/**
	 * The "Refresh lists" link, shown next to both dropdowns.
	 *
	 * @return void
	 */
	private function render_refresh_link() {
		printf(
			' <a href="%1$s" class="button button-small" title="%2$s">%3$s</a>',
			esc_url( self::refresh_url() ),
			esc_attr__( 'Re-fetch both lists from Google', 'woo-to-gsheet' ),
			esc_html__( 'Refresh', 'woo-to-gsheet' )
		);
	}

	/* -------------------------------------------------------------------------
	 * Field renderers. Each echoes exactly one input.
	 * ---------------------------------------------------------------------- */

	/**
	 * Intro text shown at the top of the Connection section.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Enter your Google OAuth credentials and the target spreadsheet. You will connect the account in the next phase.', 'woo-to-gsheet' ) . '</p>';
	}

	/**
	 * Client ID (plain text).
	 *
	 * @return void
	 */
	public function render_field_client_id() {
		$value = Settings::get( 'client_id', '' );
		printf(
			'<input type="text" id="wtg_client_id" name="%1$s[client_id]" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $value )
		);
	}

	/**
	 * Client Secret (password; rendered blank on purpose).
	 *
	 * We never echo the stored secret into the page source. If a secret is
	 * already saved we show a placeholder and let the user leave it blank to
	 * keep it (see sanitize()).
	 *
	 * @return void
	 */
	public function render_field_client_secret() {
		$has_secret  = '' !== Settings::get( 'client_secret', '' );
		$placeholder = $has_secret
			? __( '•••••••• (leave blank to keep saved secret)', 'woo-to-gsheet' )
			: __( 'Enter your client secret', 'woo-to-gsheet' );

		printf(
			'<input type="password" id="wtg_client_secret" name="%1$s[client_secret]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Redirect URI (read-only). This is the exact URL to paste into the Google
	 * Cloud console as an authorized redirect URI. It is computed live, never
	 * stored, and must match Phase 3's admin-post handler.
	 *
	 * @return void
	 */
	public function render_field_redirect_uri() {
		printf(
			'<input type="text" id="wtg_redirect_uri" value="%1$s" class="large-text code" readonly onclick="this.select();" />',
			esc_url( OAuth_Client::redirect_uri() )
		);
		echo '<p class="description">' . esc_html__( 'Add this exact URL as an Authorized redirect URI in your Google Cloud OAuth client.', 'woo-to-gsheet' ) . '</p>';
	}

	/**
	 * Spreadsheet ID (plain text).
	 *
	 * @return void
	 */
	public function render_field_spreadsheet_id() {
		$value = Settings::get( 'spreadsheet_id', '' );
		$list  = $this->cached_spreadsheets();

		// Anything other than a non-empty list falls back to the text box, so the
		// page is never a dead end — not connected, missing Drive permission, an
		// API error, or simply no spreadsheets in the account.
		if ( is_wp_error( $list ) || empty( $list ) ) {
			$this->render_spreadsheet_id_input( $value );

			if ( is_wp_error( $list ) ) {
				echo '<p class="description" style="color:#b32d2e;">' . esc_html( $list->get_error_message() ) . '</p>';

				// A scope problem is only fixed by reconnecting, so offer it here —
				// but only after showing what the token actually holds, because if
				// the Drive scope IS present the problem is not the connection.
				if ( 'wtg_drive_scope_missing' === $list->get_error_code() ) {
					$this->render_granted_scopes();

					printf(
						'<p><a class="button" href="%1$s">%2$s</a></p>',
						esc_url( OAuth_Controller::disconnect_url() ),
						esc_html__( 'Disconnect so you can reconnect', 'woo-to-gsheet' )
					);
				}
			} else {
				echo '<p class="description">' . esc_html__( 'No spreadsheets found in this Google account. Create one, then click Refresh.', 'woo-to-gsheet' ) . '</p>';
			}

			return;
		}

		printf( '<select id="wtg_spreadsheet_id" name="%s[spreadsheet_id]" class="regular-text">', esc_attr( Settings::OPTION_KEY ) );
		printf( '<option value="">%s</option>', esc_html__( '— Select a spreadsheet —', 'woo-to-gsheet' ) );

		$known = false;
		foreach ( $list as $file ) {
			if ( $file['id'] === $value ) {
				$known = true;
			}
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $file['id'] ),
				selected( $file['id'], $value, false ),
				esc_html( $file['name'] )
			);
		}

		// A saved sheet that is not in the list — typically shared by link rather
		// than owned — is kept as an option so re-saving cannot silently lose it.
		if ( '' !== $value && ! $known ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $value ),
				/* translators: %s: spreadsheet ID. */
				esc_html( sprintf( __( 'Currently set: %s', 'woo-to-gsheet' ), $value ) )
			);
		}

		echo '</select>';
		$this->render_refresh_link();

		echo '<p class="description">' . esc_html__( 'Spreadsheets in the connected Google account. The list is cached for a few minutes.', 'woo-to-gsheet' ) . '</p>';
	}

	/**
	 * The plain Spreadsheet ID text box, used whenever the list is unavailable.
	 *
	 * @param string $value Current value.
	 * @return void
	 */
	private function render_spreadsheet_id_input( $value ) {
		printf(
			'<input type="text" id="wtg_spreadsheet_id" name="%1$s[spreadsheet_id]" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $value )
		);
		// The string intentionally contains <strong> to highlight which part of the
		// URL to copy, so it CANNOT go through esc_html__() — that would escape the
		// tags and print them as literal text. wp_kses() escapes everything except
		// the one tag we allow, which keeps the markup working without trusting the
		// translation blindly.
		echo '<p class="description">' . wp_kses(
			__( 'The long ID from your sheet URL: docs.google.com/spreadsheets/d/<strong>THIS_PART</strong>/edit', 'woo-to-gsheet' ),
			array( 'strong' => array() )
		) . '</p>';
	}

	/**
	 * Sheet Name / tab (plain text).
	 *
	 * @return void
	 */
	public function render_field_sheet_name() {
		$value          = Settings::get( 'sheet_name', 'Sheet1' );
		$spreadsheet_id = Settings::get( 'spreadsheet_id', '' );

		// The tab list can only be fetched for a spreadsheet we already have saved,
		// so on first setup this is a two-step flow: choose a spreadsheet, Save,
		// and the tabs appear.
		if ( '' === $spreadsheet_id ) {
			$this->render_sheet_name_input( $value );
			echo '<p class="description">' . esc_html__( 'Choose a spreadsheet above and click Save Changes — the tab list will then load here.', 'woo-to-gsheet' ) . '</p>';
			return;
		}

		$titles = $this->cached_sheet_titles( $spreadsheet_id );

		if ( is_wp_error( $titles ) || empty( $titles ) ) {
			$this->render_sheet_name_input( $value );

			if ( is_wp_error( $titles ) ) {
				echo '<p class="description" style="color:#b32d2e;">' . esc_html( $titles->get_error_message() ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'No tabs found in that spreadsheet. Check the Spreadsheet ID, then click Refresh.', 'woo-to-gsheet' ) . '</p>';
			}

			return;
		}

		printf( '<select id="wtg_sheet_name" name="%s[sheet_name]" class="regular-text">', esc_attr( Settings::OPTION_KEY ) );

		$known = false;
		foreach ( $titles as $title ) {
			if ( $title === $value ) {
				$known = true;
			}
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $title ),
				selected( $title, $value, false )
			);
		}

		// Keep a saved tab name that no longer exists — renamed or deleted — rather
		// than silently switching the sync to a different tab on the next save.
		if ( '' !== $value && ! $known ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $value ),
				/* translators: %s: saved tab name that no longer exists. */
				esc_html( sprintf( __( '%s (not found in this spreadsheet)', 'woo-to-gsheet' ), $value ) )
			);
		}

		echo '</select>';
		$this->render_refresh_link();

		echo '<p class="description">' . esc_html__( 'Tabs in the selected spreadsheet. Rows are written to this tab.', 'woo-to-gsheet' ) . '</p>';
	}

	/**
	 * The plain Sheet Name text box, used whenever the tab list is unavailable.
	 *
	 * @param string $value Current value.
	 * @return void
	 */
	private function render_sheet_name_input( $value ) {
		printf(
			'<input type="text" id="wtg_sheet_name" name="%1$s[sheet_name]" value="%2$s" class="regular-text" />',
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $value )
		);
	}

	/* -------------------------------------------------------------------------
	 * Page + tabs.
	 * ---------------------------------------------------------------------- */

	/**
	 * Render the whole settings screen (tabs + active tab body).
	 *
	 * @return void
	 */
	public function render_page() {
		// Defense in depth: add_menu_page already gated on this capability,
		// but we re-check before rendering anything.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Which tab? Only used to switch what we display (no state change), so a
		// nonce is unnecessary; sanitize_key keeps it to a safe [a-z0-9_] slug.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';
		if ( ! in_array( $active_tab, array( 'connection', 'fields', 'status_tabs', 'sync_log' ), true ) ) {
			$active_tab = 'connection';
		}

		$base_url = menu_page_url( self::MENU_SLUG, false );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'connection', $base_url ) ); ?>"
					class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Connection', 'woo-to-gsheet' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'fields', $base_url ) ); ?>"
					class="nav-tab <?php echo 'fields' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Fields', 'woo-to-gsheet' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'status_tabs', $base_url ) ); ?>"
					class="nav-tab <?php echo 'status_tabs' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Status Tabs', 'woo-to-gsheet' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'sync_log', $base_url ) ); ?>"
					class="nav-tab <?php echo 'sync_log' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Sync Log', 'woo-to-gsheet' ); ?>
				</a>
			</h2>

			<?php
			if ( 'sync_log' === $active_tab ) {
				$this->render_sync_log_tab();
			} elseif ( 'fields' === $active_tab ) {
				$this->render_fields_tab();
			} elseif ( 'status_tabs' === $active_tab ) {
				$this->render_status_tabs_tab();
			} else {
				$this->render_connection_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The Connection tab: the Settings API form.
	 *
	 * @return void
	 */
	private function render_connection_tab() {
		// Show the "Settings saved." notice after a successful options.php save.
		settings_errors();

		// Connection state + Connect/Disconnect/Test buttons sit above the form.
		$this->render_connection_status();
		?>
		<form action="options.php" method="post">
			<?php
			// Outputs the hidden option_page, nonce and referer fields tying this
			// form to our registered group.
			settings_fields( self::SETTINGS_GROUP );

			// Tells sanitize() this is the Connection form, so it leaves the
			// column selection alone. See the FORM_MARKER docblock.
			printf(
				'<input type="hidden" name="%1$s[%2$s]" value="connection" />',
				esc_attr( Settings::OPTION_KEY ),
				esc_attr( self::FORM_MARKER )
			);

			// Prints the registered section + fields for this page slug.
			do_settings_sections( self::MENU_SLUG );

			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * The Fields tab: choose which columns go to the sheet, and rename them.
	 *
	 * Rendered by hand rather than through the Settings API, because the API is
	 * awkward for a repeating checklist. It still posts to options.php under the
	 * same registered group, so nonce and capability handling are unchanged.
	 *
	 * @return void
	 */
	private function render_fields_tab() {
		settings_errors();

		$registry = Order_Mapper::fields();
		$selected = Order_Mapper::selected_keys();
		$labels   = Settings::get( 'field_labels', array() );
		if ( ! is_array( $labels ) ) {
			$labels = array();
		}
		?>
		<p><?php esc_html_e( 'Choose which columns are written to your Google Sheet, and what each one is called. Columns always appear in the order listed below.', 'woo-to-gsheet' ); ?></p>

		<form action="options.php" method="post">
			<?php
			settings_fields( self::SETTINGS_GROUP );
			printf(
				'<input type="hidden" name="%1$s[%2$s]" value="fields" />',
				esc_attr( Settings::OPTION_KEY ),
				esc_attr( self::FORM_MARKER )
			);
			?>

			<table class="widefat striped" style="max-width:820px;margin-top:1em;">
				<thead>
					<tr>
						<th style="width:90px;"><?php esc_html_e( 'Include', 'woo-to-gsheet' ); ?></th>
						<th style="width:60px;"><?php esc_html_e( 'Column', 'woo-to-gsheet' ); ?></th>
						<th><?php esc_html_e( 'Field', 'woo-to-gsheet' ); ?></th>
						<th><?php esc_html_e( 'Heading in the sheet', 'woo-to-gsheet' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				// The spreadsheet letter each INCLUDED field will land in. Counted
				// only over selected fields, so deselecting one shifts the rest up —
				// exactly what the sheet will look like after saving.
				$column_index = 0;

				foreach ( $registry as $key => $field ) :
					$is_on     = in_array( $key, $selected, true );
					$is_locked = Order_Mapper::is_locked( $key );
					$letter    = $is_on ? $this->column_letter( $column_index++ ) : '—';
					$label     = isset( $labels[ $key ] ) ? $labels[ $key ] : $field['label'];
					?>
					<tr>
						<td>
							<input type="checkbox"
								id="wtg_field_<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[fields][]"
								value="<?php echo esc_attr( $key ); ?>"
								<?php checked( $is_on ); ?>
								<?php disabled( $is_locked ); ?> />
							<?php if ( $is_locked ) : ?>
								<?php // A disabled checkbox is not submitted, so post it separately. ?>
								<input type="hidden"
									name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[fields][]"
									value="<?php echo esc_attr( $key ); ?>" />
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $letter ); ?></code></td>
						<td>
							<label for="wtg_field_<?php echo esc_attr( $key ); ?>">
								<?php echo esc_html( $field['label'] ); ?>
							</label>
							<?php if ( ! empty( $field['per_item'] ) ) : ?>
								<span class="description"> &mdash; <?php esc_html_e( 'per product', 'woo-to-gsheet' ); ?></span>
							<?php endif; ?>
							<?php if ( $is_locked ) : ?>
								<span class="description"> &mdash; <?php esc_html_e( 'required', 'woo-to-gsheet' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<input type="text"
								name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[field_labels][<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $label ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $field['label'] ); ?>" />
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:1em;max-width:820px;">
				<strong><?php esc_html_e( 'Order ID is required.', 'woo-to-gsheet' ); ?></strong>
				<?php esc_html_e( 'The plugin finds an order in your sheet by looking up its ID in column A, which is how a status change updates the existing rows instead of adding duplicates.', 'woo-to-gsheet' ); ?>
			</p>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Product, Quantity and Unit Price are the only fields that differ between the products in one order. If you include none of them, each order is written as a single row instead of one row per product.', 'woo-to-gsheet' ); ?>
			</p>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'After changing this, click "Write Header Row" on the Connection tab. Rows already in your sheet keep the old layout.', 'woo-to-gsheet' ); ?>
			</p>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * 0 => A, 25 => Z, 26 => AA. Used to preview which column each field lands in.
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

	/**
	 * The Status Tabs tab: give each order status its own tab in the spreadsheet.
	 *
	 * Hand-rendered for the same reason as the Fields tab — the Settings API is
	 * awkward for a repeating checklist — and posts to options.php under the same
	 * registered group, so nonce and capability handling are unchanged.
	 *
	 * @return void
	 */
	private function render_status_tabs_tab() {
		settings_errors();

		$enabled   = Status_Tabs::is_enabled();
		$available = Status_Tabs::available_statuses();
		$tracked   = Status_Tabs::tracked_statuses();
		$master    = trim( (string) Settings::get( 'sheet_name', 'Sheet1' ) );

		$names = Settings::get( Status_Tabs::SETTING_NAMES, array() );
		if ( ! is_array( $names ) ) {
			$names = array();
		}
		?>
		<p style="max-width:820px;">
			<?php esc_html_e( 'Give each order status its own tab in the spreadsheet. An order is written to the tab for its current status, and when its status changes the plugin moves the row: it is added to the new tab first, then removed from the old one.', 'woo-to-gsheet' ); ?>
		</p>
		<p class="description" style="max-width:820px;">
			<?php
			printf(
				/* translators: %s: the main tab name from the Connection tab. */
				esc_html__( 'Your main tab (%s) keeps working exactly as it does today — every order still goes there. Status tabs are in addition to it, not instead of it.', 'woo-to-gsheet' ),
				'<strong>' . esc_html( $master ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
			?>
		</p>

		<?php if ( empty( $available ) ) : ?>
			<div class="notice notice-warning inline" style="margin:1em 0;max-width:820px;">
				<p><?php esc_html_e( 'WooCommerce is not active, so the list of order statuses cannot be loaded. Activate WooCommerce to configure this feature.', 'woo-to-gsheet' ); ?></p>
			</div>
		<?php endif; ?>

		<form action="options.php" method="post">
			<?php
			settings_fields( self::SETTINGS_GROUP );
			printf(
				'<input type="hidden" name="%1$s[%2$s]" value="status_tabs" />',
				esc_attr( Settings::OPTION_KEY ),
				esc_attr( self::FORM_MARKER )
			);
			?>

			<p style="margin-top:1.5em;">
				<label>
					<input type="checkbox"
						name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( Status_Tabs::SETTING_ENABLED ); ?>]"
						value="1"
						<?php checked( $enabled ); ?> />
					<strong><?php esc_html_e( 'Write orders to a tab per order status', 'woo-to-gsheet' ); ?></strong>
				</label>
			</p>

			<?php if ( ! empty( $available ) ) : ?>
				<table class="widefat striped" style="max-width:820px;margin-top:1em;">
					<thead>
						<tr>
							<th style="width:90px;"><?php esc_html_e( 'Include', 'woo-to-gsheet' ); ?></th>
							<th><?php esc_html_e( 'Order status', 'woo-to-gsheet' ); ?></th>
							<th><?php esc_html_e( 'Tab name', 'woo-to-gsheet' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $available as $slug => $label ) :
						$is_on = in_array( $slug, $tracked, true );

						// What the tab would actually be called: the admin's override if
						// they set one, otherwise WooCommerce's own label. The input is
						// left EMPTY when there is no override, so the placeholder shows
						// the default and clearing the box means "go back to the default".
						$override  = isset( $names[ $slug ] ) ? (string) $names[ $slug ] : '';
						$effective = '' !== trim( $override ) ? trim( $override ) : $label;

						// Same case-insensitive comparison Status_Tabs uses to protect the
						// master tab. This row is why the check is worth surfacing: the
						// sync silently refuses such a name, and without this note the
						// admin would just see a tab that never appears.
						$clashes = ( '' !== $master && 0 === strcasecmp( $effective, $master ) );
						?>
						<tr>
							<td>
								<input type="checkbox"
									id="wtg_status_tab_<?php echo esc_attr( $slug ); ?>"
									name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( Status_Tabs::SETTING_STATUSES ); ?>][]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( $is_on ); ?> />
							</td>
							<td>
								<label for="wtg_status_tab_<?php echo esc_attr( $slug ); ?>">
									<?php echo esc_html( $label ); ?>
								</label>
								<code style="margin-left:.5em;"><?php echo esc_html( $slug ); ?></code>
							</td>
							<td>
								<input type="text"
									name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( Status_Tabs::SETTING_NAMES ); ?>][<?php echo esc_attr( $slug ); ?>]"
									value="<?php echo esc_attr( $override ); ?>"
									class="regular-text"
									placeholder="<?php echo esc_attr( $label ); ?>" />
								<?php if ( $clashes ) : ?>
									<p class="description" style="color:#b32d2e;margin-bottom:0;">
										<?php
										printf(
											/* translators: %s: the main tab name. */
											esc_html__( 'This is the same as your main tab (%s), so this status will not get a tab — its orders stay on the main tab only. Choose a different name.', 'woo-to-gsheet' ),
											esc_html( $master )
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top:1em;max-width:820px;">
					<?php esc_html_e( 'Leave a tab name blank to use WooCommerce\'s own wording for that status. Tabs are created in your spreadsheet the first time an order reaches that status — nothing is created just by saving this page.', 'woo-to-gsheet' ); ?>
				</p>
				<p class="description" style="max-width:820px;">
					<?php esc_html_e( 'With every status ticked, any new status added later by WooCommerce or another plugin gets a tab automatically. Untick them all to switch the feature off.', 'woo-to-gsheet' ); ?>
				</p>
				<p class="description" style="max-width:820px;">
					<?php esc_html_e( 'Draft and trashed orders are not listed, because they are never synced to the sheet in the first place.', 'woo-to-gsheet' ); ?>
				</p>
			<?php endif; ?>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * The "is this thing on?" panel at the top of the Sync Log.
	 *
	 * The counts below it say what happened to orders. This says whether the
	 * automation itself is running — which is the question a shop owner actually
	 * has, and the one that used to be unanswerable without pressing Process
	 * Queue Now and seeing whether anything moved.
	 *
	 * @return void
	 */
	private function render_sync_health() {
		$last    = Sync_Runner::last_run();
		$waiting = Sync_Queue::oldest_pending_age();

		if ( $waiting >= self::STALLED_AFTER ) {
			// The full explanation is already on screen via the admin notice, so
			// this stays short and does not repeat it.
			$state = 'notice-error';
			$text  = sprintf(
				/* translators: %s: human-readable duration. */
				__( 'Stalled — the oldest order has been waiting %s.', 'woo-to-gsheet' ),
				human_time_diff( time() - $waiting, time() )
			);
		} elseif ( $last > 0 ) {
			$text = sprintf(
				/* translators: %s: human-readable duration, e.g. "3 mins". */
				__( 'Running normally. Last automatic sync: %s ago.', 'woo-to-gsheet' ),
				human_time_diff( $last, time() )
			);

			$state = 'notice-success';
		} else {
			// No run has ever completed. Not an error on a fresh install — there
			// may simply have been no orders yet — so this is neutral, not alarming.
			$state = 'notice-info';
			$text  = __( 'No automatic sync has run yet. It starts by itself as soon as an order is placed or changes status.', 'woo-to-gsheet' );
		}

		printf(
			'<div class="notice %1$s inline" style="margin:1em 0;"><p>%2$s</p></div>',
			esc_attr( $state ),
			esc_html( $text )
		);
	}

	/**
	 * The Sync Log tab: placeholder until Phase 6.
	 *
	 * @return void
	 */
	private function render_sync_log_tab() {
		settings_errors();

		$this->render_sync_health();

		// Status summary + manual trigger.
		// One query for all four counts.
		$counts     = Sync_Queue::count_by_status();
		$pending    = $counts[ Sync_Queue::STATUS_PENDING ];
		$processing = $counts[ Sync_Queue::STATUS_PROCESSING ];
		$success    = $counts[ Sync_Queue::STATUS_SUCCESS ];
		$failed     = $counts[ Sync_Queue::STATUS_FAILED ];

		echo '<p style="margin:1em 0;">';
		printf(
			/* translators: 1: pending, 2: processing, 3: success, 4: failed counts. */
			esc_html__( 'Pending: %1$d | Processing: %2$d | Success: %3$d | Failed: %4$d', 'woo-to-gsheet' ),
			(int) $pending,
			(int) $processing,
			(int) $success,
			(int) $failed
		);
		echo '</p>';

		echo '<p>';

		printf(
			'<a href="%1$s" class="button button-primary">%2$s</a>',
			esc_url( Queue_Controller::process_now_url() ),
			esc_html__( 'Process Queue Now', 'woo-to-gsheet' )
		);

		// Only offer Clear Log when there is something clearable, so the button is
		// never a no-op. Pending/processing rows are excluded from both the count
		// and the deletion — they are orders still waiting to reach the sheet.
		$clearable = (int) $success + (int) $failed;
		if ( $clearable > 0 ) {
			printf(
				' <a href="%1$s" class="button" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( Queue_Controller::clear_log_url() ),
				// esc_js + quotes: this string sits inside a JS confirm() call.
				"'" . esc_js(
					sprintf(
						/* translators: %d: number of rows that will be deleted. */
						_n(
							'Delete %d finished entry from the log? This cannot be undone. Your Google Sheet is not affected.',
							'Delete %d finished entries from the log? This cannot be undone. Your Google Sheet is not affected.',
							$clearable,
							'woo-to-gsheet'
						),
						$clearable
					)
				) . "'",
				esc_html__( 'Clear Log', 'woo-to-gsheet' )
			);
		}

		echo '</p>';

		$rows = Sync_Queue::get_rows( 100 );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'The queue is empty. Orders appear here once they reach processing or completed.', 'woo-to-gsheet' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'ID', 'Order', 'Status', 'Attempts', 'Last Error', 'Updated', 'Action' ) as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->id ) . '</td>';
			echo '<td>' . esc_html( $row->order_id ) . '</td>';
			echo '<td>' . esc_html( $row->status ) . '</td>';
			echo '<td>' . esc_html( $row->attempts ) . '</td>';
			echo '<td>' . esc_html( (string) $row->last_error ) . '</td>';
			echo '<td>' . esc_html( $row->updated_at ) . '</td>';
			echo '<td>';
			// Only failed rows offer a Retry link.
			if ( Sync_Queue::STATUS_FAILED === $row->status ) {
				printf(
					'<a href="%1$s">%2$s</a>',
					esc_url( Queue_Controller::retry_url( $row->id ) ),
					esc_html__( 'Retry', 'woo-to-gsheet' )
				);
			} else {
				echo '&mdash;';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the connection status panel with Connect/Disconnect/Test buttons.
	 *
	 * The button targets are nonced admin-post URLs built by OAuth_Controller,
	 * so the OAuth logic stays entirely in the controller — this method only
	 * decides WHICH buttons to show based on current state.
	 *
	 * @return void
	 */
	private function render_connection_status() {
		$oauth = new OAuth_Client();

		echo '<div class="wtg-connection-status" style="margin:1em 0;padding:12px 16px;border:1px solid #ccd0d4;background:#fff;border-radius:4px;">';

		if ( $oauth->is_connected() ) {
			echo '<p style="margin-top:0;"><span style="color:#46b450;font-weight:600;">&#9679; ' . esc_html__( 'Connected', 'woo-to-gsheet' ) . '</span> &mdash; ' . esc_html__( 'a Google account is authorized.', 'woo-to-gsheet' ) . '</p>';
			printf(
				'<a href="%1$s" class="button button-secondary">%2$s</a> ',
				esc_url( OAuth_Controller::test_url() ),
				esc_html__( 'Test Connection', 'woo-to-gsheet' )
			);
			// Writes the column labels into row 1 so the sheet always matches what
			// the sync sends. Safe to click repeatedly; it refuses if row 1 holds
			// order data. Only offered once connected, since it calls the API.
			printf(
				'<a href="%1$s" class="button button-secondary">%2$s</a> ',
				esc_url( OAuth_Controller::write_header_url() ),
				esc_html__( 'Write Header Row', 'woo-to-gsheet' )
			);
			printf(
				'<a href="%1$s" class="button button-link-delete">%2$s</a>',
				esc_url( OAuth_Controller::disconnect_url() ),
				esc_html__( 'Disconnect', 'woo-to-gsheet' )
			);
		} else {
			echo '<p style="margin-top:0;"><span style="color:#dc3232;font-weight:600;">&#9679; ' . esc_html__( 'Not connected', 'woo-to-gsheet' ) . '</span></p>';
			if ( $oauth->has_credentials() ) {
				printf(
					'<a href="%1$s" class="button button-primary">%2$s</a>',
					esc_url( OAuth_Controller::connect_url() ),
					esc_html__( 'Connect Google Account', 'woo-to-gsheet' )
				);
			} else {
				echo '<p class="description" style="margin-bottom:0;">' . esc_html__( 'Enter your Client ID and Client Secret below and click Save Changes. A Connect button will then appear here.', 'woo-to-gsheet' ) . '</p>';
			}
		}

		echo '</div>';
	}
}
