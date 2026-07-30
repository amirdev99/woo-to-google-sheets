<?php
/**
 * Converts a WooCommerce order into the rows we write to the sheet.
 *
 * Built around a FIELD REGISTRY: fields() declares every column the plugin can
 * produce, and everything else — the header labels, the row values, and the
 * checklist on the Fields tab — is derived from it. Add a field there once and
 * it appears in all three.
 *
 * Which fields are actually written, and what they are called, comes from
 * Settings. That makes this class configuration-aware (it reads Settings), where
 * it used to be a pure transformation.
 *
 * @package WTG
 */

namespace WTG\WooCommerce;

use WTG\Settings;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Mapper
 */
class Order_Mapper {

	/**
	 * Every field the plugin can write, in CANONICAL order.
	 *
	 * The order here is the order columns appear in the sheet. Users choose which
	 * fields to include and what to call them, but never their order — see
	 * selected_keys() for why that is deliberate.
	 *
	 * Each entry has:
	 *   label    - default column heading (users may override it).
	 *   per_item - true when the value differs between products in one order.
	 *              If NO selected field is per_item, an order collapses to a
	 *              single row instead of one row per product.
	 *   locked   - cannot be deselected. Only 'order_id' is locked, because
	 *              Sheets_Client::ORDER_ID_COLUMN is 'A' and the whole
	 *              update-in-place feature finds an order by searching column A.
	 *              A locked field must therefore stay FIRST in this array.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'order_id'    => array(
				'label'    => __( 'Order ID', 'woo-to-gsheet' ),
				'per_item' => false,
				'locked'   => true,
			),
			'date'        => array(
				'label'    => __( 'Date', 'woo-to-gsheet' ),
				'per_item' => false,
			),
			'status'      => array(
				'label'    => __( 'Status', 'woo-to-gsheet' ),
				'per_item' => false,
			),
			'customer'    => array(
				'label'    => __( 'Customer Name', 'woo-to-gsheet' ),
				'per_item' => false,
			),
			'email'       => array(
				'label'    => __( 'Email', 'woo-to-gsheet' ),
				'per_item' => false,
			),
			'phone'       => array(
				'label'    => __( 'Phone', 'woo-to-gsheet' ),
				'per_item' => false,
			),
			'product'     => array(
				'label'    => __( 'Product', 'woo-to-gsheet' ),
				'per_item' => true,
			),
			'quantity'    => array(
				'label'    => __( 'Quantity', 'woo-to-gsheet' ),
				'per_item' => true,
			),
			'unit_price'  => array(
				'label'    => __( 'Unit Price', 'woo-to-gsheet' ),
				'per_item' => true,
			),
			'order_total' => array(
				'label'    => __( 'Order Total', 'woo-to-gsheet' ),
				'per_item' => false,
			),
			'currency'    => array(
				'label'    => __( 'Currency', 'woo-to-gsheet' ),
				'per_item' => false,
			),
			'payment'     => array(
				'label'    => __( 'Payment Method', 'woo-to-gsheet' ),
				'per_item' => false,
			),
		);
	}

	/**
	 * Is this field one the user is not allowed to remove?
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	public static function is_locked( $key ) {
		$fields = self::fields();
		return ! empty( $fields[ $key ]['locked'] );
	}

	/**
	 * The field keys to write, in the order they appear in the sheet.
	 *
	 * Note the loop direction: we walk the CANONICAL list and filter it by the
	 * stored selection, rather than walking the stored list. That single choice
	 * gives us four things without extra validation:
	 *
	 *   1. Column order is always canonical, so reordering is impossible.
	 *   2. An unknown or stale key in settings is ignored automatically.
	 *   3. A locked field is always present, whatever settings say.
	 *   4. A field added in a future version simply is not selected on existing
	 *      installs — no migration needed.
	 *
	 * An empty stored value means "everything", so sites that never visit the
	 * Fields tab keep the original twelve columns.
	 *
	 * @return string[]
	 */
	public static function selected_keys() {
		$all    = array_keys( self::fields() );
		$stored = Settings::get( 'fields', array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return $all;
		}

		$keys = array();
		foreach ( $all as $key ) {
			if ( in_array( $key, $stored, true ) || self::is_locked( $key ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Does any selected field vary between the products of one order?
	 *
	 * When none do, every product would produce an identical row, so the order is
	 * written as a single row instead. That is the "one row per order" mode.
	 *
	 * @param array $keys Selected field keys.
	 * @return bool
	 */
	public static function has_per_item( array $keys ) {
		$fields = self::fields();

		foreach ( $keys as $key ) {
			if ( ! empty( $fields[ $key ]['per_item'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The column headings for the selected fields, user overrides applied.
	 *
	 * Used by the "Write Header Row" button, which is why the sheet's header can
	 * never disagree with the data written under it — both come from here.
	 *
	 * @return array
	 */
	public static function header() {
		$fields = self::fields();
		$labels = Settings::get( 'field_labels', array() );
		if ( ! is_array( $labels ) ) {
			$labels = array();
		}

		$out = array();
		foreach ( self::selected_keys() as $key ) {
			$out[] = ( isset( $labels[ $key ] ) && '' !== $labels[ $key ] )
				? $labels[ $key ]
				: $fields[ $key ]['label'];
		}

		return $out;
	}

	/**
	 * Map a WC_Order to a LIST of row arrays.
	 *
	 * One row per line item normally; a single row when no per-product field is
	 * selected, or when the order has no line items at all.
	 *
	 * @param \WC_Order $order The order to convert.
	 * @return array List of rows, each holding one value per selected field.
	 */
	public function map( \WC_Order $order ) {
		$keys = self::selected_keys();

		// Only bother reading line items if some selected field actually needs them.
		$items = self::has_per_item( $keys ) ? $order->get_items() : array();

		// Covers three cases with one branch: the one-row-per-order mode, an order
		// with no products (possible for manual orders), and WooCommerce returning
		// nothing. Without this an order could produce zero rows and vanish.
		if ( empty( $items ) ) {
			return array( $this->build_row( $keys, $order, null ) );
		}

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = $this->build_row( $keys, $order, $item );
		}

		return $rows;
	}

	/* ---------------------------------------------------------------------- *
	 * Internals.
	 * ---------------------------------------------------------------------- */

	/**
	 * Build one row: one value per selected field, in order.
	 *
	 * @param array          $keys  Selected field keys.
	 * @param \WC_Order      $order The order.
	 * @param object|null    $item  The line item, or null for an order-level row.
	 * @return array
	 */
	private function build_row( array $keys, \WC_Order $order, $item = null ) {
		$row = array();

		foreach ( $keys as $key ) {
			$row[] = $this->value_for( $key, $order, $item );
		}

		return $row;
	}

	/**
	 * Resolve a single field's value.
	 *
	 * Kept as a switch rather than closures in fields(), so the registry stays
	 * pure data — easier to read, and safe to cache or serialize.
	 *
	 * $item is null for order-level rows. Every per-product case returns '' in
	 * that situation, which is what makes the single-row path work without any
	 * special casing in map().
	 *
	 * @param string      $key   Field key.
	 * @param \WC_Order   $order The order.
	 * @param object|null $item  Line item, or null.
	 * @return mixed
	 */
	private function value_for( $key, \WC_Order $order, $item = null ) {
		switch ( $key ) {
			case 'order_id':
				return $order->get_id();
			case 'date':
				return $this->format_date( $order );
			case 'status':
				return $order->get_status();
			case 'customer':
				return $this->customer_name( $order );
			case 'email':
				return $order->get_billing_email();
			case 'phone':
				return $order->get_billing_phone();
			case 'product':
				return $item ? $item->get_name() : '';
			case 'quantity':
				return $item ? $item->get_quantity() : '';
			case 'unit_price':
				// The amount actually paid per unit AFTER any coupon, taken from
				// WooCommerce rather than derived, so it stays exact on discounts
				// and on quantities that do not divide evenly.
				return $item ? $order->get_item_total( $item ) : '';
			case 'order_total':
				return $order->get_total();
			case 'currency':
				return $order->get_currency();
			case 'payment':
				return $order->get_payment_method_title();
		}

		return '';
	}

	/**
	 * Format the order's creation date, guarding against a null date.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function format_date( \WC_Order $order ) {
		$date = $order->get_date_created(); // WC_DateTime|null.
		return $date ? $date->date( 'Y-m-d H:i:s' ) : '';
	}

	/**
	 * Build the billing full name.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function customer_name( \WC_Order $order ) {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return '' !== $name ? $name : __( 'Guest', 'woo-to-gsheet' );
	}
}
