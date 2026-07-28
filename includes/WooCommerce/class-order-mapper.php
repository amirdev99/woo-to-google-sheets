<?php
/**
 * Converts a WooCommerce order into the rows we write to the sheet.
 *
 * Pure transformation: no DB, no API. Given a WC_Order, return ONE ROW PER
 * PRODUCT (columns A-L). The order-level fields are repeated on every row, which
 * is the usual shape for a flat export — it lets you filter and pivot in Sheets
 * without lookups. Used by the Sync_Processor at send time.
 *
 * @package WTG
 */

namespace WTG\WooCommerce;

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Mapper
 */
class Order_Mapper {

	/**
	 * The column headers, in order. Handy for a manual header row (paste into
	 * row 1 of your sheet) and to document the mapping in one place.
	 *
	 * Columns G-I are per-PRODUCT; everything else repeats for each row of the
	 * same order.
	 *
	 * @return array
	 */
	public static function header() {
		return array(
			'Order ID',       // A
			'Date',           // B
			'Status',         // C
			'Customer Name',  // D
			'Email',          // E
			'Phone',          // F
			'Product',        // G - per product.
			'Quantity',       // H - per product.
			'Unit Price',     // I - per product.
			'Order Total',    // J
			'Currency',       // K
			'Payment Method', // L
		);
	}

	/**
	 * Map a WC_Order to a LIST of row arrays — one row per line item.
	 *
	 * Returns a 2D array (rows of columns) even for a single product, so callers
	 * never need to special-case the count.
	 *
	 * @param \WC_Order $order The order to convert.
	 * @return array List of rows, each with twelve values in column order.
	 */
	public function map( \WC_Order $order ) {
		// The order-level half of every row, built once and reused.
		$common_head = array(
			$order->get_id(),               // A: Order ID.
			$this->format_date( $order ),   // B: Date (site-independent format).
			$order->get_status(),           // C: Status slug (e.g. processing).
			$this->customer_name( $order ), // D: Billing full name.
			$order->get_billing_email(),    // E: Email.
			$order->get_billing_phone(),    // F: Phone.
		);

		$common_tail = array(
			$order->get_total(),                // J: Order total (whole order).
			$order->get_currency(),             // K: Currency code.
			$order->get_payment_method_title(), // L: Human-readable gateway name.
		);

		$rows = array();

		foreach ( $order->get_items() as $item ) {
			// $item is a WC_Order_Item_Product.
			$rows[] = array_merge(
				$common_head,
				array(
					$item->get_name(),     // G: This product's name.
					$item->get_quantity(), // H: This product's quantity.
					// I: Price of ONE unit — the amount actually paid per unit AFTER
					// any coupon, taken from WooCommerce rather than derived, so it
					// stays exact on discounts and on awkward quantities.
					$order->get_item_total( $item ),
				),
				$common_tail
			);
		}

		// An order with no line items (rare, but possible for manual orders) would
		// otherwise vanish from the sheet entirely. Emit one row with the product
		// columns blank so the order is still recorded.
		if ( empty( $rows ) ) {
			$rows[] = array_merge( $common_head, array( '', '', '' ), $common_tail );
		}

		return $rows;
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
