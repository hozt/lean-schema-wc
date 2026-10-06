=== Lean Schema for WooCommerce ===
Author: Jeffrey Haug
Author URI: https://hozt.com
Tags: schema, structured data, json-ld, woocommerce, rich results
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later

Lightweight, complete JSON-LD structured data for WooCommerce. One file, no front-end CSS/JS, no database tables.

== Description ==

Replaces WooCommerce's default structured data with fuller markup aimed at Google product rich results and merchant listings.

* Product: name, description, images (main + gallery), SKU, GTIN, MPN, brand
* Variable products as ProductGroup with hasVariant and variesBy (color, size, material, pattern); other attributes become additionalProperty
* Offer: price (tax display matches your shop), currency, availability incl. backorder, condition, seller
* Sale prices: StrikethroughPrice + priceValidUntil from the sale end date
* Grouped products: AggregateOffer
* AggregateRating + recent reviews
* OfferShippingDetails and MerchantReturnPolicy from simple global settings
* BreadcrumbList (respects Yoast / Rank Math primary category)
* Organization (logo, social profiles, return policy) and WebSite on the home page
* Product category (per-product field, store-wide default, or the product's main WooCommerce category) and brand URL

== Per-product settings ==

Each product has a Schema tab on its edit screen:

* Disable schema for this product
* Brand, Brand URL, Category, MPN (and GTIN on WooCommerce older than 9.2)
* Overrides for item condition, shipping rate, return window and return fees

Blank fields fall back to the store-wide values in WooCommerce > Settings > Products > Schema.

Brand is taken from, in order: the product's Brand field, a brand taxonomy (WooCommerce Brands, Perfect Brands, YITH), a "Brand" attribute, then the default brand setting.

GTIN uses WooCommerce's built-in GTIN/UPC/EAN/ISBN field (WooCommerce 9.2+). On older versions the plugin adds its own GTIN field.

== Installation ==

1. Plugins > Add New > Upload Plugin, choose lean-schema-wc.zip, activate.
2. WooCommerce > Settings > Products > Schema: set shipping rate, delivery times and return window.
3. Test a product URL in Google's Rich Results Test.

== Using with SEO plugins ==

If Yoast, Rank Math or similar already output Product schema, disable theirs or disable this plugin's output:

add_filter( 'lsw_output_enabled', '__return_false' );

If your SEO plugin already outputs breadcrumbs, untick "Breadcrumbs" in the settings.

== Filters ==

* lsw_output_enabled (bool)
* lsw_graph (array $graph)
* lsw_product (array $node, WC_Product $product)
* lsw_product_url (string $url, WC_Product $product)
* lsw_variant (array $node, WC_Product_Variation $variation, WC_Product_Variable $parent)
* lsw_offer (array $offer, WC_Product $product)
* lsw_shipping_details (array $details, WC_Product|null $product)
* lsw_return_policy (array $policy, WC_Product|null $product)
* lsw_organization (array)
* lsw_website (array)
* lsw_max_variants (int, default 50)

== Changelog ==

= 1.2.3 =
* Variant products now fall back to the parent product's image and description, fixing Google's "Missing field image" error on variable products whose variations have no image of their own.

= 1.2.2 =
* Added the lsw_product_url filter, so sites that redirect product permalinks to landing pages can point Product, variant and Offer URLs at the real page.

= 1.2.1 =
* Schema now also outputs for products embedded on pages and posts with the Single Product block.

= 1.2.0 =
* Added a store-wide Default category, overridable per product.
* Author set to Jeffrey Haug.

= 1.1.0 =
* Schema tab on the product edit screen with per-product overrides and a disable option.
* Added category and brand URL.
* The default "Uncategorized" category is no longer used in breadcrumbs or category.

= 1.0.0 =
* Initial release.
