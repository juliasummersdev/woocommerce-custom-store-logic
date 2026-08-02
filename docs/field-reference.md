# Field Reference

Developer reference for SCF field group keys and field names registered in
`includes/class-scf-fields.php`. All field groups are registered in PHP via
`acf_add_local_field_group()` (not the SCF admin UI) so they're version
controlled — see README "Field groups registered via PHP" principle. This
is dev-facing documentation; staff-facing guides live alongside it in this
same `docs/` folder (`staff-guide-*.md`, added in Phase 3).

## Bundle Settings — `group_csl_bundle_fields`

Location: Post Type == `product`

| Field name | Type | Notes |
| --- | --- | --- |
| `csl_is_bundle` | true_false | Master switch; other bundle fields are conditional on this. |
| `csl_bundle_components` | repeater | Table layout. |
| `csl_bundle_components.csl_component_product` | post_object | Filtered to `product` post type, returns ID. |
| `csl_bundle_components.csl_component_quantity` | number | Min 1, default 1. |
| `csl_bundle_components.csl_component_required` | true_false | "Inventory Required" — checked blocks add-to-cart if this component is out of stock; unchecked means this component's stock status doesn't affect whether the bundle can be sold. Doesn't affect pricing or let the customer deselect the component (see TASKS.md note). |
| `csl_bundle_components.csl_component_stock` | message | "Inventory Count" column, admin-only. Empty by design — populated client-side via an AJAX call (`csl_get_product_stock` action, `class-scf-fields.php`) for whichever product is selected in that row, so it reflects live stock rather than what was true at page load. |
| `csl_bundle_components.csl_component_links` | message | "Links" column, admin-only. Empty by design — populated client-side (`assets/js/bundle-admin.js`) with Edit/View links for whichever product is selected in that row. |
| `csl_bundle_pricing_rule` | select | `fixed_discount` \| `percent_discount` \| `custom_total`. |
| `csl_bundle_pricing_value` | number | Meaning depends on `csl_bundle_pricing_rule`. |

## Size/Fit Variant Settings — `group_csl_variant_fields`

Location: Post Type == `product_variation`

**Important:** SCF has no built-in location rule or render path for the
WooCommerce variation row — variations are edited via an AJAX-loaded partial
on the parent product screen, not a standard post-edit screen. This field
group defines the schema and makes `get_field()` / `update_field()` work
against a variation's post ID, but Phase 2 must render and save these fields
manually via `woocommerce_product_after_variable_attributes` and
`woocommerce_save_product_variation`.

| Field name | Type | Notes |
| --- | --- | --- |
| `csl_fit_profile` | select | `slim` \| `regular` \| `relaxed` \| `oversized`. Default `regular`. |
| `csl_size_notes` | textarea | Customer-facing fit note on the product page. |

## Local Pickup

### Product toggle — `group_csl_pickup_product_fields`

Location: Post Type == `product`

| Field name | Type | Notes |
| --- | --- | --- |
| `csl_pickup_enabled` | true_false | Whether this product can be picked up locally. |

### Pickup Locations — `group_csl_pickup_locations`

Location: Options Page == `csl-store-settings` (constant `CSL_OPTIONS_PAGE_SLUG`)

