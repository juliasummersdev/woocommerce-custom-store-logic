<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bundle pricing: computes the discounted bundle price from its components
 * and applies it (a) to the single product page price display and (b) to
 * the actual cart/checkout totals. Two separate mechanisms are needed here:
 * `get_price_html` only affects display before anything is in the cart,
 * while `woocommerce_before_calculate_totals` is what WooCommerce actually
 * sums for cart/checkout -- filtering price_html alone would show a
 * discount on the product page that the cart then silently ignores.
 */
class CSL_Cart_Pricing {

	public static function init() {
		// Shows the discounted bundle price (struck-through "regular" price) on the product page.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 10, 2 );
		// Applies that same discounted price to actual cart/checkout totals -- price_html alone never touches these.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_bundle_prices_to_cart' ), 20, 1 );
		// Blocks add-to-cart if a required (non-optional) bundle component is out of stock.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_bundle_stock' ), 10, 2 );
		// Priority 20: after SCF's own `_acf_do_save_post` (priority 10) has saved the fields this reads.
		// Persists the computed price into WooCommerce's own price meta so is_purchasable()/sorting/price-range widgets see it.
		add_action( 'acf/save_post', array( __CLASS__, 'sync_bundle_product_price' ), 20 );
	}

	/**
	 * Persists the computed bundle price into WooCommerce's own price meta
	 * whenever the bundle fields are saved. This isn't just a display nicety:
	 * WooCommerce's `is_purchasable()` requires `get_price() !== ''`, so
	 * without a stored price a bundle can't be added to the cart at all --
	 * and shop-page sorting/filtering-by-price and price-range widgets read
	 * these meta values directly via SQL, not through our runtime filters.
	 * `get_bundle_price_breakdown()` is still applied live at display/cart
	 * time as well, so the price stays accurate even if a component's own
	 * price changes after the bundle was last saved.
	 */
	public static function sync_bundle_product_price( $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$breakdown = self::get_bundle_price_breakdown( $post_id );
		if ( ! $breakdown ) {
			return;
		}

		update_post_meta( $post_id, '_regular_price', $breakdown['component_total'] );

		if ( $breakdown['final_price'] < $breakdown['component_total'] ) {
			update_post_meta( $post_id, '_sale_price', $breakdown['final_price'] );
			update_post_meta( $post_id, '_price', $breakdown['final_price'] );
		} else {
			delete_post_meta( $post_id, '_sale_price' );
			update_post_meta( $post_id, '_price', $breakdown['final_price'] );
		}

		wc_delete_product_transients( $post_id );
	}

	/**
	 * Sums each component's current price (respecting its own active sale
	 * price) times quantity, then applies the bundle's pricing rule.
	 * Returns false for non-bundles so callers can bail without extra checks.
	 *
	 * @return array{component_total: float, final_price: float, savings: float}|false
	 */
	public static function get_bundle_price_breakdown( $product_id ) {
		if ( ! function_exists( 'get_field' ) || ! get_field( 'csl_is_bundle', $product_id ) ) {
			return false;
		}

		$components = get_field( 'csl_bundle_components', $product_id );
		if ( empty( $components ) ) {
			return false;
		}

		$component_total = 0;
		foreach ( $components as $component ) {
			$component_product = wc_get_product( $component['csl_component_product'] );
			if ( ! $component_product ) {
				continue;
			}
			$quantity          = max( 1, (int) $component['csl_component_quantity'] );
			$component_total  += (float) $component_product->get_price() * $quantity;
		}

		$pricing_rule  = get_field( 'csl_bundle_pricing_rule', $product_id );
		$pricing_value = (float) get_field( 'csl_bundle_pricing_value', $product_id );

		switch ( $pricing_rule ) {
			case 'percent_discount':
				$final_price = $component_total * ( 1 - min( 100, max( 0, $pricing_value ) ) / 100 );
				break;
			case 'custom_total':
				$final_price = $pricing_value;
				break;
			case 'fixed_discount':
			default:
				$final_price = $component_total - $pricing_value;
				break;
		}

		$final_price = max( 0, $final_price );

		return array(
			'component_total' => $component_total,
			'final_price'      => $final_price,
			'savings'          => max( 0, $component_total - $final_price ),
		);
	}

	/**
	 * Shows the component total as a struck-through "regular" price next to
	 * the discounted bundle price, using WooCommerce's own sale-price markup
	 * so it's styled identically to a normal on-sale product.
	 */
	public static function filter_price_html( $price_html, $product ) {
		$breakdown = self::get_bundle_price_breakdown( $product->get_id() );
		if ( ! $breakdown ) {
			return $price_html;
		}

		return wc_format_sale_price( $breakdown['component_total'], $breakdown['final_price'] );
	}

	/**
	 * Overrides each bundle line item's price in the cart with its computed
	 * bundle price. This is what actually changes cart/checkout totals --
	 * `filter_price_html()` above only affects the product page, which never
	 * touches the cart.
	 */
	public static function apply_bundle_prices_to_cart( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$breakdown = self::get_bundle_price_breakdown( $cart_item['product_id'] );
			if ( $breakdown ) {
				$cart_item['data']->set_price( $breakdown['final_price'] );
			}
		}
	}

	/**
	 * Blocks adding a bundle to the cart if a required component is out of
	 * stock -- otherwise the customer pays the bundle price for something
	 * that can't actually be fulfilled. Optional components don't block
	 * add-to-cart since they're not guaranteed to be included.
	 */
	public static function validate_bundle_stock( $passed, $product_id ) {
		if ( ! function_exists( 'get_field' ) || ! get_field( 'csl_is_bundle', $product_id ) ) {
			return $passed;
		}

		$components = get_field( 'csl_bundle_components', $product_id );
		if ( empty( $components ) ) {
			return $passed;
		}

		foreach ( $components as $component ) {
			if ( empty( $component['csl_component_required'] ) ) {
				continue;
			}
			$component_product = wc_get_product( $component['csl_component_product'] );
			if ( $component_product && ! $component_product->is_in_stock() ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: out-of-stock component product name */
						__( 'This bundle can\'t be added to your cart right now because "%s" is out of stock.', 'custom-store-logic' ),
						$component_product->get_name()
					),
					'error'
				);
				return false;
			}
		}

		return $passed;
	}
}
