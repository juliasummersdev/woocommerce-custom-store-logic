<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renders a bundle's component list (thumbnail, name, regular price, link)
 * where the product description would normally appear, so a customer can
 * see exactly what's included before adding the bundle to their cart. Staff
 * can turn this off per-product via the "Show component list" bundle field,
 * e.g. if they'd rather rely on their own description copy instead.
 */
class CSL_Bundle_Display {

	public static function init() {
		// Forces the "Description" tab to exist even if the bundle has no written description text, so the component list below always has somewhere to render.
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'ensure_description_tab' ), 20 );
		// Swaps the default "Sale!" badge for "Bundle Discount" on bundle products.
		add_filter( 'woocommerce_sale_flash', array( __CLASS__, 'bundle_sale_flash' ), 10, 3 );
		// Priority 11: right after `woocommerce_template_single_price` (10), before the excerpt (20).
		// Shows the discount rule + total amount saved just under the price.
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_discount_info' ), 11 );
		// Appends each component's own image to the bundle's product-page gallery.
		add_filter( 'woocommerce_product_get_gallery_image_ids', array( __CLASS__, 'add_component_images_to_gallery' ), 10, 2 );
		// Renders those appended gallery images even when the bundle itself has no main image (core template would otherwise skip them).
		add_action( 'woocommerce_product_thumbnails', array( __CLASS__, 'render_thumbnails_when_no_main_image' ), 15 );
		// Rebuilds the placeholder "no image" gallery slide's markup so the zoom/slider JS doesn't misindex it against the real component slides.
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'fix_placeholder_gallery_slide' ), 10, 2 );
		// Loads assets/css/bundle-components.css, only on bundle product pages.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	private static function is_bundle( $product_id ) {
		return function_exists( 'get_field' ) && get_field( 'csl_is_bundle', $product_id );
	}

	/**
	 * Meta is checked directly (not just `get_field()`'s `default_value`)
	 * because ACF/SCF default values only apply on a fresh add-new form --
	 * a bundle saved before this field existed has no stored value at all,
	 * and should still default to showing the list rather than hiding it.
	 */
	private static function should_show_components( $product_id ) {
		if ( ! self::is_bundle( $product_id ) ) {
			return false;
		}

		$raw = get_post_meta( $product_id, 'csl_bundle_show_components', true );
		return '' === $raw ? true : (bool) $raw;
	}

	/**
	 * Same "meta checked directly" reasoning as `should_show_components()`
	 * -- a bundle saved before this field existed should still default to
	 * showing component images rather than hiding them.
	 */
	private static function should_show_gallery_images( $product_id ) {
		if ( ! self::is_bundle( $product_id ) ) {
			return false;
		}

		$raw = get_post_meta( $product_id, 'csl_bundle_show_gallery_images', true );
		return '' === $raw ? true : (bool) $raw;
	}

	/**
	 * WooCommerce only registers the "Description" tab at all if the
	 * product has description text (`woocommerce_default_product_tabs()`
	 * checks `$post->post_content`). A bundle with no written description
	 * would otherwise show no component list, so this adds/overrides the
	 * tab whenever a bundle wants its list shown, regardless of whether it
	 * also has written description text.
	 */
	public static function ensure_description_tab( $tabs ) {
		global $product;

		if ( ! $product instanceof WC_Product || ! self::should_show_components( $product->get_id() ) ) {
			return $tabs;
		}

		$tabs['description'] = array(
			'title'    => isset( $tabs['description']['title'] ) ? $tabs['description']['title'] : __( 'Description', 'woocommerce' ),
			'priority' => isset( $tabs['description']['priority'] ) ? $tabs['description']['priority'] : 10,
			'callback' => array( __CLASS__, 'render_description_tab' ),
		);

		return $tabs;
	}

	/**
	 * Replaces the default "Sale!" flash with "Bundle Discount" for bundle
	 * products, reusing WooCommerce's own `.onsale` class (plus a distinct
	 * modifier class) so it's styled identically without duplicating/drifting
	 * from the theme's actual badge CSS. Applies wherever WooCommerce shows
	 * the sale flash -- shop loop and single product page both call this
	 * same filter, and a bundle should read the same way in both places.
	 */
	public static function bundle_sale_flash( $html, $post, $product ) {
		if ( ! $product instanceof WC_Product || ! self::is_bundle( $product->get_id() ) ) {
			return $html;
		}

		return '<span class="onsale csl-bundle-onsale">' . esc_html__( 'Bundle Discount', 'custom-store-logic' ) . '</span>';
	}

	/**
	 * Appends each component's own first/featured image to the bundle's
	 * gallery, so a customer can see what's included without leaving the
	 * page. This only filters `get_gallery_image_ids()` (the "additional
	 * images" below/beside the main photo) -- WooCommerce's own template
	 * always renders the bundle's featured image (or its placeholder if
	 * none is set) as the first/main image regardless, so that position is
	 * untouched here. Skipped in wp-admin so the Product Gallery meta box
	 * doesn't show images that aren't actually part of the bundle's own
	 * stored gallery.
	 */
	public static function add_component_images_to_gallery( $gallery_image_ids, $product ) {
		if ( is_admin() || ! $product instanceof WC_Product || ! self::should_show_gallery_images( $product->get_id() ) ) {
			return $gallery_image_ids;
		}

		$components = get_field( 'csl_bundle_components', $product->get_id() );
		if ( empty( $components ) ) {
			return $gallery_image_ids;
		}

		$seen_ids = array_merge( array( $product->get_image_id() ), $gallery_image_ids );

		foreach ( $components as $component ) {
			$component_product = wc_get_product( $component['csl_component_product'] );
			if ( ! $component_product ) {
				continue;
			}

			$image_id = $component_product->get_image_id();
			if ( $image_id && ! in_array( $image_id, $seen_ids, true ) ) {
				$gallery_image_ids[] = $image_id;
				$seen_ids[]          = $image_id;
			}
		}

		return $gallery_image_ids;
	}

	/**
	 * WooCommerce's own `single-product/product-thumbnails.php` only loops
	 * over gallery images `if ( $attachment_ids && $product->get_image_id() )`
	 * -- it requires the product to ALSO have its own main image, which a
	 * bundle isn't required to (it falls back to the "Awaiting product
	 * image" placeholder). That core condition would otherwise hide the
	 * component images appended in `add_component_images_to_gallery()`
	 * entirely whenever the bundle has no image of its own. This renders
	 * them the same way the core template would, but only for that specific
	 * case the core template skips -- when a bundle *does* have its own
	 * image, the core loop already runs normally and this is a no-op.
	 */
	public static function render_thumbnails_when_no_main_image() {
		global $product;

		if ( ! $product instanceof WC_Product || $product->get_image_id() || ! self::is_bundle( $product->get_id() ) ) {
			return;
		}

		if ( ! function_exists( 'wc_get_gallery_image_html' ) ) {
			return;
		}

		$attachment_ids = $product->get_gallery_image_ids();
		foreach ( $attachment_ids as $key => $attachment_id ) {
			echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', wc_get_gallery_image_html( $attachment_id, false, $key ), $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * WooCommerce's placeholder branch in `single-product/product-image.php`
	 * renders a plain `<div class="woocommerce-product-gallery__image--placeholder">`
	 * with no `data-thumb`/`data-large_image` attributes -- fine on its own,
	 * since a product with no image normally has no gallery images either.
	 * But once a bundle *does* have real gallery slides alongside it (from
	 * `add_component_images_to_gallery()`), that mismatched markup is what
	 * caused the reported bugs: the zoom script had no large-image size to
	 * compute a sane ratio from (so it rendered zoomed in), and because the
	 * placeholder has no `data-thumb`, the slider's thumbnail nav only had
	 * entries for the *other* slides -- shifting every click's slide/thumb
	 * pairing off by one. This rebuilds the placeholder with the same
	 * `data-thumb` + `.woocommerce-product-gallery__image` structure
	 * `wc_get_gallery_image_html()` gives every other slide, so it behaves
	 * as a normal, correctly-indexed first slide instead of a special case.
	 */
	private static function get_placeholder_gallery_slide_html() {
		$alt = __( 'Awaiting product image', 'woocommerce' );
		$src = wc_placeholder_img_src( 'woocommerce_single' );
		$image = wc_placeholder_img(
			'woocommerce_single',
			array(
				'class' => 'wp-post-image',
				'alt'   => $alt,
			)
		);

		return '<div data-thumb="' . esc_url( $src ) . '" data-thumb-alt="' . esc_attr( $alt ) . '" class="woocommerce-product-gallery__image">'
			. '<a href="' . esc_url( $src ) . '">' . $image . '</a>'
			. '</div>';
	}

	/**
	 * `$attachment_id` is falsy only for the placeholder "no image" slide --
	 * every real gallery image goes through this filter with a real ID and
	 * is returned untouched. See `get_placeholder_gallery_slide_html()`
	 * above for why the placeholder specifically needs rebuilding.
	 */
	public static function fix_placeholder_gallery_slide( $html, $attachment_id ) {
		global $product;

		if ( $attachment_id || ! $product instanceof WC_Product || ! self::is_bundle( $product->get_id() ) ) {
			return $html;
		}

		if ( empty( $product->get_gallery_image_ids() ) ) {
			return $html;
		}

		return self::get_placeholder_gallery_slide_html();
	}

	private static function get_discount_type_label( $pricing_rule ) {
		switch ( $pricing_rule ) {
			case 'percent_discount':
				return __( 'Percentage Discount', 'custom-store-logic' );
			case 'custom_total':
				return __( 'Custom Bundle Price', 'custom-store-logic' );
			case 'fixed_discount':
			default:
				return __( 'Flat Discount', 'custom-store-logic' );
		}
	}

	private static function get_discount_value_display( $pricing_rule, $pricing_value ) {
		if ( 'percent_discount' === $pricing_rule ) {
			return esc_html( $pricing_value . '%' );
		}
		return wc_price( $pricing_value );
	}

	/**
	 * Shows the discount rule (type + value) and the total dollar amount
	 * saved off the components' combined price, directly under the price
	 * on the single product page.
	 */
	public static function render_discount_info() {
		global $product;

		if ( ! $product instanceof WC_Product || ! self::is_bundle( $product->get_id() ) ) {
			return;
		}

		$breakdown = CSL_Cart_Pricing::get_bundle_price_breakdown( $product->get_id() );
		if ( ! $breakdown ) {
			return;
		}

		$pricing_rule  = get_field( 'csl_bundle_pricing_rule', $product->get_id() );
		$pricing_value = (float) get_field( 'csl_bundle_pricing_value', $product->get_id() );
		?>
		<div class="csl-bundle-discount">
			<div class="csl-bundle-discount__rule">
				<span class="csl-bundle-discount__type"><?php echo esc_html( self::get_discount_type_label( $pricing_rule ) ); ?></span>
                of
				<span class="csl-bundle-discount__value"><?php echo wp_kses_post( self::get_discount_value_display( $pricing_rule, $pricing_value ) ); ?></span>
			</div>
			<div class="csl-bundle-discount__savings">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: amount saved, 2: combined price of all components */
						__( 'You save %1$s off the %2$s combined price', 'custom-store-logic' ),
						wc_price( $breakdown['savings'] ),
						wc_price( $breakdown['component_total'] )
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	public static function render_description_tab() {
		global $post, $product;

		if ( $post->post_content ) {
			echo '<h2>' . esc_html( apply_filters( 'woocommerce_product_description_heading', __( 'Description', 'woocommerce' ) ) ) . '</h2>';
			the_content();
		}

		self::render_components_list( $product->get_id() );
	}

	private static function render_components_list( $product_id ) {
		$components = get_field( 'csl_bundle_components', $product_id );
		if ( empty( $components ) ) {
			return;
		}
		?>
		<div class="csl-bundle-components">
			<h2><?php esc_html_e( "What's in this bundle", 'custom-store-logic' ); ?></h2>
			<ul class="csl-bundle-components__list">
				<?php
				foreach ( $components as $component ) :
					$component_product = wc_get_product( $component['csl_component_product'] );
					if ( ! $component_product ) {
						continue;
					}
					/**
					 * Variable products often have no `_regular_price` of
					 * their own at the parent level (it lives per-variation)
					 * -- fall back to the effective price rather than
					 * showing a misleading $0.00.
					 */
					$regular_price = $component_product->get_regular_price();
					if ( '' === $regular_price ) {
						$regular_price = $component_product->get_price();
					}
					?>
					<li class="csl-bundle-components__item">
						<a class="csl-bundle-components__thumb" href="<?php echo esc_url( $component_product->get_permalink() ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo wp_kses_post( $component_product->get_image( 'woocommerce_thumbnail' ) ); ?>
						</a>
						<div class="csl-bundle-components__details">
							<a class="csl-bundle-components__name" href="<?php echo esc_url( $component_product->get_permalink() ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $component_product->get_name() ); ?>
							</a>
							<span class="csl-bundle-components__price">
								<?php esc_html_e( 'Regularly:', 'custom-store-logic' ); ?>
								<?php echo wp_kses_post( wc_price( $regular_price ) ); ?>
							</span>
						</div>
					</li>
					<?php
				endforeach;
				?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Looks up the product via the queried object instead of the global
	 * `$product` -- `wp_enqueue_scripts` fires before WooCommerce populates
	 * that global (it's set up per-post on the `the_post` hook, later in the
	 * main Loop), so relying on it here silently skipped the stylesheet on
	 * every load.
	 */
	public static function enqueue_styles() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product || ! self::is_bundle( $product->get_id() ) ) {
			return;
		}

		$relative_path = 'assets/css/bundle-components.css';

		wp_enqueue_style(
			'csl-bundle-components',
			plugins_url( $relative_path, CSL_PLUGIN_FILE ),
			array(),
			csl_asset_version( $relative_path )
		);
	}
}