| Field name | Type | Notes |
| --- | --- | --- |
| `csl_pickup_locations` | repeater | Block layout; one entry per physical location. **No stable ID field** — `class-pickup-scheduling.php` uses `csl_location_name` itself as the location's identifier in checkout fields and order meta, so keep names unique. |
| `csl_pickup_locations.csl_location_name` | text | Required. |
| `csl_pickup_locations.csl_location_address_1` / `csl_location_address_2` | text | Street address, split from a single free-text field so it can be composed consistently (e.g. one line vs. two) everywhere it's displayed. |
| `csl_pickup_locations.csl_location_city` / `csl_location_state` / `csl_location_zip` | text | Rendered as `City, State ZIP` on a line under the address. `CSL_Pickup_Scheduling::format_location_address()` is the single place all five of these fields are joined into the multi-line address string used for display, the Google Maps link, and the `_csl_pickup_address` order-meta snapshot. |
| `csl_pickup_locations.csl_location_hours` | repeater | Table layout, nested inside a location. |
| `csl_location_hours.csl_hours_day` | select | `mon`..`sun`. |
| `csl_location_hours.csl_hours_opens` / `csl_hours_closes` | time_picker | Stored as `H:i` (24h). Time slots for checkout are generated as fixed 60-minute blocks between these (`CSL_Pickup_Scheduling::SLOT_LENGTH_MINUTES`) — there's no separate slot-length field. There's no per-slot capacity limit — any number of orders can book the same location/date/time slot. |
| `csl_pickup_locations.csl_location_blackout_dates` | repeater | Table layout. |
| `csl_location_blackout_dates.csl_blackout_date` | date_picker | Stored (`return_format`) as `Y-m-d` regardless of display. `display_format` is set dynamically from `get_option('date_format')` at registration time (falls back to `F j, Y` if that option is somehow empty), so it always matches whatever format is chosen in Settings > General, re-read fresh on every `acf/init`. |

## Order Item Meta — `includes/class-order-meta.php`

Not an SCF field group — this is order-item meta written directly via
`WC_Order_Item::add_meta_data()` at checkout, snapshotting bundle contents onto
the order so later edits to the bundle don't retroactively change what old
orders appear to contain.

| Meta key | Written | Notes |
| --- | --- | --- |
| `_csl_bundle_components` | `woocommerce_checkout_create_order_line_item` | Array of `{ name, quantity, product_id }` per component, snapshotted at checkout. `quantity` is *per one bundle* (e.g. "1 hair mask per bundle"), not multiplied by how many bundles were ordered — `get_total_component_quantity()` multiplies it by the line item's own `get_quantity()` at *display* time only (so "Hair Mask Bundle × 3" correctly shows "Premium Hair Mask × 3", not × 1), keeping the stored snapshot itself as the simple per-bundle spec. Underscore-prefixed so WooCommerce's default item-meta display doesn't also dump it raw — `CSL_Order_Meta` renders it itself on the thank-you page, in order emails (HTML + plain-text), and as a "Bundle Contents" column in wp-admin, all three going through the same `get_total_component_quantity()` helper. Only written for bundle products (`csl_is_bundle`); absent on ordinary line items. On the thank-you page / HTML emails, each component name links to its product page (new tab) via `product_id`; orders placed before `product_id` was added to the snapshot fall back to matching the bundle's *current* components by name (`get_component_product_id()`) so older orders can still link out where possible. |
| `_csl_pickup_location` / `_csl_pickup_date` / `_csl_pickup_time` / `_csl_pickup_address` | `validate_order()` on `woocommerce_store_api_checkout_order_processed` (`class-pickup-scheduling.php`) | Order-level (not per-item) meta mirrored from the block-checkout `csl/pickup_location` / `csl/pickup_date` / `csl/pickup_time` additional checkout fields (see below), once validated, plus a snapshot of the location's address at that moment (`_csl_pickup_address`, since the location isn't otherwise snapshotted and could be renamed/removed later). `_csl_pickup_location` stores the location's *name* (no stable ID field exists on the repeater — see below), `_csl_pickup_date` is `Y-m-d`, `_csl_pickup_time` is the slot start time in `H:i`. Rendered as a "Pickup Details" block (with address + a Google Maps "Get Directions" link) via `CSL_Pickup_Scheduling` on the thank-you page, in order emails, and on the wp-admin order screen — and also used to override the order's *Shipping Address* display everywhere WooCommerce renders it (see below). |

## Checkout Fields (Block Checkout) — `includes/class-pickup-scheduling.php`

Not SCF fields — registered with WooCommerce Blocks' "Additional Checkout
Fields" API (`woocommerce_register_additional_checkout_field()`), since this
store's Checkout page uses the WooCommerce Checkout *block*, which doesn't
fire classic checkout hooks (`woocommerce_after_order_notes` etc.) at all.

