<?php
/**
 * Decides which sheet tab an order belongs on, based on its status.
 *
 * All the POLICY of the per-status-tab feature lives here: whether it is on,
 * which statuses earn a tab, what those tabs are called, and making sure a tab
 * exists (with a header row) before anything is written to it.
 *
 * Deliberately split in two halves:
 *
 *  - STATIC methods answer naming questions from Settings alone. They make no
 *    HTTP calls, so the settings page can ask "what tabs would you create?"
 *    without touching Google.
 *  - INSTANCE methods resolve a tab name to a real sheet, creating it if needed.
 *    An instance caches the spreadsheet's tab map, so a whole batch of orders
 *    costs ONE lookup rather than one per order.
 *
 * @package WTG
 */

namespace WTG\Sheets;

use WTG\Settings;
use WTG\Google\Sheets_Client;
use WTG\WooCommerce\Order_Mapper;
use WTG\WooCommerce\Order_Listener;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Status_Tabs
 */
class Status_Tabs {

	/**
	 * Settings keys this feature owns. Named as constants so the settings page
	 * and the sanitizer cannot drift out of step with the readers here.
	 */
	const SETTING_ENABLED  = 'status_tabs_enabled';
	const SETTING_STATUSES = 'status_tab_statuses';
	const SETTING_NAMES    = 'status_tab_names';

	/**
	 * The spreadsheet being written to.
	 *
	 * @var string
	 */
	private $spreadsheet_id;

	/**
	 * Sheets client used for the map/create calls.
	 *
	 * @var Sheets_Client
	 */
	private $sheets;

	/**
	 * Valid OAuth access token for this batch.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Title => sheetId for every tab in the spreadsheet, or null until loaded.
	 *
	 * Null rather than an empty array as the "not loaded yet" marker, because an
	 * empty array is a legitimate (if odd) answer and must not trigger a reload.
	 *
	 * @var array|null
	 */
	private $map = null;

	/**
	 * @param Sheets_Client $sheets         Client to use.
	 * @param string        $spreadsheet_id Target spreadsheet.
	 * @param string        $token          Valid access token.
	 */
	public function __construct( Sheets_Client $sheets, $spreadsheet_id, $token ) {
		$this->sheets         = $sheets;
		$this->spreadsheet_id = (string) $spreadsheet_id;
		$this->token          = (string) $token;
	}

	/* ---------------------------------------------------------------------- *
	 * Naming policy (static, no HTTP).
	 * ---------------------------------------------------------------------- */

	/**
	 * Is the per-status-tab feature switched on?
	 *
	 * Defaults to FALSE so existing installs are completely unaffected until the
	 * admin opts in — no tabs appear and no rows move on upgrade.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Settings::get( self::SETTING_ENABLED, false );
	}

	/**
	 * Every order status that could have a tab: slug => default tab name.
	 *
	 * Read from WooCommerce rather than hard-coded, so custom statuses added by
	 * other plugins appear here too. wc_get_order_statuses() returns keys with a
	 * "wc-" prefix ("wc-completed") while WC_Order::get_status() returns them
	 * WITHOUT it ("completed") — everything in this plugin uses the unprefixed
	 * form, so the prefix is stripped once, here.
	 *
	 * The same statuses Order_Listener refuses to sync are skipped, so we can
	 * never create a tab for an order shell that will never be written.
	 *
	 * @return array Slug => human label.
	 */
	public static function available_statuses() {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$out = array();

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$slug = preg_replace( '/^wc-/', '', $slug );

			if ( in_array( $slug, Order_Listener::EXCLUDED_STATUSES, true ) ) {
				continue;
			}

			$out[ $slug ] = $label;
		}

