<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * Local pickup slot selection at checkout, for products flagged
 * `csl_pickup_enabled`.
 *
 * This store's checkout page uses the WooCommerce Checkout *block*, not the
 * classic `[woocommerce_checkout]` shortcode -- block checkout has no
 * equivalent to classic hooks like `woocommerce_after_order_notes` /
 * `woocommerce_checkout_process`, so fields are registered through
 * WooCommerce Blocks' "Additional Checkout Fields" API instead
 * (`woocommerce_register_additional_checkout_field()`). That API only
 * supports `text`/`select`/`checkbox` field types, so pickup date and time
 * are rendered as `select` dropdowns (a bounded list of upcoming dates and
 * generic time slots) rather than native date/time pickers -- the trade-off
 * for not needing a custom JS block build. The authoritative validation
 * (correct hours, blackout dates) still runs entirely server-side.
 *
 * A free "Local Pickup" shipping rate is injected into the cart's shipping
 * options whenever the cart contains a pickup-enabled product
 * (`add_local_pickup_rate()`), alongside any real shipping methods rather
 * than replacing them -- so a mixed cart (pickup-enabled items alongside
 * normally-shipped items) can still ship normally if the customer prefers.
 * The pickup location/date/time fields only become visible and required
 * once the customer actually *selects* that rate, using the same
 * JSON-Schema-based conditional field rules WooCommerce Blocks uses
 * internally, evaluated against `cart.shipping_rates`.
 *
 * Slots are fixed 60-minute blocks -- the field schema
 * (`class-scf-fields.php`) stores opening/closing hours but not a slot
 * length, so this is a documented assumption (`SLOT_LENGTH_MINUTES`), not a
 * staff-configurable value. There's no per-slot capacity limit -- any number
 * of orders can book the same location/date/time slot.
 *
 * Locations have no stable ID field in the options-page repeater, so the
 * location's name doubles as its identifier here and in order meta. This
 * assumes staff keep location names unique.
 */
class CSL_Pickup_Scheduling {

	const SLOT_LENGTH_MINUTES     = 60;
	const DATE_OPTIONS_DAYS_AHEAD = 30;
	const TIME_OPTIONS_START      = '07:00';
	const TIME_OPTIONS_END        = '20:00';

	const SHIPPING_RATE_ID = 'csl_local_pickup';

	const FIELD_LOCATION = 'csl/pickup_location';
	const FIELD_DATE     = 'csl/pickup_date';
	const FIELD_TIME     = 'csl/pickup_time';

