<?php
defined( 'ABSPATH' ) || exit;

/**
 * Site-wide promo banner, staff-configured on the shared options page
 * (`csl_promo_banner_*` fields, `class-scf-fields.php`). Rendered on
 * `wp_body_open` -- a core WP action, not a Storefront-specific one -- so
 * this keeps working if the theme is ever switched, per the README "Minimal
 * theme footprint" principle.
 */
class CSL_Promo_Banner {

	public static function init() {
		// Echoes the banner markup at the top of the page body, if active.
		add_action( 'wp_body_open', array( __CLASS__, 'render_banner' ) );
		// Only enqueued when the banner will actually render -- see enqueue_styles().
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Read fresh on every request (not cached) so a staff edit takes effect
	 * immediately, matching how other options-page settings are read
	 * elsewhere in this plugin (e.g. pickup locations).
	 */
	private static function get_settings() {
		if ( ! function_exists( 'get_field' ) ) {
			return null;
		}

		return array(
			'enabled'    => (bool) get_field( 'csl_promo_banner_enabled', 'option' ),
			'text'       => (string) get_field( 'csl_promo_banner_text', 'option' ),
			'link'       => self::resolve_link(),
			'start_date' => (string) get_field( 'csl_promo_banner_start_date', 'option' ),
			'end_date'   => (string) get_field( 'csl_promo_banner_end_date', 'option' ),
		);
	}

	/**
	 * `csl_promo_banner_link_page` is a `select` whose choices are "Custom
	 * URL" (value `custom`) followed by every published page/post by ID --
	 * selecting an actual page/post resolves to its permalink live (not
	 * stored), so the banner link stays correct if that page is later
	 * renamed/moved. The custom URL field is used verbatim, unvalidated, so
	 * staff can enter anything a strict `url` field type would reject (a
	 * relative path, `mailto:`, etc.).
	 */
	private static function resolve_link() {
		$selection = get_field( 'csl_promo_banner_link_page', 'option' );

		if ( $selection && 'custom' !== $selection ) {
			$permalink = get_permalink( (int) $selection );
			if ( $permalink ) {
				return $permalink;
			}
		}

		return (string) get_field( 'csl_promo_banner_link_url', 'option' );
	}

	/**
	 * True if the banner is enabled, has text to show, and today falls
	 * within its configured date range -- an unset start/end date leaves
	 * that side of the range unbounded.
	 */
	private static function is_active( $settings ) {
		if ( ! $settings || ! $settings['enabled'] || '' === trim( $settings['text'] ) ) {
			return false;
		}

		$today = current_time( 'Y-m-d' );

		if ( $settings['start_date'] && $today < $settings['start_date'] ) {
			return false;
		}

		if ( $settings['end_date'] && $today > $settings['end_date'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Echoes the banner markup directly on `wp_body_open` -- no template
	 * override needed, so this works regardless of which theme is active.
	 */
	public static function render_banner() {
		$settings = self::get_settings();

		if ( ! self::is_active( $settings ) ) {
			return;
		}

		$text = esc_html( $settings['text'] );

		if ( $settings['link'] ) {
			$text = '<a href="' . esc_url( $settings['link'] ) . '">' . $text . '</a>';
		}

		echo '<div class="csl-promo-banner">' . wp_kses_post( $text ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $text built from esc_html()/esc_url() above.
	}

	/**
	 * Re-checks `is_active()` independently of `render_banner()` (a fresh
	 * `get_settings()` call, not shared state) so the stylesheet is never
	 * loaded on a page where the banner won't actually show.
	 */
	public static function enqueue_styles() {
		if ( ! self::is_active( self::get_settings() ) ) {
			return;
		}

		$relative_path = 'assets/css/promo-banner.css';

		wp_enqueue_style(
			'csl-promo-banner',
			plugins_url( $relative_path, CSL_PLUGIN_FILE ),
			array(),
			csl_asset_version( $relative_path )
		);
	}
}
