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
use WTG\Queue\Sync_Queue;

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
	 * Register the admin hooks. Called from Plugin::run() (in admin context).
	 *
	 * @return void
	 */
	public function hooks() {
		// admin_menu: add our page to the menu. admin_init: register the setting
		// and its fields. Both only ever fire inside wp-admin.
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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

		// Plain text fields.
		$output['client_id']      = isset( $input['client_id'] ) ? sanitize_text_field( $input['client_id'] ) : '';
		$output['spreadsheet_id'] = isset( $input['spreadsheet_id'] ) ? sanitize_text_field( $input['spreadsheet_id'] ) : '';

		// Sheet name: fall back to a sensible default if cleared.
		$sheet = isset( $input['sheet_name'] ) ? sanitize_text_field( $input['sheet_name'] ) : '';
		$output['sheet_name'] = '' !== $sheet ? $sheet : 'Sheet1';

		// Client Secret: the field renders blank for security, so a blank
		// submission means "keep the existing secret", not "erase it".
		$submitted_secret = isset( $input['client_secret'] ) ? trim( $input['client_secret'] ) : '';
		if ( '' !== $submitted_secret ) {
			$output['client_secret'] = sanitize_text_field( $submitted_secret );
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
		$value = Settings::get( 'sheet_name', 'Sheet1' );
		printf(
			'<input type="text" id="wtg_sheet_name" name="%1$s[sheet_name]" value="%2$s" class="regular-text" />',
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'The worksheet/tab name rows are appended to (e.g. Sheet1).', 'woo-to-gsheet' ) . '</p>';
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
		if ( ! in_array( $active_tab, array( 'connection', 'sync_log' ), true ) ) {
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
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'sync_log', $base_url ) ); ?>"
					class="nav-tab <?php echo 'sync_log' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Sync Log', 'woo-to-gsheet' ); ?>
				</a>
			</h2>

			<?php
			if ( 'sync_log' === $active_tab ) {
				$this->render_sync_log_tab();
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

			// Prints the registered section + fields for this page slug.
			do_settings_sections( self::MENU_SLUG );

			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * The Sync Log tab: placeholder until Phase 6.
	 *
	 * @return void
	 */
	private function render_sync_log_tab() {
		settings_errors();

		// Status summary + manual trigger.
		$pending    = Sync_Queue::count_by_status( Sync_Queue::STATUS_PENDING );
		$processing = Sync_Queue::count_by_status( Sync_Queue::STATUS_PROCESSING );
		$success    = Sync_Queue::count_by_status( Sync_Queue::STATUS_SUCCESS );
		$failed     = Sync_Queue::count_by_status( Sync_Queue::STATUS_FAILED );

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