	public static function init() {
		// Registers the Pickup Location/Date/Time fields with WooCommerce Blocks' checkout.
		add_action( 'init', array( __CLASS__, 'register_checkout_fields' ) );
		// Injects the free $0 "Local Pickup" shipping rate whenever the cart contains a pickup-enabled product.
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'add_local_pickup_rate' ), 10, 2 );
		// Authoritative server-side validation (location/date/time all correct) -- block-checkout's equivalent of `woocommerce_checkout_process`.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'validate_order' ) );
		// The next three render the same "Pickup Details" block (location, date/time, address, Get Directions link) on the thank-you page, in order emails, and on the wp-admin order screen respectively.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_pickup_summary_thankyou' ) );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'render_pickup_summary_email' ), 10, 3 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'render_pickup_summary_admin' ) );
		// The next two override wherever WooCommerce shows the order's "Shipping Address" (thank-you page, emails, My Account, admin) to show the pickup location instead, for pickup orders.
		add_filter( 'woocommerce_order_get_formatted_shipping_address', array( __CLASS__, 'filter_formatted_shipping_address' ), 10, 3 );
		add_filter( 'woocommerce_shipping_address_map_url', array( __CLASS__, 'filter_shipping_address_map_url' ), 10, 2 );
		// Buffers the order-received page's full HTML so the auto-rendered "Additional information" section's Pickup Location row can get a clickable address/Get Directions link -- see the method's docblock for why a block-render filter doesn't work here.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_additional_fields_buffer' ) );
	}

	/**
	 * Product IDs currently enabled for local pickup -- queried fresh on
	 * every request (not cached), since staff can toggle this on any
	 * product at any time via the SCF field on the product edit screen.
	 */
	private static function get_pickup_enabled_product_ids() {
		return get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => 'csl_pickup_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
	}

	public static function get_locations() {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}

		$locations = get_field( 'csl_pickup_locations', 'option' );

		return is_array( $locations ) ? $locations : array();
	}

	public static function find_location( $name ) {
		foreach ( self::get_locations() as $location ) {
			if ( isset( $location['csl_location_name'] ) && $location['csl_location_name'] === $name ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Builds a display-ready, multi-line address (Address Line 1/2, then
	 * "City, State ZIP") from a location's separate address fields -- kept
	 * as a single formatted string downstream so `build_maps_url()`,
	 * `nl2br()` display, and the `_csl_pickup_address` order-meta snapshot
	 * don't need to know about the underlying field split.
	 */
	private static function format_location_address( $location ) {
		$address_1 = trim( $location['csl_location_address_1'] ?? '' );
		$address_2 = trim( $location['csl_location_address_2'] ?? '' );

		$street_line = implode( ', ', array_filter( array( $address_1, $address_2 ) ) );

		$lines = array_filter( array( $street_line ) );

		$city  = trim( $location['csl_location_city'] ?? '' );
		$state = trim( $location['csl_location_state'] ?? '' );
		$zip   = trim( $location['csl_location_zip'] ?? '' );

		$city_line = implode(
			' ',
			array_filter( array( $city ? $city . ',' : '', $state, $zip ) )
		);
		$city_line = rtrim( $city_line, ',' );

		if ( $city_line ) {
			$lines[] = $city_line;
		}

		return implode( "\n", $lines );
	}

	private static function day_key( $date_string ) {
		$date = DateTime::createFromFormat( 'Y-m-d', $date_string );

		return $date ? strtolower( $date->format( 'D' ) ) : '';
	}

	public static function is_blackout_date( $location, $date_string ) {
		foreach ( (array) ( $location['csl_location_blackout_dates'] ?? array() ) as $blackout ) {
			if ( ! empty( $blackout['csl_blackout_date'] ) && $blackout['csl_blackout_date'] === $date_string ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generates fixed-length time slots between a location's opening and
	 * closing time for the day of week the given date falls on.
	 */
	public static function get_time_slots( $location, $date_string ) {
		$day = self::day_key( $date_string );

		if ( ! $day || empty( $location['csl_location_hours'] ) ) {
			return array();
		}

		$slots = array();

		foreach ( $location['csl_location_hours'] as $hours_row ) {
			if ( empty( $hours_row['csl_hours_day'] ) || $day !== $hours_row['csl_hours_day'] ) {
				continue;
			}

			$opens  = $hours_row['csl_hours_opens'] ?? '';
			$closes = $hours_row['csl_hours_closes'] ?? '';

			$cursor = DateTime::createFromFormat( 'H:i', $opens );
			$close  = DateTime::createFromFormat( 'H:i', $closes );

			if ( ! $cursor || ! $close ) {
				continue;
			}

			while ( $cursor < $close ) {
				$slots[] = $cursor->format( 'H:i' );
				$cursor->modify( '+' . self::SLOT_LENGTH_MINUTES . ' minutes' );
			}
		}

		return $slots;
	}

	/**
	 * True if at least one configured location can serve this date (not a
	 * blackout date there, and has hours for that day of week).
	 */
	private static function date_available_at_any_location( $date_string ) {
		foreach ( self::get_locations() as $location ) {
			if ( empty( $location['csl_location_name'] ) ) {
				continue;
			}
			if ( self::is_blackout_date( $location, $date_string ) ) {
				continue;
			}
			if ( ! empty( self::get_time_slots( $location, $date_string ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Dates offered are only ones where at least one location is actually
	 * open (not a blackout date, has hours for that weekday) -- with a
	 * single configured location this excludes every closed/blackout date
	 * outright. With multiple locations that don't share the same schedule,
	 * a date only disappears once *every* location is closed for it; a
	 * mismatch between a specific location and date is still caught by the
	 * server-side check in validate_order() at submission time.
	 */
	private static function get_date_options() {
		$options = array();
		$date    = new DateTime( 'today' );

		for ( $i = 0; $i < self::DATE_OPTIONS_DAYS_AHEAD; $i++ ) {
			$date_string = $date->format( 'Y-m-d' );

			if ( self::date_available_at_any_location( $date_string ) ) {
				$options[] = array(
					'value' => $date_string,
					'label' => $date->format( 'D, M j, Y' ),
				);
			}

			$date->modify( '+1 day' );
		}

		return $options;
	}

	private static function get_time_options() {
		$options = array();
		$cursor  = DateTime::createFromFormat( 'H:i', self::TIME_OPTIONS_START );
		$end     = DateTime::createFromFormat( 'H:i', self::TIME_OPTIONS_END );

		while ( $cursor < $end ) {
			$start     = $cursor->format( 'H:i' );
			$options[] = array(
				'value' => $start,
				'label' => self::format_time_range( $start ),
			);
			$cursor->modify( '+' . self::SLOT_LENGTH_MINUTES . ' minutes' );
		}

		return $options;
	}

	/**
	 * JSON-Schema rule matching a cart whose *selected* shipping rate is our
	 * injected "Local Pickup" rate -- shared between the field-registration
	 * `required`/`hidden` rules below.
	 */
	private static function selected_pickup_rate_schema() {
		return array(
			'properties' => array(
				'cart' => array(
					'properties' => array(
						'shipping_rates' => array(
							'contains' => array(
								'enum' => array( self::SHIPPING_RATE_ID ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Registers the pickup location/date/time fields with WooCommerce
	 * Blocks. `required` and `hidden` are JSON-Schema rules evaluated
	 * against the cart -- both reference whether the customer has *selected*
	 * the "Local Pickup" shipping rate (inverted for `hidden`), so the
	 * fields only appear, and are only required, once pickup is actually the
	 * chosen fulfillment method -- not merely because a pickup-eligible
	 * product happens to be in the cart (it might still ship normally).
	 */
	public static function register_checkout_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$location_options = array();
		foreach ( self::get_locations() as $location ) {
			if ( empty( $location['csl_location_name'] ) ) {
				continue;
			}

			$label   = $location['csl_location_name'];
			$address = self::format_location_address( $location );
			if ( $address ) {
				$label .= ' -- ' . str_replace( "\n", ', ', $address );
			}

			$location_options[] = array(
				'value' => $location['csl_location_name'],
				'label' => $label,
			);
		}

		if ( empty( $location_options ) ) {
			// A select field can't be registered without options, and there's
			// nothing valid to offer if no locations are configured yet.
			return;
		}

		$required_when_pickup_selected = self::selected_pickup_rate_schema();
		$hidden_unless_pickup_selected  = array( 'not' => $required_when_pickup_selected );

		$common = array(
			'location' => 'order',
			'type'     => 'select',
			'required' => $required_when_pickup_selected,
			'hidden'   => $hidden_unless_pickup_selected,
		);

		woocommerce_register_additional_checkout_field(
			array_merge(
				$common,
				array(
					'id'      => self::FIELD_LOCATION,
					'label'   => __( 'Pickup Location', 'custom-store-logic' ),
					'options' => $location_options,
				)
			)
		);

		woocommerce_register_additional_checkout_field(
			array_merge(
				$common,
				array(
					'id'      => self::FIELD_DATE,
					'label'   => __( 'Pickup Date', 'custom-store-logic' ),
					'options' => self::get_date_options(),
				)
			)
		);

		woocommerce_register_additional_checkout_field(
			array_merge(
				$common,
				array(
					'id'      => self::FIELD_TIME,
					'label'   => __( 'Pickup Time', 'custom-store-logic' ),
					'options' => self::get_time_options(),
				)
			)
		);
	}

	/**
	 * True if any item in the given shipping package is a pickup-enabled
	 * product -- gates whether the free "Local Pickup" rate is offered for
	 * that package at all.
	 */
	private static function package_contains_pickup_item( $package ) {
		if ( ! function_exists( 'get_field' ) || empty( $package['contents'] ) ) {
			return false;
		}

		foreach ( $package['contents'] as $item ) {
			// Use the parent product ID, not the variation -- csl_pickup_enabled
			// is only ever registered on post_type == product.
			if ( ! empty( $item['product_id'] ) && get_field( 'csl_pickup_enabled', $item['product_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Injects a free "Local Pickup" shipping rate alongside whatever real
	 * shipping methods are available, whenever the package contains a
	 * pickup-enabled product. Not registered as a full WC_Shipping_Method /
	 * shipping zone entry -- injecting directly into the calculated rates
	 * means it works immediately even on a store with no shipping zones
	 * configured yet, which was the case here.
	 *
	 * @param WC_Shipping_Rate[] $rates
	 * @param array               $package
	 */
	public static function add_local_pickup_rate( $rates, $package ) {
		if ( ! self::package_contains_pickup_item( $package ) ) {
			return $rates;
		}

		$rate = new WC_Shipping_Rate(
			self::SHIPPING_RATE_ID,
			__( 'Local Pickup', 'custom-store-logic' ),
			0,
			array(),
			self::SHIPPING_RATE_ID
		);

		return array( self::SHIPPING_RATE_ID => $rate ) + $rates;
	}

	/**
	 * Mirrors the "does this order need pickup fields" check the field
	 * registration schema evaluates against the live cart, but reads from
	 * the order's line items -- by the time `woocommerce_store_api_checkout_
	 * order_processed` fires, the cart has already become an order.
	 * Order-level, so it checks the chosen shipping method rather than the
	 * cart's `shipping_rates` schema data (which no longer exists once the
	 * cart has been converted).
	 *
	 * @param WC_Order $order
	 */
	private static function order_selected_pickup( $order ) {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if ( self::SHIPPING_RATE_ID === $shipping_item->get_method_id() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Authoritative, cross-field validation of the pickup selection. Runs on
	 * `woocommerce_store_api_checkout_order_processed`, the block-checkout
	 * equivalent of the classic `woocommerce_checkout_process` gate --
	 * throwing here halts checkout with the given message shown to the
	 * customer.
	 *
	 * @param WC_Order $order
	 */
	public static function validate_order( $order ) {
		if ( ! self::order_selected_pickup( $order ) ) {
			return;
		}

		$checkout_fields = Package::container()->get( CheckoutFields::class );

		$location_name = $checkout_fields->get_field_from_object( self::FIELD_LOCATION, $order );
		$date_string   = $checkout_fields->get_field_from_object( self::FIELD_DATE, $order );
		$time_string   = $checkout_fields->get_field_from_object( self::FIELD_TIME, $order );

		if ( '' === $location_name || '' === $date_string || '' === $time_string ) {
			throw new RouteException(
				'csl_pickup_fields_incomplete',
				__( 'Please choose a pickup location, date, and time.', 'custom-store-logic' ),
				400
			);
		}

		$location = self::find_location( $location_name );

		if ( ! $location ) {
			throw new RouteException(
				'csl_pickup_location_invalid',
				__( 'The selected pickup location is no longer available. Please choose another.', 'custom-store-logic' ),
				400
			);
		}

		$date = DateTime::createFromFormat( 'Y-m-d', $date_string );

		if ( ! $date || $date->format( 'Y-m-d' ) !== $date_string || $date < new DateTime( 'today' ) ) {
			throw new RouteException(
				'csl_pickup_date_invalid',
				__( 'Please choose a valid, upcoming pickup date.', 'custom-store-logic' ),
				400
			);
		}

		if ( self::is_blackout_date( $location, $date_string ) ) {
			throw new RouteException(
				'csl_pickup_date_blackout',
				__( 'This location is not offering pickup on the selected date. Please choose another date.', 'custom-store-logic' ),
				400
			);
		}

		$valid_slots = self::get_time_slots( $location, $date_string );

		if ( empty( $valid_slots ) ) {
			throw new RouteException(
				'csl_pickup_date_closed',
				__( 'This location is closed on the selected date. Please choose another date.', 'custom-store-logic' ),
				400
			);
		}

		if ( ! in_array( $time_string, $valid_slots, true ) ) {
			throw new RouteException(
				'csl_pickup_time_invalid',
				__( 'Please choose a valid pickup time.', 'custom-store-logic' ),
				400
			);
		}

		// The Blocks field system already persisted these under its own
		// `_wc_other/csl/pickup_*` meta keys; mirroring them here (plus a
		// snapshot of the address, in case the location is later renamed or
		// removed) keeps the display methods below working against simple,
		// stable keys.
		$order->update_meta_data( '_csl_pickup_location', $location_name );
		$order->update_meta_data( '_csl_pickup_date', $date_string );
		$order->update_meta_data( '_csl_pickup_time', $time_string );
		$order->update_meta_data( '_csl_pickup_address', self::format_location_address( $location ) );
		$order->save_meta_data();
	}

	private static function format_time_range( $start_time ) {
		$start = DateTime::createFromFormat( 'H:i', $start_time );

		if ( ! $start ) {
			return $start_time;
		}

		$end = clone $start;
		$end->modify( '+' . self::SLOT_LENGTH_MINUTES . ' minutes' );

		return $start->format( 'g:i a' ) . '–' . $end->format( 'g:i a' );
	}

	/**
	 * Builds a Google Maps search URL for driving directions to the given
	 * address (multi-line textarea values are joined onto one line).
	 */
	private static function build_maps_url( $address ) {
		if ( ! $address ) {
			return '';
		}

		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( str_replace( "\n", ', ', $address ) );
	}

	/**
	 * @param WC_Order $order
	 * @return array|null Null if this isn't a pickup order.
	 */
	private static function get_pickup_summary_data( $order ) {
		$location_name = $order->get_meta( '_csl_pickup_location' );
		$date          = $order->get_meta( '_csl_pickup_date' );
		$time          = $order->get_meta( '_csl_pickup_time' );

		if ( ! $location_name || ! $date || ! $time ) {
			return null;
		}

		$address = $order->get_meta( '_csl_pickup_address' );

		// Orders placed before the address snapshot existed have no
		// `_csl_pickup_address` meta at all -- fall back to a live lookup by
		// location name so those older orders still show an address/map link
		// if a location by that name still exists.
		if ( ! $address ) {
			$location = self::find_location( $location_name );
			$address  = $location ? self::format_location_address( $location ) : '';
		}

		$timestamp = strtotime( $date );

		return array(
			'location_name' => $location_name,
			'address'       => $address,
			'date_display'  => $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : $date,
			'time_display'  => self::format_time_range( $time ),
			'maps_url'      => self::build_maps_url( $address ),
		);
	}

	/**
	 * This, `render_pickup_summary_email()`, and `render_pickup_summary_admin()`
	 * below all build from the same `get_pickup_summary_data()` (which handles
	 * the legacy-order address fallback), so the name/address/Get Directions
	 * structure stays consistent across the thank-you page, both order-email
	 * formats, and wp-admin -- only the surrounding markup differs per surface.
	 * `<div>` rather than `<p>` here specifically, per an explicit design
	 * choice, so there's no extra whitespace between the summary lines.
	 */
	public static function render_pickup_summary_thankyou( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$data = self::get_pickup_summary_data( $order );

		if ( ! $data ) {
			return;
		}

		echo '<section class="woocommerce-order-details csl-pickup-summary">';
		echo '<h2>' . esc_html__( 'Pickup Details', 'custom-store-logic' ) . '</h2>';
		echo '<div>' . esc_html( $data['location_name'] ) . ' -- ' . esc_html( $data['date_display'] ) . ', ' . esc_html( $data['time_display'] ) . '</div>';
		if ( $data['address'] ) {
			echo '<div>' . nl2br( esc_html( $data['address'] ) ) . '</div>';
		}
		if ( $data['maps_url'] ) {
			echo '<div><a href="' . esc_url( $data['maps_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Directions', 'custom-store-logic' ) . '</a></div>';
		}
		echo '</section>';
	}

	/**
	 * Same source data as `render_pickup_summary_thankyou()` above -- see its
	 * docblock. Handles both HTML and plain-text emails since WooCommerce
	 * fires `woocommerce_email_order_meta` for both formats.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 */
	public static function render_pickup_summary_email( $order, $sent_to_admin, $plain_text ) {
		$data = self::get_pickup_summary_data( $order );

		if ( ! $data ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Pickup Details:', 'custom-store-logic' ) . ' ' . esc_html( $data['location_name'] ) . ' -- ' . esc_html( $data['date_display'] ) . ', ' . esc_html( $data['time_display'] ) . "\n";
			if ( $data['address'] ) {
				echo esc_html( str_replace( "\n", ', ', $data['address'] ) ) . "\n";
			}
			if ( $data['maps_url'] ) {
				echo esc_html__( 'Get Directions:', 'custom-store-logic' ) . ' ' . esc_url( $data['maps_url'] ) . "\n";
			}
			return;
		}

		echo '<h2>' . esc_html__( 'Pickup Details', 'custom-store-logic' ) . '</h2>';
		echo '<p>' . esc_html( $data['location_name'] ) . ' -- ' . esc_html( $data['date_display'] ) . ', ' . esc_html( $data['time_display'] ) . '</p>';
		if ( $data['address'] ) {
			echo '<p>' . nl2br( esc_html( $data['address'] ) ) . '</p>';
		}
		if ( $data['maps_url'] ) {
			echo '<p><a href="' . esc_url( $data['maps_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Directions', 'custom-store-logic' ) . '</a></p>';
		}
	}

	/**
	 * Same source data as `render_pickup_summary_thankyou()` above -- see its
	 * docblock. Rendered on the wp-admin order-edit screen, right after the
	 * shipping address block.
	 *
	 * @param WC_Order $order
	 */
	public static function render_pickup_summary_admin( $order ) {
		$data = self::get_pickup_summary_data( $order );

		if ( ! $data ) {
			return;
		}

		echo '<p class="csl-pickup-summary"><strong>' . esc_html__( 'Pickup:', 'custom-store-logic' ) . '</strong> ' . esc_html( $data['location_name'] ) . ' -- ' . esc_html( $data['date_display'] ) . ', ' . esc_html( $data['time_display'] ) . '</p>';
		if ( $data['address'] ) {
			echo '<p class="csl-pickup-summary">' . nl2br( esc_html( $data['address'] ) ) . '</p>';
		}
		if ( $data['maps_url'] ) {
			echo '<p class="csl-pickup-summary"><a href="' . esc_url( $data['maps_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Directions', 'custom-store-logic' ) . '</a></p>';
		}
	}

	/**
	 * Replaces the "Shipping Address" shown on the thank-you page, in order
	 * emails, on the My Account order view, and on the wp-admin order screen
	 * (all of these call `WC_Order::get_formatted_shipping_address()`, so
	 * this one filter covers all of them) with the pickup location's own
	 * address, for pickup orders. The customer's actual submitted shipping
	 * address is untouched -- this only changes what's displayed.
	 *
	 * @param string   $address
	 * @param array    $raw_address
	 * @param WC_Order $order
	 */
	public static function filter_formatted_shipping_address( $address, $raw_address, $order ) {
		$data = self::get_pickup_summary_data( $order );

		if ( ! $data ) {
			return $address;
		}

		$lines = array(
			sprintf(
				/* translators: %s: pickup location name */
				esc_html__( 'Pickup at %s', 'custom-store-logic' ),
				esc_html( $data['location_name'] )
			),
		);

		if ( $data['address'] ) {
			$lines[] = nl2br( esc_html( $data['address'] ) );
		}

		if ( $data['maps_url'] ) {
			$lines[] = '<a href="' . esc_url( $data['maps_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Directions', 'custom-store-logic' ) . '</a>';
		}

		return implode( '<br/>', $lines );
	}

	/**
	 * WooCommerce's own "map" link (used e.g. in the admin orders list
	 * table) normally points at the customer's shipping address -- for
	 * pickup orders, point it at the pickup location instead.
	 *
	 * @param string   $url
	 * @param WC_Order $order
	 */
	public static function filter_shipping_address_map_url( $url, $order ) {
		$data = self::get_pickup_summary_data( $order );

		if ( ! $data || ! $data['maps_url'] ) {
			return $url;
		}

		return $data['maps_url'];
	}

	/**
	 * The order-confirmation page's "Additional information" section
	 * auto-renders our registered checkout fields (Pickup Location/Date/
	 * Time) as plain `<dt>label</dt><dd>value</dd>` rows -- WooCommerce
	 * Blocks escapes the value (`esc_html`), so there's no way to get a
	 * clickable Get Directions link in there through the field value itself.
	 *
	 * The obvious fix is hooking that block's own render filter
	 * (`render_block_woocommerce/order-confirmation-additional-fields`),
	 * and its wrapper's -- both were tried and neither actually fired on
	 * this site's real order-received page (confirmed by fetching the live
	 * page authenticated as the order's own customer, not just testing the
	 * block in isolation via `do_blocks()`): this page's "Additional
	 * information" section is composed through some other internal path
	 * that never runs through WordPress's `render_block()` filter pipeline
	 * for that block. Rather than keep guessing at which internal call path
	 * WooCommerce Blocks actually uses here, this buffers the *entire* page
	 * output and does the same `<dt>Pickup Location</dt><dd>...</dd>` row
	 * replacement against the final HTML -- guaranteed to catch it
	 * regardless of which code path generated it.
	 */
	public static function maybe_start_additional_fields_buffer() {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			ob_start( array( __CLASS__, 'enhance_additional_fields_html' ) );
		}
	}

	/**
	 * @param string $html
	 */
	public static function enhance_additional_fields_html( $html ) {
		$order_id = absint( get_query_var( 'order-received' ) );

		if ( ! $order_id ) {
			return $html;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return $html;
		}

		$data = self::get_pickup_summary_data( $order );

		if ( ! $data ) {
			return $html;
		}

		$replacement = '<dd>' . esc_html( $data['location_name'] );

		if ( $data['address'] ) {
			$replacement .= '<br />' . nl2br( esc_html( $data['address'] ) );
		}

		if ( $data['maps_url'] ) {
			$replacement .= '<br /><a href="' . esc_url( $data['maps_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get Directions', 'custom-store-logic' ) . '</a>';
		}

		$replacement .= '</dd>';

		$label_html = '<dt>' . esc_html__( 'Pickup Location', 'custom-store-logic' ) . '</dt>';
		$pattern    = '#' . preg_quote( $label_html, '#' ) . '<dd>.*?</dd>#s';

		return preg_replace( $pattern, $label_html . $replacement, $html, 1 );
	}
}
