<?php
/**
 * Plugin Name:       Woocommerce Custom Store Logic
 * Description:       Custom WooCommerce cart, checkout, and product logic (bundles, size/fit variants, local pickup scheduling) built on Secure Custom Fields. 
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            juliasummers.dev
 * License:           GPL-2.0-or-later
 * Text Domain:       custom-store-logic
 */

defined( 'ABSPATH' ) || exit;

define( 'CSL_PLUGIN_FILE', __FILE__ );
define( 'CSL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Slug of the shared SCF options page. Pickup locations (Phase 1) and
 * shipping rules / promo banners / announcements (Phase 3) all live on this
 * one staff-facing page rather than each field group creating its own menu
 * item.
 */
define( 'CSL_OPTIONS_PAGE_SLUG', 'csl-store-settings' );

/**
 * Cache-busts a plugin CSS/JS URL with the file's own last-modified time
 * (unix timestamp) instead of a hardcoded version string, so a browser (or
 * any intermediary cache) fetches a fresh copy the moment the file changes
 * on disk -- no manual version bump to remember on every edit.
 */
function csl_asset_version( $relative_path ) {
	$path = CSL_PLUGIN_DIR . ltrim( $relative_path, '/' );
	return file_exists( $path ) ? (string) filemtime( $path ) : '0.1.0';
}

require_once CSL_PLUGIN_DIR . 'includes/class-options-page.php';
require_once CSL_PLUGIN_DIR . 'includes/class-scf-fields.php';
require_once CSL_PLUGIN_DIR . 'includes/class-cart-pricing.php';
require_once CSL_PLUGIN_DIR . 'includes/class-bundle-display.php';
require_once CSL_PLUGIN_DIR . 'includes/class-order-meta.php';
require_once CSL_PLUGIN_DIR . 'includes/class-pickup-scheduling.php';
require_once CSL_PLUGIN_DIR . 'includes/class-promo-banner.php';
require_once CSL_PLUGIN_DIR . 'includes/class-bundle-showcase.php';

CSL_Options_Page::init();
CSL_SCF_Fields::init();
CSL_Cart_Pricing::init();
CSL_Bundle_Display::init();
CSL_Order_Meta::init();
CSL_Pickup_Scheduling::init();
CSL_Promo_Banner::init();
CSL_Bundle_Showcase::init();

/**
 * Per the "fail gracefully without SCF" architecture principle: field
 * registration itself is a no-op if SCF is inactive, because it's hooked to
 * `acf/init`, which SCF is the one firing. Nothing here can fatal-error on a
 * missing SCF -- but staff should still see *why* bundle/variant/pickup
 * fields have disappeared from the product editor, hence this notice.
 */
function csl_missing_scf_notice() {
	if ( function_exists( 'acf_add_local_field_group' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>' .
		esc_html__( 'Custom Store Logic: Secure Custom Fields (SCF) is not active. Bundle, size/fit, and local-pickup fields will not appear until SCF is installed and activated.', 'custom-store-logic' ) .
		'</p></div>';
}
add_action( 'admin_notices', 'csl_missing_scf_notice' );