		return $out;
	}

	/**
	 * The status slugs that actually get their own tab.
	 *
	 * An empty stored selection means "all of them", mirroring how the Fields tab
	 * treats an empty field list. That way enabling the feature does something
	 * sensible immediately, and a site that never edits the list keeps working
	 * when WooCommerce or a plugin adds a new status later.
	 *
	 * @return string[]
	 */
	public static function tracked_statuses() {
		$available = array_keys( self::available_statuses() );
		$stored    = Settings::get( self::SETTING_STATUSES, array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return $available;
		}

		// Walk the AVAILABLE list and filter by the stored selection, so a stale
		// slug left over from a removed plugin is ignored automatically. Same
		// direction of travel as Order_Mapper::selected_keys(), for the same reason.
		return array_values( array_intersect( $available, $stored ) );
	}

	/**
	 * The tab name for a status, or '' when that status is not routed.
	 *
	 * Returns '' in three cases, all of which mean "leave this order on the master
	 * tab only": the feature is off, the status is not tracked, or the resulting
	 * name would collide with the master tab.
	 *
	 * That last case is a SAFETY rule, not a nicety. If a site's master tab is
	 * called "Completed" and a Completed status tab were also allowed, they would
	 * be the same sheet — and the move logic, which deletes an order's rows from
	 * every tab that is not its target, would delete rows from the master. Refusing
	 * the name makes that impossible. The settings page warns about the clash.
	 *
	 * @param string $status Unprefixed status slug, e.g. "completed".
	 * @return string Tab name, or '' if this status is not routed.
	 */
	public static function tab_name_for( $status ) {
		$status = (string) $status;

		if ( ! self::is_enabled() || '' === $status ) {
			return '';
		}

		if ( ! in_array( $status, self::tracked_statuses(), true ) ) {
			return '';
		}

		$name = self::raw_name_for( $status );

		if ( '' === $name || self::is_master_tab( $name ) ) {
			return '';
		}

		return $name;
	}

	/**
	 * Every tab name this feature owns, for the tracked statuses.
	 *
	 * This is the list the processor searches when asking "where does this order
	 * currently live?", so it must never contain the master tab — see
	 * tab_name_for(). Duplicates are collapsed, since two statuses given the same
	 * custom name would otherwise be searched (and deleted from) twice.
	 *
	 * @return string[]
	 */
	public static function tab_names() {
		if ( ! self::is_enabled() ) {
			return array();
		}

		$names = array();

		foreach ( self::tracked_statuses() as $status ) {
			$name = self::tab_name_for( $status );
			if ( '' !== $name ) {
				$names[ $name ] = $name; // Keyed, so duplicates collapse.
			}
		}

		return array_values( $names );
	}

	/**
	 * Statuses whose tab name clashes with the master tab, slug => name.
	 *
	 * Nothing in the sync path uses this — tab_name_for() already refuses those
	 * names. It exists purely so the settings page can TELL the admin why their
	 * "Completed" tab is not being used, instead of leaving them to wonder.
	 *
	 * @return array Slug => the clashing name.
	 */
	public static function master_conflicts() {
		$out = array();

		foreach ( self::tracked_statuses() as $status ) {
			$name = self::raw_name_for( $status );

			if ( '' !== $name && self::is_master_tab( $name ) ) {
				$out[ $status ] = $name;
			}
		}

		return $out;
	}

	/**
	 * The configured name for a status, ignoring tracking and collision rules.
	 *
	 * Order of preference: the admin's override, then WooCommerce's own label for
	 * the status ("On hold", "Completed"), then a tidied-up slug as a last resort
	 * for when WooCommerce is not loaded.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function raw_name_for( $status ) {
		$overrides = Settings::get( self::SETTING_NAMES, array() );

		if ( is_array( $overrides ) && isset( $overrides[ $status ] ) && '' !== trim( (string) $overrides[ $status ] ) ) {
			return trim( (string) $overrides[ $status ] );
		}

		$available = self::available_statuses();
		if ( isset( $available[ $status ] ) ) {
			return (string) $available[ $status ];
		}

		return ucfirst( str_replace( '-', ' ', $status ) );
	}

	/**
	 * Is this name the master tab?
	 *
	 * Compared case-INSENSITIVELY: Google treats "Completed" and "completed" as
	 * different tabs, but a human who typed the wrong case still means the same
	 * sheet, and being wrong here is destructive. Erring toward "yes, it's the
	 * master" only ever costs a status its tab.
	 *
	 * @param string $name Candidate tab name.
	 * @return bool
	 */
	private static function is_master_tab( $name ) {
		$master = trim( (string) Settings::get( 'sheet_name', 'Sheet1' ) );

		return '' !== $master && 0 === strcasecmp( trim( (string) $name ), $master );
	}

	/* ---------------------------------------------------------------------- *
	 * Sheet resolution (instance, makes HTTP calls).
	 * ---------------------------------------------------------------------- */

	/**
	 * Which of our status tabs actually exist in the spreadsheet right now.
	 *
	 * The processor searches only these. Asking Google to read a range on a tab
	 * that does not exist is an ERROR for the whole batchGet request, so a tab we
	 * have not created yet must be filtered out rather than searched hopefully.
	 *
	 * @return array|\WP_Error List of tab names.
	 */
	public function existing_tab_names() {
		$map = $this->map();

		if ( is_wp_error( $map ) ) {
			return $map;
		}

		$existing = array();

		foreach ( self::tab_names() as $name ) {
			if ( isset( $map[ $name ] ) ) {
				$existing[] = $name;
			}
		}

		return $existing;
	}

	/**
	 * The numeric sheet ID for a tab name, creating the tab if it is missing.
	 *
	 * Needed because deleting rows addresses a tab by ID, not by name.
	 *
	 * @param string $name Tab name.
	 * @return int|\WP_Error
	 */
	public function sheet_id_for( $name ) {
		$name = trim( (string) $name );

		// Belt and braces. tab_name_for() already refuses the master tab, but this
		// method is what hands out the ID used for DELETING rows, so it re-checks
		// rather than trusting its caller.
		if ( '' === $name || self::is_master_tab( $name ) ) {
			return new \WP_Error(
				'wtg_invalid_status_tab',
				__( 'Refusing to treat the main sheet tab as a status tab.', 'woo-to-gsheet' )
			);
		}

		$map = $this->map();

		if ( is_wp_error( $map ) ) {
			return $map;
		}

		if ( isset( $map[ $name ] ) ) {
			return $map[ $name ];
		}

		return $this->create( $name );
	}

	/**
	 * Create a tab and give it a header row.
	 *
	 * @param string $name Tab name.
	 * @return int|\WP_Error New sheetId.
	 */
	private function create( $name ) {
		$sheet_id = $this->sheets->create_sheet( $this->spreadsheet_id, $name, $this->token );

		if ( is_wp_error( $sheet_id ) ) {
			// Two cron runs can race to create the same tab for the first order to
			// reach a status. The loser gets "already exists", which is a success in
			// disguise — re-read the map and use the tab the winner made.
			if ( 'wtg_sheet_exists' === $sheet_id->get_error_code() ) {
				$this->map = null; // Force a fresh read.
				$map       = $this->map();

				if ( is_wp_error( $map ) ) {
					return $map;
				}

				if ( isset( $map[ $name ] ) ) {
					return $map[ $name ];
				}
			}

			return $sheet_id;
		}

		// Record it immediately so the rest of the batch does not re-create it.
		$this->map[ $name ] = $sheet_id;

		// The tab is brand new and empty, so an append lands in row 1 — no need to
		// check what is there first, unlike OAuth_Controller::write_header_row().
		//
		// A failure here is deliberately IGNORED: the tab exists and the order data
		// still belongs in it, and a missing header is cosmetic — the next save on
		// the settings page rewrites row 1 of every existing tab, this one included
		// (OAuth_Controller::write_header_row()). Failing the whole sync over a
		// heading would be worse.
		$header = Order_Mapper::header();

		if ( ! empty( $header ) ) {
			$this->sheets->append_rows( $this->spreadsheet_id, $name, array( $header ), $this->token );
		}

		return $sheet_id;
	}

	/**
	 * The spreadsheet's tab map, fetched at most once per instance.
	 *
	 * @return array|\WP_Error Title => sheetId.
	 */
	private function map() {
		if ( null === $this->map ) {
			$map = $this->sheets->get_sheet_map( $this->spreadsheet_id, $this->token );

			if ( is_wp_error( $map ) ) {
				return $map; // Not cached, so the next call retries.
			}

			$this->map = $map;
		}

		return $this->map;
	}
}
