# Staff Guide: Promo Banner

This guide is for store staff, not developers. It covers the banner that can
appear across the top of every page on the site.

> A note on screenshots: this guide is written as clear step-by-step text
> instead of screenshots, since screenshots weren't available to capture in
> the environment this was written in. Everything below refers to labels you
> can match exactly on the screen.

## Where to find it

1. Log in to the WordPress admin (wp-admin).
2. In the left-hand admin menu, click **Store Settings** (it has a small
   store icon, grouped with the other WooCommerce menu items — right after
   **Marketing**, just above **Appearance**).
3. You'll land on a page with several sections. Scroll to the one titled
   **Promo Banner**.

## What each field does

| Field | What it does | Leave blank if... |
| --- | --- | --- |
| **Enable Promo Banner** | The master on/off switch. If this is unchecked, the banner never shows, no matter what's in the other fields. | You want the banner off entirely — you can leave everything else filled in and just come back to flip this on later. |
| **Banner Text** | The message shown in the banner (e.g. "Free shipping on orders over $50"). | You want the banner to stay hidden — even with "Enable" checked, a blank message means nothing shows. |
| **Link to Page/Post** | A dropdown. Choose **Custom URL** to type in any link yourself, or pick one of your site's existing pages/posts by name to link straight to it. | You just want plain, unclickable text — pick **Custom URL** and leave the URL field below blank. |
| **Or Custom URL** | Only appears when **Custom URL** is selected above. Type the web address you want the banner to link to (e.g. `https://jsdev.woocommerce/promo/`, or a page on another site). | Not applicable if you selected an actual page/post above instead. |
| **Start Date** | The banner won't show before this date. | You want the banner to start showing immediately as soon as you enable it. |
| **End Date** | The banner stops showing after this date — no need to remember to come back and turn it off. | You want the banner to keep showing indefinitely, until you manually disable it. |

Dates display in whatever format is set under **Settings > General > Date
Format**, so they'll always match what you're used to seeing elsewhere in
wp-admin.

## Typical workflow: running a week-long sale

1. Go to **Store Settings > Promo Banner**.
2. Check **Enable Promo Banner**.
3. Type your message into **Banner Text**, e.g. "Summer Sale — 20% off all
   hair masks, this week only!"
4. Under **Link to Page/Post**, either:
   - pick the Shop page (or a specific sale page) from the dropdown, or
   - select **Custom URL** and type the link yourself.
5. Set **Start Date** and **End Date** to the sale's first and last day.
6. Click **Update** (or **Save**) at the bottom of the page.
7. Visit the site's homepage in a new browser tab to confirm the banner
   appears with the text and link you expect.

The banner will then disappear on its own the day after your End Date —
nothing else to remember.

## What's staff-safe vs. what needs a developer

**Everything on the Promo Banner section of Store Settings is safe for
staff to change at any time.** There's no field here that can break the
store if it's left blank, misconfigured, or forgotten about — the banner
simply won't show if anything required (the enable switch or the text) is
missing.

The one thing that **does** need a developer: changing how the banner
*looks* (its background color, text size, spacing, etc.). That's controlled
by a stylesheet in the plugin's code
(`assets/css/promo-banner.css`), not by any field on this settings page.
