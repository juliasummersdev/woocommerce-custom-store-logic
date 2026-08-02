<?php
defined( 'ABSPATH' ) || exit;

/**
 * Snapshots bundle contents onto the order line item at checkout, and
 * displays that snapshot wherever WooCommerce shows order line items:
 * the thank-you page, order emails (HTML and plain-text), and the
 * wp-admin order edit screen.
 *
 * A snapshot (rather than looking up the bundle's current components at
 * display time) matters because a bundle's components can change after
 * the order is placed -- a component product could be renamed, have its
 * quantity in the bundle changed, or be removed from the bundle entirely.
 * Without a snapshot, an old order would misleadingly show whatever the
 * bundle contains *today*, not what the customer actually bought.
 */
class CSL_Order_Meta {

	public static function init() {
		// Snapshots a bundle's current components onto the order line item at checkout.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_line_item_meta' ), 10, 4 );
		// Renders that snapshot as an "Includes:" list -- fires on the thank-you page and in both HTML/plain-text order emails.
		add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'display_line_item_meta' ), 10, 4 );
		// The next two add a "Bundle Contents" column to the wp-admin order-edit line items table.
		add_action( 'woocommerce_admin_order_item_headers', array( __CLASS__, 'render_admin_header' ) );
		add_action( 'woocommerce_admin_order_item_values', array( __CLASS__, 'render_admin_value' ), 10, 3 );
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array                  $values
	 * @param WC_Order               $order
	 */
	public static function save_line_item_meta( $item, $cart_item_key, $values, $order ) {
		$product = $item->get_product();

		if ( ! $product || ! function_exists( 'get_field' ) || ! get_field( 'csl_is_bundle', $product->get_id() ) ) {
			return;
		}

		$components = get_field( 'csl_bundle_components', $product->get_id() );

		if ( empty( $components ) ) {
			return;
		}

		$snapshot = array();

		foreach ( $components as $component ) {
			$component_product = ! empty( $component['csl_component_product'] ) ? wc_get_product( $component['csl_component_product'] ) : null;

			if ( ! $component_product ) {
				continue;
			}

			$snapshot[] = array(
				'name'       => $component_product->get_name(),
				'quantity'   => max( 1, (int) $component['csl_component_quantity'] ),
				'product_id' => $component_product->get_id(),
			);
		}

		if ( $snapshot ) {
			// Underscore-prefixed key: hidden from WooCommerce's default
			// item-meta dump so only our own formatted output below shows.
			$item->add_meta_data( '_csl_bundle_components', $snapshot, true );
		}
	}

	/**
	 * Fires on the thank-you page, and in both HTML and plain-text order
	 * emails -- all three share this one hook.
	 *
	 * @param int           $item_id
	 * @param WC_Order_Item $item
	 * @param WC_Order      $order
	 * @param bool          $plain_text
	 */
	public static function display_line_item_meta( $item_id, $item, $order, $plain_text ) {
		$components = $item->get_meta( '_csl_bundle_components' );

		if ( empty( $components ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Includes:', 'custom-store-logic' ) . "\n";
			foreach ( $components as $component ) {
				echo '- ' . esc_html( $component['name'] ) . ' x' . esc_html( self::get_total_component_quantity( $item, $component ) ) . "\n";
			}
			return;
		}

		echo '<p class="csl-bundle-contents"><strong>' . esc_html__( 'Includes:', 'custom-store-logic' ) . '</strong></p>';
		echo '<ul class="csl-bundle-contents__list">';
		foreach ( $components as $component ) {
			$name        = esc_html( $component['name'] );
			$product_id  = self::get_component_product_id( $item, $component );
			$permalink   = $product_id ? get_permalink( $product_id ) : false;
			$name_markup = $permalink ? '<a href="' . esc_url( $permalink ) . '" target="_blank" rel="noopener noreferrer">' . $name . '</a>' : $name;

			echo '<li>' . $name_markup . ' &times; ' . esc_html( self::get_total_component_quantity( $item, $component ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name_markup built from esc_html()/esc_url() above.
		}
		echo '</ul>';
	}

	/**
	 * The snapshot stores each component's quantity *per bundle* (e.g. "1
	 * hair mask per bundle") -- multiplying by the line item's own quantity
	 * gives the actual total a customer who ordered more than one of the
	 * bundle receives (e.g. 2 bundles x 1 hair mask each = 2 hair masks
	 * total), rather than misleadingly showing the per-bundle count
	 * regardless of how many bundles were ordered.
	 *
	 * @param WC_Order_Item $item
	 * @param array          $component
	 */
	private static function get_total_component_quantity( $item, $component ) {
		return (int) $component['quantity'] * max( 1, (int) $item->get_quantity() );
	}

	/**
	 * Snapshots saved before product links were added have no `product_id`
	 * -- fall back to matching against the bundle's *current* components by
	 * name, so older orders can still link out where possible.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param array                  $component
	 * @return int 0 if no product could be resolved.
	 */
	private static function get_component_product_id( $item, $component ) {
		if ( ! empty( $component['product_id'] ) && get_post( $component['product_id'] ) ) {
			return (int) $component['product_id'];
		}

		if ( ! function_exists( 'get_field' ) ) {
			return 0;
		}

		$bundle_product = $item->get_product();

		if ( ! $bundle_product ) {
			return 0;
		}

		$current_components = get_field( 'csl_bundle_components', $bundle_product->get_id() );

		foreach ( (array) $current_components as $current_component ) {
			$current_product = ! empty( $current_component['csl_component_product'] ) ? wc_get_product( $current_component['csl_component_product'] ) : null;

			if ( $current_product && $current_product->get_name() === $component['name'] ) {
				return $current_product->get_id();
			}
		}

		return 0;
	}

	/**
	 * Adds the "Bundle Contents" column header to the wp-admin order-edit
	 * line items table -- paired with `render_admin_value()` below, which
	 * fills in that column for each row.
	 */
	public static function render_admin_header() {
		echo '<th class="csl-bundle-contents-header">' . esc_html__( 'Bundle Contents', 'custom-store-logic' ) . '</th>';
	}

	/**
	 * Fills in the "Bundle Contents" column (`render_admin_header()` above)
	 * for every line item row, bundle or not -- an em dash for ordinary
	 * items keeps the table's columns visually aligned rather than leaving
	 * a blank cell.
	 *
	 * @param WC_Product|false $product
	 * @param WC_Order_Item    $item
	 * @param int               $item_id
	 */
	public static function render_admin_value( $product, $item, $item_id ) {
		$components = $item->get_meta( '_csl_bundle_components' );

		echo '<td class="csl-bundle-contents-value">';

		if ( empty( $components ) ) {
			echo '&#8212;';
		} else {
			$rows = array();
			foreach ( $components as $component ) {
				$rows[] = esc_html( $component['name'] ) . ' &times; ' . esc_html( self::get_total_component_quantity( $item, $component ) );
			}
			echo implode( '<br>', $rows );
		}

		echo '</td>';
	}
}
