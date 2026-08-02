<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the single SCF options page used for staff-manageable, store-wide
 * settings. Kept as one page (rather than one per feature) so non-technical
 * staff have a single, predictable place to look -- see README "Staff-safe
 * settings only in the options page" principle.
 *
 * Phase 1 puts pickup locations here. Phase 3 adds shipping rules, promo
 * banner content, and site announcements as further field groups targeting
 * the same page.
 */
class CSL_Options_Page {

	public static function init() {
		// Registers the "Store Settings" admin page itself -- must run before
		// any field group can target it via `'options_page' => CSL_OPTIONS_PAGE_SLUG`.
		add_action( 'acf/init', array( __CLASS__, 'register_options_page' ) );
	}

	/**
	 * Menu position 58 (top-level, no `parent_slug`) groups this page with
	 * WooCommerce's own menu items rather than under Settings, since it's a
	 * store-management page store managers need daily, not a one-off config
	 * screen.
	 */
	public static function register_options_page() {
		if ( ! function_exists( 'acf_add_options_page' ) ) {
			return;
		}

		acf_add_options_page(
			array(
				'page_title' => __( 'Store Settings', 'custom-store-logic' ),
				'menu_title' => __( 'Store Settings', 'custom-store-logic' ),
				'menu_slug'  => CSL_OPTIONS_PAGE_SLUG,
				/**
				 * `manage_woocommerce` (not `manage_options`) so store
				 * managers who aren't full site admins can still update
				 * pickup locations, shipping rules, etc.
				 */
				'capability' => 'manage_woocommerce',
				'redirect'   => false,
				'icon_url'   => 'dashicons-store',
				'position'   => 58,
			)
		);
	}
}