| Field ID | Type | Notes |
| --- | --- | --- |
| `csl/pickup_location` | select | Options built from `csl_pickup_locations` (options page); each option's label includes the location's address. |
| `csl/pickup_date` | select | Next 30 days from today, **excluding any date where every configured location is closed or blacked out** (`date_available_at_any_location()`). With multiple locations on different schedules a date only disappears once *all* are closed for it — a location-specific mismatch is still caught server-side at submission. |
| `csl/pickup_time` | select | Fixed hourly slots, 7am–8pm; real validity for the chosen location/date is checked server-side. |

All three: `location => 'order'`. `required`/`hidden` are JSON-Schema rules
evaluated against `cart.shipping_rates` (whether the customer has *selected*
the `csl_local_pickup` shipping rate — see below) rather than `cart.items` —
the fields only appear once pickup is the actually-chosen fulfillment method,
not merely because a pickup-eligible product happens to be in the cart while
the customer intends to ship it normally. See
`selected_pickup_rate_schema()`/`register_checkout_fields()`.

## Free "Local Pickup" Shipping Rate — `includes/class-pickup-scheduling.php`

Not a registered `WC_Shipping_Method`/shipping zone entry — a `WC_Shipping_Rate`
(id `csl_local_pickup`, cost `0`) is injected directly into a package's
calculated rates (`woocommerce_package_rates`, `add_local_pickup_rate()`)
whenever that package contains a product flagged `csl_pickup_enabled`,
alongside whatever real shipping methods are configured (not replacing them,
so a mixed cart can still ship normally). Injecting directly into the
calculated rates means it works even with no shipping zones configured at
all. `order_selected_pickup()` checks an order's shipping line item for this
same rate ID to decide whether `validate_order()`'s pickup checks apply.

## Pickup Info Display Surfaces — `includes/class-pickup-scheduling.php`

Everywhere pickup details are shown to the customer or staff, and how each
one is wired up:

