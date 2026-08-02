<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers all SCF field groups for the custom store logic (bundles,
 * size/fit variants, local pickup). Registered in PHP via
 * `acf_add_local_field_group()` rather than the SCF admin UI so the field
 * architecture is version-controlled and portable across environments
 * (README "Field groups registered via PHP" principle).
 *
 * This class only defines *schema* (Phase 1). Cart pricing, checkout
 * validation, and order-meta hooks that read these fields are built in
 * Phase 2.
 */
class CSL_SCF_Fields {

	public static function init() {
		// Field schema registration -- all three run on every `acf/init`, so
		// they're pure no-ops when SCF is inactive rather than throwing.
		add_action( 'acf/init', array( __CLASS__, 'register_bundle_fields' ) );
		add_action( 'acf/init', array( __CLASS__, 'register_variant_fields' ) );
		add_action( 'acf/init', array( __CLASS__, 'register_pickup_fields' ) );
		add_action( 'acf/init', array( __CLASS__, 'register_promo_banner_fields' ) );
		// Populates the Bundle Components "Links"/"Inventory Count" columns on the product edit screen.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_bundle_admin_script' ) );
		// Backs the live stock lookup that script performs for whichever component product is selected.
		add_action( 'wp_ajax_csl_get_product_stock', array( __CLASS__, 'ajax_get_product_stock' ) );
	}

