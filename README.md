# Lean Schema for WooCommerce

Lightweight, complete JSON-LD structured data for WooCommerce. One PHP file, no front-end CSS or JS, no database tables.

It replaces WooCommerce's built-in structured data with fuller markup aimed at Google product rich results and merchant listings, so nothing is duplicated.

**Author:** [Jeffrey Haug](https://hozt.com) · **License:** GPL-2.0-or-later · **Requires:** WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+

## What it outputs

- **Product:** name, description, images (main + gallery), SKU, GTIN, MPN, brand (with URL), category
- **Variable products:** `ProductGroup` with `hasVariant` and `variesBy` (color, size, material, pattern); other attributes become `additionalProperty`
- **Offer:** price (matches your shop's tax display), currency, availability incl. backorder, condition, seller
- **Sale prices:** `StrikethroughPrice` and `priceValidUntil` from the sale end date
- **Grouped products:** `AggregateOffer`
- **Reviews:** `AggregateRating` plus recent reviews
- **Shipping and returns:** `OfferShippingDetails` and `MerchantReturnPolicy`
- **BreadcrumbList:** respects the Yoast / Rank Math primary category, skips "Uncategorized"
- **Organization and WebSite** on the home page (logo, social profiles, return policy)

Everything goes in a single `<script type="application/ld+json">` in the page `<head>`.

## Installation

1. Download `lean-schema-wc-x.y.z.zip` from the [latest release](../../releases/latest).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, activate.
3. Set your defaults under **WooCommerce → Settings → Products → Schema**.
4. Check a product URL with Google's [Rich Results Test](https://search.google.com/test/rich-results).

To update, upload the new zip the same way and choose **Replace current with uploaded**.

## Settings

Store-wide defaults live in **WooCommerce → Settings → Products → Schema**. Each product has a **Schema** tab on its edit screen; any field left blank there uses the store default.

| Product field | Store-wide default |
|---|---|
| Brand | Default brand (also read from brand taxonomies or a "Brand" attribute) |
| Brand URL | Brand URL |
| Category | Default category, then the product's main WooCommerce category |
| Item condition | Item condition |
| Shipping rate | Shipping rate, plus handling and transit days |
| Return window | Return window (days) |
| Return fees | Return fees |
| MPN | — |
| Disable schema | — |

GTIN uses WooCommerce's built-in GTIN/UPC/EAN/ISBN field (WooCommerce 9.2+). On older versions the plugin adds its own GTIN field. Variations have their own MPN (and GTIN on older WooCommerce).

## Using with SEO plugins

If Yoast, Rank Math or similar already output Product schema, turn theirs off or turn this plugin's output off:

```php
add_filter( 'lsw_output_enabled', '__return_false' );
```

If your SEO plugin already outputs breadcrumbs, untick **Breadcrumbs** in the settings.

## Filters

| Filter | Arguments |
|---|---|
| `lsw_output_enabled` | `bool` |
| `lsw_graph` | `array $graph` |
| `lsw_product` | `array $node, WC_Product $product` |
| `lsw_product_url` | `string $url, WC_Product $product` |
| `lsw_variant` | `array $node, WC_Product_Variation $variation, WC_Product_Variable $parent` |
| `lsw_offer` | `array $offer, WC_Product $product` |
| `lsw_shipping_details` | `array $details, WC_Product\|null $product` |
| `lsw_return_policy` | `array $policy, WC_Product\|null $product` |
| `lsw_organization` | `array $node` |
| `lsw_website` | `array $node` |
| `lsw_max_variants` | `int $limit` (default 50), `WC_Product_Variable $product` |

Example: add a GTIN stored in a custom field.

```php
add_filter( 'lsw_product', function ( $node, $product ) {
	$gtin = $product->get_meta( '_my_gtin' );
	if ( $gtin ) {
		$node['gtin'] = $gtin;
	}
	return $node;
}, 10, 2 );
```

## Building and releasing

The [Build plugin zip](.github/workflows/build.yml) workflow runs on every push and pull request:

1. Lints all PHP files on PHP 7.4 and 8.3.
2. Checks that the version matches in three places: the `Version:` header and `VERSION` constant in `lean-schema-wc.php`, and `Stable tag:` in `readme.txt`.
3. Builds `lean-schema-wc-x.y.z.zip` with a `lean-schema-wc/` folder inside, leaving out repo-only files listed in `.distignore`.
4. Uploads it as a workflow artifact (Actions tab → the run → Artifacts). GitHub wraps artifacts in an extra zip, so unzip once before uploading to WordPress.

To publish a release:

1. Bump the version in all three places and add a changelog entry in `readme.txt`.
2. Commit, then tag and push:

   ```bash
   git tag v1.2.0
   git push origin main --tags
   ```

3. The workflow checks the tag matches the plugin version and creates a GitHub release with the installable zip attached. You can also run it by hand from the Actions tab.

To build locally:

```bash
mkdir -p build/lean-schema-wc
rsync -a --exclude-from=.distignore ./ build/lean-schema-wc/
(cd build && zip -r ../lean-schema-wc.zip lean-schema-wc)
```

## Changelog

See [`readme.txt`](readme.txt).

## License

GPL-2.0-or-later. See the plugin header in [`lean-schema-wc.php`](lean-schema-wc.php).