| Surface | Mechanism |
| --- | --- |
| "Pickup Details" block (thank-you page, both email formats, admin order screen) | `render_pickup_summary_thankyou()` / `render_pickup_summary_email()` / `render_pickup_summary_admin()` — plain hooks, full control over markup. |
| "Shipping Address" (thank-you page, emails, My Account, admin — all call `WC_Order::get_formatted_shipping_address()`) | `filter_formatted_shipping_address()` on `woocommerce_order_get_formatted_shipping_address`; `filter_shipping_address_map_url()` on `woocommerce_shipping_address_map_url` for WooCommerce's own existing "map" link (e.g. admin orders list table). |
| "Additional information" section on the order-confirmation **block** page — WooCommerce Blocks auto-renders `csl/pickup_location` (etc.) here since they're `location => 'order'` fields | `maybe_start_additional_fields_buffer()` (on `template_redirect`, only on the order-received page) + `enhance_additional_fields_html()`. **Necessary workaround, not a style choice:** the block's own rendering (`AbstractOrderConfirmationBlock::render_additional_field()`) runs the field value through `esc_html()`, so a clickable Get Directions link can't be produced through the field value itself. Tried the obvious fix first — hooking `render_block_woocommerce/order-confirmation-additional-fields` (and its `-wrapper` parent) — but neither filter actually fires for this block on this site's real order-received page (confirmed by fetching the live page authenticated as the order's own customer, not just testing the block in isolation); some other internal WooCommerce Blocks path composes that section without going through WordPress's `render_block()` pipeline for it. Buffering the whole page output and doing the same `<dt>Pickup Location</dt><dd>...</dd>` row replacement against the final HTML sidesteps needing to know which internal path actually produced it. |

All three build from the same `get_pickup_summary_data( $order )` helper
(location name, address, formatted date/time, Maps URL), so the
name → address → Get Directions link structure stays consistent everywhere.

## Promo Banner — `group_csl_promo_banner`

Location: Options Page == `csl-store-settings` (constant `CSL_OPTIONS_PAGE_SLUG`)

Site-wide banner rendered by `CSL_Promo_Banner` (`includes/class-promo-banner.php`)
on `wp_body_open` — a core WordPress action rather than a Storefront-specific
hook, so it keeps working through a theme switch (README "Minimal theme
footprint" principle).

| Field name | Type | Notes |
| --- | --- | --- |
| `csl_promo_banner_enabled` | true_false | Master switch. The other four fields are conditional on this in the admin UI, but `CSL_Promo_Banner::is_active()` re-checks it directly (not just relying on `conditional_logic` having hidden the other fields) since a field hidden in the UI can still hold a stale saved value. |
| `csl_promo_banner_text` | text | Banner stays hidden even when enabled if this is blank. |
| `csl_promo_banner_link_page` | select | Choices are `custom` => "Custom URL" (first, and the default value) followed by every published page/post, keyed by post ID. Built dynamically at registration time (`get_posts()`), not a static list. Selecting an actual page/post takes priority — `CSL_Promo_Banner::resolve_link()` resolves it to `get_permalink()` live (not stored), so it stays correct if the page is later renamed/moved. |
| `csl_promo_banner_link_url` | text | Only shown (via `conditional_logic`) when `csl_promo_banner_link_page` is set to `custom`. Deliberately a plain `text` field, not `url` — no format validation, so staff can enter anything (a relative path, `mailto:`, an external URL) without ACF rejecting it. Still passed through `esc_url()` at output for safety regardless of what's entered. |
| `csl_promo_banner_start_date` / `csl_promo_banner_end_date` | date_picker | Both optional and independent — an unset side of the range is unbounded (no start = show immediately once enabled, no end = show indefinitely). Stored (`return_format`) as `Y-m-d`; `display_format` is set dynamically from `get_option('date_format')` at registration time, same as Pickup Locations' Blackout Dates. |

`is_active()` is checked twice per page load, independently: once by
`render_banner()` and once by `enqueue_styles()` (so the stylesheet is never
loaded on a page where the banner won't render). Both re-read the fields
fresh via `get_field()` rather than sharing state, matching how other
options-page settings are read elsewhere in this plugin (e.g. pickup
locations) — cheap enough not to bother caching within the request.

## Bundle Showcase (homepage) — `includes/class-bundle-showcase.php`

Not an SCF field — reads the existing `csl_is_bundle` product field to build
a "Bundles" section on the homepage, above the main Shop product grid (the
homepage on this site *is* the WooCommerce Shop page, per `page_on_front`).

Hooked to `woocommerce_before_main_content` at priority 15 — WooCommerce's
own `woocommerce_output_content_wrapper()` is what's hooked to that same
action at priority 10 (it opens the page's `#primary`/`#main` wrapper), so
15 places this section immediately after that, above the Shop page's own
`<h1>` title and product grid.

Deliberately reuses WooCommerce's own product-loop template part
(`wc_get_template_part( 'content', 'product' )`, the same one the main Shop
grid renders) rather than custom markup, mirroring the exact loop
construction WooCommerce's own `single-product/up-sells.php` template uses
(`woocommerce_product_loop_start()`/`_end()` wrapping a `setup_postdata()` +
`wc_get_template_part()` foreach). This means bundle tiles automatically
pick up every other filter already applied elsewhere in this plugin — the
"Bundle Discount" sale flash (`CSL_Bundle_Display::bundle_sale_flash()`) and
the discounted price (`CSL_Cart_Pricing::filter_price_html()`) both render
correctly with zero extra code, since those filters hook into WooCommerce's
core price/sale-flash rendering globally, not just the single-product page.

Gated on `is_front_page()`, so it only ever appears on the homepage, not on
the Shop page reached any other way, and not on single product pages.

## Reading these fields in code

Standard SCF/ACF accessors work against all of the above once a real post ID
(product, variation) or `"option"` is passed:

```php
$is_bundle = get_field( 'csl_is_bundle', $product_id );
$components = get_field( 'csl_bundle_components', $product_id );
$fit = get_field( 'csl_fit_profile', $variation_id );
$locations = get_field( 'csl_pickup_locations', 'option' );
```

Always guard calls with `function_exists( 'get_field' )` outside of hooks that
already only fire when SCF is active (see README "fail gracefully without
SCF" principle).