	/**
	 * Product bundles: an "enable bundle" switch plus a repeater of
	 * components (linked product, quantity, required/optional) and a
	 * pricing rule for the bundle as a whole.
	 */
	public static function register_bundle_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group(
			array(
				'key'      => 'group_csl_bundle_fields',
				'title'    => __( 'Bundle Settings', 'custom-store-logic' ),
				'fields'   => array(
					array(
						'key'           => 'field_csl_is_bundle',
						'name'          => 'csl_is_bundle',
						'label'         => __( 'This product is a bundle', 'custom-store-logic' ),
						'type'          => 'true_false',
						'ui'            => 1,
						'instructions'  => __( 'Turn this product into a configurable bundle of other products.', 'custom-store-logic' ),
					),
					array(
						'key'               => 'field_csl_bundle_show_components',
						'name'              => 'csl_bundle_show_components',
						'label'             => __( 'Show component list on product page', 'custom-store-logic' ),
						'type'              => 'true_false',
						'ui'                => 1,
						'default_value'     => 1,
						'instructions'      => __( 'Displays each component\'s thumbnail, name, regular price, and link where the product description would normally appear. Turn off if you\'d rather write your own description copy.', 'custom-store-logic' ),
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_csl_is_bundle',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
					),
					array(
						'key'               => 'field_csl_bundle_show_gallery_images',
						'name'              => 'csl_bundle_show_gallery_images',
						'label'             => __( 'Show component images in product gallery', 'custom-store-logic' ),
						'type'              => 'true_false',
						'ui'                => 1,
						'default_value'     => 1,
						'instructions'      => __( 'When on, each component\'s own image is added to the product gallery alongside the bundle\'s main image. Turn off to show only the bundle\'s own main image.', 'custom-store-logic' ),
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_csl_is_bundle',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
					),
					array(
						'key'               => 'field_csl_bundle_components',
						'name'              => 'csl_bundle_components',
						'label'             => __( 'Bundle Components', 'custom-store-logic' ),
						'type'              => 'repeater',
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_csl_is_bundle',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
						'layout'            => 'table',
						'button_label'      => __( 'Add Component', 'custom-store-logic' ),
						'sub_fields'        => array(
							array(
								'key'          => 'field_csl_component_product',
								'name'         => 'csl_component_product',
								'label'        => __( 'Product', 'custom-store-logic' ),
								'type'         => 'post_object',
								'post_type'    => array( 'product' ),
								'return_format' => 'id',
								'required'     => 1,
							),
							array(
								'key'           => 'field_csl_component_quantity',
								'name'          => 'csl_component_quantity',
								'label'         => __( 'Quantity', 'custom-store-logic' ),
								'type'          => 'number',
								'default_value' => 1,
								'min'           => 1,
								'step'          => 1,
								'required'      => 1,
							),
							array(
								'key'           => 'field_csl_component_required',
								'name'          => 'csl_component_required',
								'label'         => __( 'Inventory Required', 'custom-store-logic' ),
								'type'          => 'true_false',
								'ui'            => 1,
								'default_value' => 1,
								'instructions'  => __( 'When checked, this bundle cannot be added to the cart if this component is out of stock. Uncheck for a component whose availability shouldn\'t block the sale of the bundle.', 'custom-store-logic' ),
							),
							array(
								'key'     => 'field_csl_component_stock',
								'name'    => 'csl_component_stock',
								'label'   => __( 'Inventory Count', 'custom-store-logic' ),
								'type'    => 'message',
								'message' => '',
								/**
								 * Populated client-side, same as Links -- stock is looked up
								 * live via AJAX (not read from the initial page load) so it
								 * stays accurate if stock changes elsewhere while this screen
								 * is open, and so it works for a row added after page load.
								 */
							),
							array(
								'key'          => 'field_csl_component_links',
								'name'         => 'csl_component_links',
								'label'        => __( 'Links', 'custom-store-logic' ),
								'type'         => 'message',
								'message'      => '',
								/**
								 * Populated client-side (assets/js/bundle-admin.js) from the
								 * "Product" column's current selection -- there's no server-side
								 * row data to render Edit/View links from for a row the admin
								 * just added but hasn't saved yet.
								 */
							),
						),
					),
					array(
						'key'               => 'field_csl_bundle_pricing_rule',
						'name'              => 'csl_bundle_pricing_rule',
						'label'             => __( 'Pricing Rule', 'custom-store-logic' ),
						'type'              => 'select',
						'choices'           => array(
							'fixed_discount'   => __( 'Fixed discount off component total', 'custom-store-logic' ),
							'percent_discount' => __( 'Percentage discount off component total', 'custom-store-logic' ),
							'custom_total'     => __( 'Custom fixed bundle price', 'custom-store-logic' ),
						),
						'default_value'     => 'fixed_discount',
						'conditional_logic'  => array(
							array(
								array(
									'field'    => 'field_csl_is_bundle',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
					),
					array(
						'key'               => 'field_csl_bundle_pricing_value',
						'name'              => 'csl_bundle_pricing_value',
						'label'             => __( 'Pricing Value', 'custom-store-logic' ),
						'type'              => 'number',
						'instructions'      => __( 'Meaning depends on the pricing rule above: a currency amount off, a percentage off, or the bundle\'s fixed total price.', 'custom-store-logic' ),
						'min'               => 0,
						'conditional_logic'  => array(
							array(
								array(
									'field'    => 'field_csl_is_bundle',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'product',
						),
					),
				),
			)
		);
	}

	/**
	 * Size/fit variant data. Registered against `product_variation` so
	 * `get_field()` / `update_field()` read and write correctly against a
	 * variation's post ID -- but SCF has no built-in location rule or render
	 * path for the WooCommerce variation row, since variations are edited
	 * via an AJAX-loaded partial on the parent product screen, not a normal
	 * post-edit screen. Phase 2 renders and saves these fields manually via
	 * `woocommerce_product_after_variable_attributes` /
	 * `woocommerce_save_product_variation`, using this field group only as
	 * the versioned schema definition.
	 */
	public static function register_variant_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group(
			array(
				'key'      => 'group_csl_variant_fields',
				'title'    => __( 'Size/Fit Variant Settings', 'custom-store-logic' ),
				'fields'   => array(
					array(
						'key'     => 'field_csl_fit_profile',
						'name'    => 'csl_fit_profile',
						'label'   => __( 'Fit Profile', 'custom-store-logic' ),
						'type'    => 'select',
						'choices' => array(
							'slim'      => __( 'Slim', 'custom-store-logic' ),
							'regular'   => __( 'Regular', 'custom-store-logic' ),
							'relaxed'   => __( 'Relaxed', 'custom-store-logic' ),
							'oversized' => __( 'Oversized', 'custom-store-logic' ),
						),
						'default_value' => 'regular',
					),
					array(
						'key'          => 'field_csl_size_notes',
						'name'         => 'csl_size_notes',
						'label'        => __( 'Fit Notes', 'custom-store-logic' ),
						'type'         => 'textarea',
						'instructions' => __( 'Shown to customers on the product page, e.g. "Runs small, size up." Leave blank to show nothing.', 'custom-store-logic' ),
						'rows'         => 2,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'product_variation',
						),
					),
				),
			)
		);
	}

	/**
	 * Local pickup: a per-product "offer pickup" toggle, plus a store-wide
	 * repeater of pickup locations (address, hours, capacity, blackout
	 * dates) on the shared options page so staff can manage locations
	 * without a developer.
	 */
	public static function register_pickup_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		// Matches Settings > General > Date Format so Blackout Dates display
		// consistently with the rest of wp-admin, and stays in sync since this
		// runs fresh on every `acf/init`.
		$date_format = get_option( 'date_format' );
		if ( ! $date_format ) {
			$date_format = 'F j, Y';
		}

		acf_add_local_field_group(
			array(
				'key'      => 'group_csl_pickup_product_fields',
				'title'    => __( 'Local Pickup', 'custom-store-logic' ),
				'fields'   => array(
					array(
						'key'          => 'field_csl_pickup_enabled',
						'name'         => 'csl_pickup_enabled',
						'label'        => __( 'Available for local pickup', 'custom-store-logic' ),
						'type'         => 'true_false',
						'ui'           => 1,
						'instructions' => __( 'Lets the customer choose a pickup slot at checkout instead of shipping.', 'custom-store-logic' ),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'product',
						),
					),
				),
			)
		);

		acf_add_local_field_group(
			array(
				'key'      => 'group_csl_pickup_locations',
				'title'    => __( 'Pickup Locations', 'custom-store-logic' ),
				'fields'   => array(
					array(
						'key'          => 'field_csl_pickup_locations',
						'name'         => 'csl_pickup_locations',
						'label'        => __( 'Pickup Locations', 'custom-store-logic' ),
						'type'         => 'repeater',
						'layout'       => 'block',
						'button_label' => __( 'Add Location', 'custom-store-logic' ),
						'sub_fields'   => array(
							array(
								'key'      => 'field_csl_location_name',
								'name'     => 'csl_location_name',
								'label'    => __( 'Location Name', 'custom-store-logic' ),
								'type'     => 'text',
								'required' => 1,
							),
							array(
								'key'   => 'field_csl_location_address_1',
								'name'  => 'csl_location_address_1',
								'label' => __( 'Address Line 1', 'custom-store-logic' ),
								'type'  => 'text',
							),
							array(
								'key'     => 'field_csl_location_address_2',
								'name'    => 'csl_location_address_2',
								'label'   => __( 'Address Line 2', 'custom-store-logic' ),
								'type'    => 'text',
								'wrapper' => array( 'width' => '' ),
							),
							array(
								'key'     => 'field_csl_location_city',
								'name'    => 'csl_location_city',
								'label'   => __( 'City', 'custom-store-logic' ),
								'type'    => 'text',
								'wrapper' => array( 'width' => '50' ),
							),
							array(
								'key'     => 'field_csl_location_state',
								'name'    => 'csl_location_state',
								'label'   => __( 'State', 'custom-store-logic' ),
								'type'    => 'text',
								'wrapper' => array( 'width' => '25' ),
							),
							array(
								'key'     => 'field_csl_location_zip',
								'name'    => 'csl_location_zip',
								'label'   => __( 'ZIP Code', 'custom-store-logic' ),
								'type'    => 'text',
								'wrapper' => array( 'width' => '25' ),
							),
							array(
								'key'          => 'field_csl_location_hours',
								'name'         => 'csl_location_hours',
								'label'        => __( 'Hours', 'custom-store-logic' ),
                                'instructions' => __( 'For closed days, leave the value blank.', 'custom-store-logic' ),
								'type'         => 'repeater',
								'layout'       => 'table',
								'button_label' => __( 'Add Hours Row', 'custom-store-logic' ),
								'sub_fields'   => array(
									array(
										'key'     => 'field_csl_hours_day',
										'name'    => 'csl_hours_day',
										'label'   => __( 'Day', 'custom-store-logic' ),
										'type'    => 'select',
										'choices' => array(
											'mon' => __( 'Monday', 'custom-store-logic' ),
											'tue' => __( 'Tuesday', 'custom-store-logic' ),
											'wed' => __( 'Wednesday', 'custom-store-logic' ),
											'thu' => __( 'Thursday', 'custom-store-logic' ),
											'fri' => __( 'Friday', 'custom-store-logic' ),
											'sat' => __( 'Saturday', 'custom-store-logic' ),
											'sun' => __( 'Sunday', 'custom-store-logic' ),
										),
									),
									array(
										'key'           => 'field_csl_hours_opens',
										'name'          => 'csl_hours_opens',
										'label'         => __( 'Opens', 'custom-store-logic' ),
										'type'          => 'time_picker',
										'display_format' => 'g:i a',
										'return_format'   => 'H:i',
									),
									array(
										'key'           => 'field_csl_hours_closes',
										'name'          => 'csl_hours_closes',
										'label'         => __( 'Closes', 'custom-store-logic' ),
										'type'          => 'time_picker',
										'display_format' => 'g:i a',
										'return_format'   => 'H:i',
									),
								),
							),
							array(
								'key'          => 'field_csl_location_blackout_dates',
								'name'         => 'csl_location_blackout_dates',
								'label'        => __( 'Blackout Dates', 'custom-store-logic' ),
								'type'         => 'repeater',
								'layout'       => 'table',
								'button_label' => __( 'Add Blackout Date', 'custom-store-logic' ),
								'instructions' => __( 'Dates this location does not offer pickup at all (holidays, inventory days, etc.).', 'custom-store-logic' ),
								'sub_fields'   => array(
									array(
										'key'           => 'field_csl_blackout_date',
										'name'          => 'csl_blackout_date',
										'label'         => __( 'Date', 'custom-store-logic' ),
										'type'          => 'date_picker',
										'display_format' => $date_format,
										'return_format'   => 'Y-m-d',
									),
								),
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => CSL_OPTIONS_PAGE_SLUG,
						),
					),
				),
			)
		);
	}

	/**
	 * Site-wide promo banner: staff-facing on/off switch, text, an optional
	 * link, and an optional date range -- rendered by `CSL_Promo_Banner`.
	 * Kept on the shared options page like Pickup Locations, per the
	 * README "Staff-safe settings only in the options page" principle.
	 */
	public static function register_promo_banner_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		// Matches Settings > General > Date Format, same reasoning as the
		// Pickup Locations blackout dates above.
		$date_format = get_option( 'date_format' );
		if ( ! $date_format ) {
			$date_format = 'F j, Y';
		}

		// "Custom URL" comes first so it's the obvious default -- the rest of
		// the list is every published page/post, letting staff link the
		// banner straight to one without copy-pasting its URL.
		$link_choices = array( 'custom' => __( 'Custom URL', 'custom-store-logic' ) );

		$linkable_posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $linkable_posts as $linkable_post ) {
			$type_label = 'page' === $linkable_post->post_type ? __( 'Page', 'custom-store-logic' ) : __( 'Post', 'custom-store-logic' );
			$link_choices[ $linkable_post->ID ] = $linkable_post->post_title . ' (' . $type_label . ')';
		}

