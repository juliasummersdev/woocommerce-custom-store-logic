<?php
defined( 'ABSPATH' ) || exit;

/**
 * A "Bundles" section on the homepage, above the main Shop product grid,
 * listing every bundle product. Reuses WooCommerce's own product-loop markup
 * (`content-product.php`, the same template part the Shop grid itself uses)
 * so bundle tiles look and behave identically to regular product tiles --
 * same thumbnail/title/price/add-to-cart markup, same theme CSS classes, no
 * separate template to maintain. The loop construction here mirrors
 * WooCommerce's own `single-product/up-sells.php` template.
 */
class CSL_Bundle_Showcase {

	public static function init() {
		// Priority 15: `woocommerce_before_main_content` is also where
		// WooCommerce's own `woocommerce_output_content_wrapper()` opens the
		// page's content wrapper, at priority 10 -- 15 puts this section
		// right after that, above the Shop page's title and product grid.
		add_action( 'woocommerce_before_main_content', array( __CLASS__, 'render_bundle_showcase' ), 15 );
		// Only enqueued when the section will actually render -- see enqueue_styles().
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * @return WC_Product[]
	 */
	private static function get_bundle_products() {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => 'csl_is_bundle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);

		return array_filter( array_map( 'wc_get_product', $ids ) );
	}

	private static function should_render() {
		return is_front_page() && ! empty( self::get_bundle_products() );
	}

	/**
	 * Echoed directly rather than templated -- there's no dedicated
	 * template file to override since this section doesn't exist anywhere
	 * in WooCommerce core, so a theme dev would customize it by unhooking
	 * this and hooking their own callback instead, same as any other action.
	 */
	public static function render_bundle_showcase() {
		$bundles = self::get_bundle_products();

		if ( ! is_front_page() || empty( $bundles ) ) {
			return;
		}
		?>
		<section class="csl-bundle-showcase products">
            <header class="woocommerce-products-header">
                <h1 class="woocommerce-products-header__title page-title"><?php esc_html_e( 'Bundles', 'custom-store-logic' ); ?></h1>
            </header>

			<?php woocommerce_product_loop_start(); ?>

			<?php foreach ( $bundles as $bundle ) : ?>
				<?php
				setup_postdata( $GLOBALS['post'] = get_post( $bundle->get_id() ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found
				wc_get_template_part( 'content', 'product' );
				?>
			<?php endforeach; ?>

			<?php woocommerce_product_loop_end(); ?>
		</section>
		<?php
		wp_reset_postdata();
	}

	/**
	 * Re-checks `should_render()` independently of `render_bundle_showcase()`
	 * (a fresh query, not shared state) so the stylesheet is never loaded on
	 * a page where the section won't actually show.
	 */
	public static function enqueue_styles() {
		if ( ! self::should_render() ) {
			return;
		}

		$relative_path = 'assets/css/bundle-showcase.css';

		wp_enqueue_style(
			'csl-bundle-showcase',
			plugins_url( $relative_path, CSL_PLUGIN_FILE ),
			array(),
			csl_asset_version( $relative_path )
		);
	}
}
