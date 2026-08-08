# Custom Store Logic for WooCommerce

A custom WooCommerce plugin that allows for product bundles with dynamic pricing,
size/fit variant data, and local-pickup scheduling for the WooCommerce
**block** checkout.

## Features

- **Product bundles** — configurable components (linked product, quantity,
  required/optional) with flat, percentage, or custom-price discount rules.
  Cart/checkout pricing, a component list + gallery images on the product
  page, a "Bundle Discount" badge with savings breakdown, and a homepage
  "Bundles" showcase section.
- **Size/fit variant data** — `csl_fit_profile` / `csl_size_notes` fields
  modeled at the WooCommerce variation level via SCF.
- **Local pickup scheduling** — location, date, and time selection built on
  WooCommerce Blocks' Additional Checkout Fields API (not the classic
  checkout shortcode), with server-side validation of business hours,
  blackout dates, and a free "Local Pickup" shipping rate injected
  alongside real shipping methods.
- **Site-wide promo banner** — staff-editable text, link (custom URL or an
  existing page/post), and an optional active date range, rendered on
  `wp_body_open` so it survives a theme switch.
- **Order-meta snapshots** — bundle contents and pickup details are saved
  to the order at checkout and displayed on the thank-you page, in both
  HTML and plain-text order emails, and in the wp-admin order screen.

## Requirements

- WordPress 6.4+
- WooCommerce (with **block-based checkout** — the pickup-scheduling
  feature specifically targets the Store API / Checkout block, not the
  classic `[woocommerce_checkout]` shortcode)
- [Secure Custom Fields](https://wordpress.org/plugins/secure-custom-fields/)
  (or ACF Pro) — field registration fails gracefully (admin notice, no
  fatal error) if this is inactive
- PHP 7.4+

## Installation

1. Copy this directory to `wp-content/plugins/woocommerce-custom-store-logic`.
2. Install and activate **Secure Custom Fields**.
3. Activate **Custom Store Logic** from the Plugins screen.
4. Go to **WooCommerce → Store Settings** to configure pickup locations and
   the promo banner.

## Architecture

Custom logic lives entirely in this plugin — not the theme — so it's
upgrade-safe against WooCommerce core updates and theme changes.

```
woocommerce-custom-store-logic/
  woocommerce-woocommerce-custom-store-logic.php   plugin bootstrap
  includes/
    class-scf-fields.php          SCF field group registration (bundles, variants, pickup, promo banner)
    class-options-page.php        shared SCF options page ("Store Settings")
    class-cart-pricing.php        bundle pricing (cart/checkout + WooCommerce price meta sync)
    class-bundle-display.php      bundle component list + gallery on the single product page
    class-bundle-showcase.php     homepage "Bundles" section, above the Shop grid
    class-order-meta.php          bundle-contents order-item-meta save/display
    class-pickup-scheduling.php   pickup slot selection, validation, and display (block checkout)
    class-promo-banner.php        site-wide promo banner, options-page driven
  assets/
    css/                          bundle components, bundle showcase, promo banner styling
    js/                           admin: Edit/View links + stock count on the Bundle Components table
  docs/
    field-reference.md            dev-facing SCF field group/key reference
    staff-guide-promo-banners.md  staff walkthrough for the promo banner
```

### Key hooks in use

| Purpose | Hook |
| --- | --- |
| Bundle pricing at cart level | `woocommerce_before_calculate_totals` |
| Bundle component list on the product page | `woocommerce_product_tabs` |
| Block add-to-cart if a required bundle component is out of stock | `woocommerce_add_to_cart_validation` |
| Save bundle-contents snapshot to the order line item at checkout | `woocommerce_checkout_create_order_line_item` |
| Display that snapshot on order confirmation / emails | `woocommerce_order_item_meta_end` |
| Display custom data in the admin order view | `woocommerce_admin_order_item_headers` / `woocommerce_admin_order_item_values` |
| Local pickup slot selection at checkout (block checkout only) | `woocommerce_register_additional_checkout_field()` / `woocommerce_store_api_checkout_order_processed` |
| Free "Local Pickup" shipping rate | `woocommerce_package_rates` |
| Site-wide promo banner | `wp_body_open` |
| Homepage "Bundles" section | `woocommerce_before_main_content` |

## Documentation

- [`docs/field-reference.md`](docs/field-reference.md) — SCF field group and
  key reference for developers.
- [`docs/staff-guide-promo-banners.md`](docs/staff-guide-promo-banners.md) —
  a plain-language walkthrough for non-technical staff updating the promo
  banner.

## License

GPL-2.0-or-later