		$enabled_condition = array(
			array(
				array(
					'field'    => 'field_csl_promo_banner_enabled',
					'operator' => '==',
					'value'    => '1',
				),
			),
		);

		acf_add_local_field_group(
			array(
				'key'      => 'group_csl_promo_banner',
				'title'    => __( 'Promo Banner', 'custom-store-logic' ),
				'fields'   => array(
					array(
						'key'          => 'field_csl_promo_banner_enabled',
						'name'         => 'csl_promo_banner_enabled',
						'label'        => __( 'Enable Promo Banner', 'custom-store-logic' ),
						'type'         => 'true_false',
						'ui'           => 1,
						'instructions' => __( 'Shows a site-wide banner at the top of every page.', 'custom-store-logic' ),
					),
					array(
						'key'               => 'field_csl_promo_banner_text',
						'name'              => 'csl_promo_banner_text',
						'label'             => __( 'Banner Text', 'custom-store-logic' ),
						'type'              => 'text',
						'instructions'      => __( 'The banner stays hidden if this is left blank, even when enabled.', 'custom-store-logic' ),
						'conditional_logic' => $enabled_condition,
					),
					array(
						'key'               => 'field_csl_promo_banner_link_page',
						'name'              => 'csl_promo_banner_link_page',
						'label'             => __( 'Link to Page/Post', 'custom-store-logic' ),
						'type'              => 'select',
						'choices'           => $link_choices,
						'default_value'     => 'custom',
						'instructions'      => __( 'Makes the banner text clickable. Choose "Custom URL" to enter any link below instead of linking to a specific page/post.', 'custom-store-logic' ),
						'conditional_logic' => $enabled_condition,
					),
					array(
						'key'               => 'field_csl_promo_banner_link_url',
						'name'              => 'csl_promo_banner_link_url',
						'label'             => __( 'Or Custom URL', 'custom-store-logic' ),
						'type'              => 'text',
						'instructions'      => __( 'Any value is accepted (external URLs, relative paths, mailto:, etc.) -- not validated as a strict URL.', 'custom-store-logic' ),
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_csl_promo_banner_enabled',
									'operator' => '==',
									'value'    => '1',
								),
								array(
									'field'    => 'field_csl_promo_banner_link_page',
									'operator' => '==',
									'value'    => 'custom',
								),
							),
						),
					),
					array(
						'key'               => 'field_csl_promo_banner_start_date',
						'name'              => 'csl_promo_banner_start_date',
						'label'             => __( 'Start Date', 'custom-store-logic' ),
						'type'              => 'date_picker',
						'display_format'    => $date_format,
						'return_format'     => 'Y-m-d',
						'instructions'      => __( 'Optional. Leave blank to show as soon as enabled.', 'custom-store-logic' ),
						'conditional_logic' => $enabled_condition,
					),
					array(
						'key'               => 'field_csl_promo_banner_end_date',
						'name'              => 'csl_promo_banner_end_date',
						'label'             => __( 'End Date', 'custom-store-logic' ),
						'type'              => 'date_picker',
						'display_format'    => $date_format,
						'return_format'     => 'Y-m-d',
						'instructions'      => __( 'Optional. Leave blank to show indefinitely.', 'custom-store-logic' ),
						'conditional_logic' => $enabled_condition,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => CSL_OPTIONS_PAGE_SLUG,
						),
					),
				),
			)
		);
	}

	/**
	 * Only loaded on the product edit screen -- this script just populates
	 * the Bundle Components "Links" column, so it has no reason to load
	 * anywhere else in wp-admin.
	 */
	public static function enqueue_bundle_admin_script( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		$relative_path = 'assets/js/bundle-admin.js';

		wp_enqueue_script(
			'csl-bundle-admin',
			plugins_url( $relative_path, CSL_PLUGIN_FILE ),
			array( 'jquery' ),
			csl_asset_version( $relative_path ),
			true
		);

		wp_localize_script(
			'csl-bundle-admin',
			'cslBundleAdmin',
			array(
				'adminUrl'       => admin_url(),
				'homeUrl'        => home_url(),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'stockNonce'     => wp_create_nonce( 'csl_get_product_stock' ),
				'editLabel'      => __( 'Edit', 'custom-store-logic' ),
				'viewLabel'      => __( 'View', 'custom-store-logic' ),
				'notManagedText' => __( 'Not tracked', 'custom-store-logic' ),
				'inStockText'    => __( 'In stock', 'custom-store-logic' ),
				'outOfStockText' => __( 'Out of stock', 'custom-store-logic' ),
				'onBackorderText' => __( 'On backorder', 'custom-store-logic' ),
			)
		);
	}

	/**
	 * Returns the current stock count/status for a single component product,
	 * for the "Inventory Count" column in the Bundle Components table. Looked
	 * up live via AJAX rather than embedded at page load, so it reflects
	 * stock changes made elsewhere while this screen stays open and works
	 * for rows added after the initial page load.
	 */
	public static function ajax_get_product_stock() {
		check_ajax_referer( 'csl_get_product_stock', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'custom-store-logic' ) ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'custom-store-logic' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'manage_stock'   => $product->managing_stock(),
				'stock_quantity' => $product->get_stock_quantity(),
				'stock_status'   => $product->get_stock_status(),
			)
		);
	}
}
