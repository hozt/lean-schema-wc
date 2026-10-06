<?php
/**
 * Plugin Name:          Lean Schema for WooCommerce
 * Description:          Lightweight, complete JSON-LD structured data for WooCommerce: Product / ProductGroup with variants, offers, sale prices, reviews, shipping and return policy, breadcrumbs, and Organization. Replaces WooCommerce's default markup so nothing is duplicated.
 * Version:              1.2.1
 * Author:               Jeffrey Haug
 * Author URI:           https://hozt.com
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      10.2
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          lean-schema-wc
 */

defined( 'ABSPATH' ) || exit;

final class Lean_Schema_WC {

	const VERSION          = '1.2.1';
	const META_BRAND       = '_lsw_brand';
	const META_BRAND_URL   = '_lsw_brand_url';
	const META_MPN         = '_lsw_mpn';
	const META_GTIN        = '_lsw_gtin';
	const META_CATEGORY    = '_lsw_category';
	const META_DISABLE     = '_lsw_disable';
	const META_CONDITION   = '_lsw_condition';
	const META_SHIP_RATE   = '_lsw_ship_rate';
	const META_RETURN_DAYS = '_lsw_return_days';
	const META_RETURN_FEES = '_lsw_return_fees';

	const CONDITIONS  = array( 'NewCondition', 'UsedCondition', 'RefurbishedCondition' );
	const RETURN_FEES = array( 'FreeReturn', 'ReturnFeesCustomerResponsibility' );

	/** Mapping of attribute names to schema.org properties Google accepts in variesBy. */
	const VARIANT_PROPS = array(
		'color'    => 'color',
		'colour'   => 'color',
		'size'     => 'size',
		'material' => 'material',
		'pattern'  => 'pattern',
	);

	private static $instance;
	private $shipping_cache = array();
	private $returns_cache  = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Settings: WooCommerce > Settings > Products > Schema.
		add_filter( 'woocommerce_get_sections_products', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_products', array( $this, 'settings' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );

		// Product edit fields.
		if ( is_admin() ) {
			add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'product_panel' ) );
			add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_fields' ) );
			add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );
		}

		// Front end.
		add_action( 'wp', array( $this, 'disable_wc_markup' ) );
		add_action( 'wp_head', array( $this, 'output' ), 30 );
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	/** Stop WooCommerce printing its own (thinner) JSON-LD so there are no duplicates. */
	public function disable_wc_markup() {
		if ( ! $this->enabled() ) {
			return;
		}
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( $wc && isset( $wc->structured_data ) && is_object( $wc->structured_data ) ) {
			remove_action( 'wp_footer', array( $wc->structured_data, 'output_structured_data' ), 10 );
		}
	}

	public function output() {
		if ( ! $this->enabled() ) {
			return;
		}

		$graph = array();

		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof WC_Product && 'yes' !== $product->get_meta( self::META_DISABLE ) ) {
				$node = apply_filters( 'lsw_product', $this->product_node( $product ), $product );
				if ( $node ) {
					$graph[] = $node;
				}
			}
		}

		// Pages/posts that embed products with the Single Product block.
		if ( is_singular() && ! is_product() ) {
			foreach ( $this->embedded_product_ids( get_queried_object_id() ) as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product || 'publish' !== get_post_status( $product_id ) || 'yes' === $product->get_meta( self::META_DISABLE ) ) {
					continue;
				}
				$node = apply_filters( 'lsw_product', $this->product_node( $product ), $product );
				if ( $node ) {
					$graph[] = $node;
				}
			}
		}

		if ( is_front_page() && 'yes' === get_option( 'lsw_org_enabled', 'yes' ) ) {
			$graph[] = $this->organization_node();
			$graph[] = $this->website_node();
		}

		if ( 'yes' === get_option( 'lsw_breadcrumbs', 'yes' ) && ( is_product() || is_product_category() || is_product_tag() ) ) {
			$crumbs = $this->breadcrumb_node();
			if ( $crumbs ) {
				$graph[] = $crumbs;
			}
		}

		$graph = array_values( array_filter( (array) apply_filters( 'lsw_graph', $graph ) ) );
		if ( ! $graph ) {
			return;
		}

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);

		if ( $json ) {
			echo "\n<script type=\"application/ld+json\" class=\"lean-schema-wc\">" . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encoded with JSON_HEX_TAG.
		}
	}

	/** Product IDs from woocommerce/single-product blocks in a post's content. */
	private function embedded_product_ids( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === $content || false === strpos( $content, 'woocommerce/single-product' ) ) {
			return array();
		}

		$ids   = array();
		$walk  = function ( array $blocks ) use ( &$walk, &$ids ) {
			foreach ( $blocks as $block ) {
				if ( 'woocommerce/single-product' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['productId'] ) ) {
					$ids[] = absint( $block['attrs']['productId'] );
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );

		return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, (int) apply_filters( 'lsw_max_embedded_products', 10 ) );
	}

	private function enabled() {
		return (bool) apply_filters( 'lsw_output_enabled', true );
	}

	/* ---------------------------------------------------------------------
	 * Product
	 * ------------------------------------------------------------------- */

	private function product_node( WC_Product $p ) {
		$url  = get_permalink( $p->get_id() );
		$node = array(
			'@type' => 'Product',
			'@id'   => $url . '#product',
			'name'  => wp_strip_all_tags( $p->get_name() ),
			'url'   => $url,
		);

		$desc = $this->description( $p );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}

		$images = $this->images( $p );
		if ( $images ) {
			$node['image'] = $images;
		}

		$brand = $this->brand( $p );
		if ( '' !== $brand ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
			$brand_url = $this->pmeta( $p, self::META_BRAND_URL );
			if ( '' === $brand_url ) {
				$brand_url = (string) get_option( 'lsw_brand_url', '' );
			}
			$brand_url = esc_url_raw( $brand_url );
			if ( $brand_url ) {
				$node['brand']['url'] = $brand_url;
			}
		}

		$category = $this->category( $p );
		if ( '' !== $category ) {
			$node['category'] = $category;
		}

		$this->add_reviews( $node, $p );

		if ( $p->is_type( 'variable' ) ) {
			return $this->product_group( $node, $p );
		}

		$this->add_identifiers( $node, $p );

		if ( $p->is_type( 'grouped' ) ) {
			$offer = $this->aggregate_offer( $p );
		} else {
			$offer = $this->offer( $p, $url );
		}
		if ( $offer ) {
			$node['offers'] = $offer;
		}

		return $node;
	}

	/** Variable products become a ProductGroup with one Product per variation. */
	private function product_group( array $node, WC_Product_Variable $p ) {
		$node['@type']          = 'ProductGroup';
		$node['productGroupID'] = $p->get_sku() ? $p->get_sku() : (string) $p->get_id();

		$limit    = (int) apply_filters( 'lsw_max_variants', 50, $p );
		$variants = array();
		$varies   = array();

		foreach ( array_slice( $p->get_children(), 0, $limit ) as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v instanceof WC_Product_Variation || 'publish' !== $v->get_status() || '' === $v->get_price() ) {
				continue;
			}

			$vurl = $v->get_permalink();
			$vn   = array(
				'@type' => 'Product',
				'@id'   => $vurl . '#product',
				'name'  => wp_strip_all_tags( $v->get_name() ),
				'url'   => $vurl,
			);

			if ( $v->get_image_id( 'edit' ) ) {
				$img = wp_get_attachment_image_url( $v->get_image_id( 'edit' ), 'full' );
				if ( $img ) {
					$vn['image'] = $img;
				}
			}

			$this->add_identifiers( $vn, $v );

			foreach ( $v->get_attributes() as $attr_name => $value ) {
				if ( '' === $value ) {
					continue; // "Any …" attribute.
				}
				$label = wc_attribute_label( $attr_name, $p );
				if ( taxonomy_exists( $attr_name ) ) {
					$term  = get_term_by( 'slug', $value, $attr_name );
					$value = $term ? $term->name : $value;
				}
				$key = sanitize_title( str_replace( 'pa_', '', $attr_name ) );

				if ( isset( self::VARIANT_PROPS[ $key ] ) ) {
					$prop        = self::VARIANT_PROPS[ $key ];
					$vn[ $prop ] = $value;
					$varies[ 'https://schema.org/' . $prop ] = true;
				} else {
					$vn['additionalProperty'][] = array(
						'@type' => 'PropertyValue',
						'name'  => $label,
						'value' => $value,
					);
				}
			}

			$offer = $this->offer( $v, $vurl );
			if ( $offer ) {
				$vn['offers'] = $offer;
			}

			$variants[] = apply_filters( 'lsw_variant', $vn, $v, $p );
		}

		if ( $variants ) {
			$node['hasVariant'] = $variants;
		}
		if ( $varies ) {
			$node['variesBy'] = array_keys( $varies );
		}

		return $node;
	}

	private function offer( WC_Product $p, $url ) {
		if ( '' === $p->get_price() ) {
			return null;
		}

		$decimals = wc_get_price_decimals();
		$currency = get_woocommerce_currency();
		$price    = wc_format_decimal( wc_get_price_to_display( $p ), $decimals );

		$offer = array(
			'@type'         => 'Offer',
			'url'           => $url,
			'price'         => $price,
			'priceCurrency' => $currency,
			'availability'  => $this->availability( $p ),
			'itemCondition' => 'https://schema.org/' . $this->condition( $p ),
			'seller'        => array(
				'@type' => 'Organization',
				'@id'   => home_url( '/#organization' ),
				'name'  => get_bloginfo( 'name' ),
			),
		);

		// Sale: show the strikethrough (regular) price and when the sale ends.
		if ( $p->is_on_sale() ) {
			$regular = wc_format_decimal( wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) ), $decimals );
			if ( (float) $regular > (float) $price ) {
				$offer['priceSpecification'] = array(
					array(
						'@type'         => 'UnitPriceSpecification',
						'price'         => $price,
						'priceCurrency' => $currency,
					),
					array(
						'@type'         => 'UnitPriceSpecification',
						'priceType'     => 'https://schema.org/StrikethroughPrice',
						'price'         => $regular,
						'priceCurrency' => $currency,
					),
				);
			}
			$ends = $p->get_date_on_sale_to();
			if ( $ends ) {
				$offer['priceValidUntil'] = $ends->date( 'Y-m-d' );
			}
		}

		if ( ! $p->is_virtual() ) {
			$shipping = $this->shipping_details( $p );
			if ( $shipping ) {
				$offer['shippingDetails'] = $shipping;
			}
		}

		$returns = $this->return_policy( $p );
		if ( $returns ) {
			$offer['hasMerchantReturnPolicy'] = $returns;
		}

		return apply_filters( 'lsw_offer', $offer, $p );
	}

	private function aggregate_offer( WC_Product $p ) {
		$prices   = array();
		$in_stock = false;

		foreach ( $p->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || '' === $child->get_price() ) {
				continue;
			}
			$prices[] = (float) wc_get_price_to_display( $child );
			$in_stock = $in_stock || $child->is_in_stock();
		}

		if ( ! $prices ) {
			return null;
		}

		$decimals = wc_get_price_decimals();
		return array(
			'@type'         => 'AggregateOffer',
			'lowPrice'      => wc_format_decimal( min( $prices ), $decimals ),
			'highPrice'     => wc_format_decimal( max( $prices ), $decimals ),
			'offerCount'    => count( $prices ),
			'priceCurrency' => get_woocommerce_currency(),
			'availability'  => 'https://schema.org/' . ( $in_stock ? 'InStock' : 'OutOfStock' ),
		);
	}

	private function availability( WC_Product $p ) {
		$map = array(
			'instock'     => 'InStock',
			'outofstock'  => 'OutOfStock',
			'onbackorder' => 'BackOrder',
		);
		$status = $p->get_stock_status();
		return 'https://schema.org/' . ( isset( $map[ $status ] ) ? $map[ $status ] : 'InStock' );
	}

	private function add_identifiers( array &$node, WC_Product $p ) {
		$sku = $p->get_sku( 'edit' ); // 'edit' so variations don't inherit the parent SKU.
		if ( '' !== (string) $sku ) {
			$node['sku'] = $sku;
		}

		$gtin = method_exists( $p, 'get_global_unique_id' ) ? $p->get_global_unique_id( 'edit' ) : '';
		if ( ! $gtin ) {
			$gtin = $p->get_meta( self::META_GTIN );
		}
		$gtin = preg_replace( '/\D/', '', (string) $gtin );
		if ( in_array( strlen( $gtin ), array( 8, 12, 13, 14 ), true ) ) {
			$node['gtin'] = $gtin;
		}

		$mpn = $p->get_meta( self::META_MPN );
		if ( '' !== (string) $mpn ) {
			$node['mpn'] = $mpn;
		}
	}

	private function brand( WC_Product $p ) {
		$id    = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
		$brand = (string) get_post_meta( $id, self::META_BRAND, true );

		// Brand taxonomies: WooCommerce core (9.6+), Perfect Brands, YITH Brands.
		if ( '' === $brand ) {
			foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $tax ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$terms = get_the_terms( $id, $tax );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$brand = $terms[0]->name;
					break;
				}
			}
		}

		// A "Brand" product attribute.
		if ( '' === $brand ) {
			$parent = $p->get_parent_id() ? wc_get_product( $id ) : $p;
			if ( $parent ) {
				$attr  = $parent->get_attribute( 'brand' );
				$brand = $attr ? trim( explode( ',', $attr )[0] ) : '';
			}
		}

		if ( '' === $brand ) {
			$brand = (string) get_option( 'lsw_default_brand', '' );
		}

		return trim( wp_strip_all_tags( $brand ) );
	}

	/** Product meta, read from the parent for variations. */
	private function pmeta( WC_Product $p, $key ) {
		$id = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
		return trim( (string) get_post_meta( $id, $key, true ) );
	}

	private function condition( WC_Product $p ) {
		$c = $this->pmeta( $p, self::META_CONDITION );
		if ( ! in_array( $c, self::CONDITIONS, true ) ) {
			$c = get_option( 'lsw_condition', 'NewCondition' );
		}
		return in_array( $c, self::CONDITIONS, true ) ? $c : 'NewCondition';
	}

	/** Per-product Category field, then the store-wide Default category, then the product's main WooCommerce category. */
	private function category( WC_Product $p ) {
		$cat = $this->pmeta( $p, self::META_CATEGORY );
		if ( '' === $cat ) {
			$cat = trim( (string) get_option( 'lsw_default_category', '' ) );
		}
		if ( '' === $cat ) {
			$term = $this->primary_category( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() );
			$cat  = $term ? $term->name : '';
		}
		return trim( wp_strip_all_tags( html_entity_decode( $cat, ENT_QUOTES, 'UTF-8' ) ) );
	}

	private function description( WC_Product $p ) {
		$text = $p->get_short_description() ? $p->get_short_description() : $p->get_description();
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 5000 ) : substr( $text, 0, 5000 );
	}

	private function images( WC_Product $p ) {
		$ids  = array_filter( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) );
		$urls = array();
		foreach ( array_slice( array_unique( $ids ), 0, 10 ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	private function add_reviews( array &$node, WC_Product $p ) {
		if ( ! wc_review_ratings_enabled() || $p->get_review_count() < 1 || $p->get_average_rating() <= 0 ) {
			return;
		}

		$node['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => wc_format_decimal( $p->get_average_rating(), 2 ),
			'reviewCount' => (int) $p->get_review_count(),
			'bestRating'  => 5,
			'worstRating' => 1,
		);

		$limit = absint( get_option( 'lsw_reviews_limit', 5 ) );
		if ( ! $limit ) {
			return;
		}

		$comments = get_comments(
			array(
				'post_id'  => $p->get_id(),
				'status'   => 'approve',
				'type'     => 'review',
				'parent'   => 0,
				'number'   => $limit,
				'meta_key' => 'rating', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
			)
		);

		foreach ( $comments as $c ) {
			$rating = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			if ( $rating < 1 ) {
				continue;
			}
			$node['review'][] = array(
				'@type'         => 'Review',
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'bestRating'  => 5,
					'worstRating' => 1,
				),
				'author'        => array(
					'@type' => 'Person',
					'name'  => get_comment_author( $c ),
				),
				'datePublished' => get_comment_date( 'c', $c ),
				'reviewBody'    => trim( wp_strip_all_tags( $c->comment_content ) ),
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Shipping & returns (computed once per request)
	 * ------------------------------------------------------------------- */

	private function country() {
		$c = strtoupper( trim( (string) get_option( 'lsw_country', '' ) ) );
		return $c ? $c : WC()->countries->get_base_country();
	}

	private function shipping_details( WC_Product $p = null ) {
		$rate = get_option( 'lsw_ship_rate', '' );
		if ( $p ) {
			$override = $this->pmeta( $p, self::META_SHIP_RATE );
			if ( '' !== $override && is_numeric( $override ) ) {
				$rate = $override;
			}
		}
		if ( '' === $rate || ! is_numeric( $rate ) ) {
			return null;
		}

		$key = $p ? (string) ( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() ) : '0';
		if ( array_key_exists( $key, $this->shipping_cache ) ) {
			return $this->shipping_cache[ $key ];
		}

		$details = array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array(
				'@type'    => 'MonetaryAmount',
				'value'    => wc_format_decimal( $rate, wc_get_price_decimals() ),
				'currency' => get_woocommerce_currency(),
			),
			'shippingDestination' => array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => $this->country(),
			),
		);

		$time = array();
		foreach ( array( 'handling' => 'handlingTime', 'transit' => 'transitTime' ) as $tkey => $prop ) {
			$min = get_option( "lsw_{$tkey}_min", '' );
			$max = get_option( "lsw_{$tkey}_max", '' );
			if ( '' !== $min && '' !== $max ) {
				$time[ $prop ] = array(
					'@type'    => 'QuantitativeValue',
					'minValue' => absint( $min ),
					'maxValue' => max( absint( $min ), absint( $max ) ),
					'unitCode' => 'DAY',
				);
			}
		}
		if ( $time ) {
			$details['deliveryTime'] = array( '@type' => 'ShippingDeliveryTime' ) + $time;
		}

		$this->shipping_cache[ $key ] = apply_filters( 'lsw_shipping_details', $details, $p );
		return $this->shipping_cache[ $key ];
	}

	private function return_policy( WC_Product $p = null ) {
		$days = get_option( 'lsw_return_days', '' );
		$fees = get_option( 'lsw_return_fees', 'FreeReturn' );
		if ( $p ) {
			$o_days = $this->pmeta( $p, self::META_RETURN_DAYS );
			if ( '' !== $o_days && is_numeric( $o_days ) ) {
				$days = $o_days;
			}
			$o_fees = $this->pmeta( $p, self::META_RETURN_FEES );
			if ( in_array( $o_fees, self::RETURN_FEES, true ) ) {
				$fees = $o_fees;
			}
		}
		if ( '' === $days || ! is_numeric( $days ) ) {
			return null;
		}
		$days = absint( $days );
		$fees = in_array( $fees, self::RETURN_FEES, true ) ? $fees : 'FreeReturn';

		$key = $p ? (string) ( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() ) : '0';
		if ( array_key_exists( $key, $this->returns_cache ) ) {
			return $this->returns_cache[ $key ];
		}

		$policy = array(
			'@type'             => 'MerchantReturnPolicy',
			'applicableCountry' => $this->country(),
		);

		if ( 0 === $days ) {
			$policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnNotPermitted';
		} else {
			$policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnFiniteReturnWindow';
			$policy['merchantReturnDays']   = $days;
			$policy['returnMethod']         = 'https://schema.org/ReturnByMail';
			$policy['returnFees']           = 'https://schema.org/' . $fees;
		}

		$this->returns_cache[ $key ] = apply_filters( 'lsw_return_policy', $policy, $p );
		return $this->returns_cache[ $key ];
	}

	/* ---------------------------------------------------------------------
	 * Site-level nodes
	 * ------------------------------------------------------------------- */

	private function organization_node() {
		$node = array(
			'@type' => get_option( 'lsw_org_type', 'OnlineStore' ),
			'@id'   => home_url( '/#organization' ),
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo_id = get_theme_mod( 'custom_logo' );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		if ( $logo ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		$same_as = array_values(
			array_filter(
				array_map( 'esc_url_raw', array_map( 'trim', preg_split( '/\R/', (string) get_option( 'lsw_same_as', '' ) ) ) )
			)
		);
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		$returns = $this->return_policy();
		if ( $returns ) {
			$node['hasMerchantReturnPolicy'] = $returns;
		}

		return apply_filters( 'lsw_organization', $node );
	}

	private function website_node() {
		return apply_filters(
			'lsw_website',
			array(
				'@type'     => 'WebSite',
				'@id'       => home_url( '/#website' ),
				'name'      => get_bloginfo( 'name' ),
				'url'       => home_url( '/' ),
				'publisher' => array( '@id' => home_url( '/#organization' ) ),
			)
		);
	}

	private function breadcrumb_node() {
		$items = array( array( __( 'Home', 'lean-schema-wc' ), home_url( '/' ) ) );

		if ( is_product() ) {
			$id   = get_queried_object_id();
			$term = $this->primary_category( $id );
			if ( $term ) {
				$items = array_merge( $items, $this->term_trail( $term ) );
			}
			$items[] = array( get_the_title( $id ), get_permalink( $id ) );
		} else {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$items = array_merge( $items, $this->term_trail( $term ) );
			}
		}

		if ( count( $items ) < 2 ) {
			return null;
		}

		$list = array();
		foreach ( $items as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => wp_strip_all_tags( $item[0] ),
				'item'     => $item[1],
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => end( $items )[1] . '#breadcrumb',
			'itemListElement' => $list,
		);
	}

	/** Primary category from Yoast / Rank Math if set, otherwise the deepest assigned category. */
	private function primary_category( $product_id ) {
		foreach ( array( '_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat' ) as $key ) {
			$tid = (int) get_post_meta( $product_id, $key, true );
			if ( $tid ) {
				$term = get_term( $tid, 'product_cat' );
				if ( $term instanceof WP_Term ) {
					return $term;
				}
			}
		}

		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}

		$best  = null;
		$depth = -1;
		foreach ( $terms as $t ) {
			if ( $this->is_uncategorized( $t ) ) {
				continue;
			}
			$d = count( get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ) );
			if ( $d > $depth ) {
				$best  = $t;
				$depth = $d;
			}
		}
		return $best;
	}

	/** WooCommerce's default "Uncategorized" product category says nothing useful, so it is skipped. */
	private function is_uncategorized( WP_Term $t ) {
		return (int) get_option( 'default_product_cat', 0 ) === (int) $t->term_id || 'uncategorized' === $t->slug;
	}

	private function term_trail( WP_Term $term ) {
		$trail = array();
		$ids   = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
		$ids[] = $term->term_id;
		foreach ( $ids as $tid ) {
			$t    = get_term( $tid, $term->taxonomy );
			$link = $t instanceof WP_Term ? get_term_link( $t ) : '';
			if ( $link && ! is_wp_error( $link ) ) {
				$trail[] = array( $t->name, $link );
			}
		}
		return $trail;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	public function add_section( $sections ) {
		$sections['lsw'] = __( 'Schema', 'lean-schema-wc' );
		return $sections;
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=products&section=lsw' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lean-schema-wc' ) . '</a>' );
		return $links;
	}

	public function settings( $settings, $section ) {
		if ( 'lsw' !== $section ) {
			return $settings;
		}

		$num = array(
			'min'  => '0',
			'step' => '1',
		);

		return array(
			array(
				'title' => __( 'Structured data', 'lean-schema-wc' ),
				'type'  => 'title',
				'desc'  => __( 'Lean Schema replaces WooCommerce\'s default JSON-LD. Leave shipping or return fields blank to omit them.', 'lean-schema-wc' ),
				'id'    => 'lsw_general',
			),
			array(
				'title'   => __( 'Organization', 'lean-schema-wc' ),
				'desc'    => __( 'Output Organization and WebSite markup on the home page', 'lean-schema-wc' ),
				'id'      => 'lsw_org_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Organization type', 'lean-schema-wc' ),
				'id'      => 'lsw_org_type',
				'type'    => 'select',
				'default' => 'OnlineStore',
				'options' => array(
					'OnlineStore'  => 'OnlineStore',
					'Store'        => 'Store',
					'Organization' => 'Organization',
				),
			),
			array(
				'title'       => __( 'Social profiles', 'lean-schema-wc' ),
				'desc_tip'    => __( 'One URL per line (sameAs).', 'lean-schema-wc' ),
				'id'          => 'lsw_same_as',
				'type'        => 'textarea',
				'css'         => 'min-width:400px;height:90px;',
				'placeholder' => "https://www.instagram.com/yourstore\nhttps://www.facebook.com/yourstore",
			),
			array(
				'title'   => __( 'Breadcrumbs', 'lean-schema-wc' ),
				'desc'    => __( 'Output BreadcrumbList on product and product archive pages (turn off if your SEO plugin already does)', 'lean-schema-wc' ),
				'id'      => 'lsw_breadcrumbs',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( 'Default brand', 'lean-schema-wc' ),
				'desc_tip' => __( 'Used when a product has no brand field, brand taxonomy, or Brand attribute.', 'lean-schema-wc' ),
				'id'       => 'lsw_default_brand',
				'type'     => 'text',
			),
			array(
				'title'       => __( 'Brand URL', 'lean-schema-wc' ),
				'desc_tip'    => __( 'Brand website added to every product\'s brand. Can be overridden per product.', 'lean-schema-wc' ),
				'id'          => 'lsw_brand_url',
				'type'        => 'url',
				'placeholder' => home_url( '/' ),
				'css'         => 'min-width:300px;',
			),
			array(
				'title'       => __( 'Default category', 'lean-schema-wc' ),
				'desc_tip'    => __( 'Schema category for every product, e.g. "Dietary Supplement". Can be overridden per product. Leave blank to use each product\'s main WooCommerce category.', 'lean-schema-wc' ),
				'id'          => 'lsw_default_category',
				'type'        => 'text',
				'placeholder' => __( 'e.g. Dietary Supplement', 'lean-schema-wc' ),
			),
			array(
				'title'   => __( 'Item condition', 'lean-schema-wc' ),
				'id'      => 'lsw_condition',
				'type'    => 'select',
				'default' => 'NewCondition',
				'options' => array(
					'NewCondition'         => __( 'New', 'lean-schema-wc' ),
					'UsedCondition'        => __( 'Used', 'lean-schema-wc' ),
					'RefurbishedCondition' => __( 'Refurbished', 'lean-schema-wc' ),
				),
			),
			array(
				'title'             => __( 'Reviews in markup', 'lean-schema-wc' ),
				'desc_tip'          => __( 'How many recent reviews to include per product (0 = rating summary only).', 'lean-schema-wc' ),
				'id'                => 'lsw_reviews_limit',
				'type'              => 'number',
				'default'           => '5',
				'custom_attributes' => $num,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lsw_general',
			),

			array(
				'title' => __( 'Shipping & returns', 'lean-schema-wc' ),
				'type'  => 'title',
				'desc'  => __( 'Helps products qualify for Google merchant listings. Use your most common domestic values.', 'lean-schema-wc' ),
				'id'    => 'lsw_shipping',
			),
			array(
				'title'       => __( 'Country', 'lean-schema-wc' ),
				'desc_tip'    => __( 'Two-letter code for shipping destination and return policy. Defaults to your store country.', 'lean-schema-wc' ),
				'id'          => 'lsw_country',
				'type'        => 'text',
				'placeholder' => WC()->countries->get_base_country(),
				'css'         => 'width:80px;',
			),
			array(
				'title'             => __( 'Shipping rate', 'lean-schema-wc' ),
				'desc_tip'          => __( 'Typical shipping cost. Use 0 for free shipping. Blank = omit shipping details.', 'lean-schema-wc' ),
				'id'                => 'lsw_ship_rate',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => 'any',
				),
			),
			array(
				'title'             => __( 'Handling days (min)', 'lean-schema-wc' ),
				'id'                => 'lsw_handling_min',
				'type'              => 'number',
				'custom_attributes' => $num,
			),
			array(
				'title'             => __( 'Handling days (max)', 'lean-schema-wc' ),
				'id'                => 'lsw_handling_max',
				'type'              => 'number',
				'custom_attributes' => $num,
			),
			array(
				'title'             => __( 'Transit days (min)', 'lean-schema-wc' ),
				'id'                => 'lsw_transit_min',
				'type'              => 'number',
				'custom_attributes' => $num,
			),
			array(
				'title'             => __( 'Transit days (max)', 'lean-schema-wc' ),
				'id'                => 'lsw_transit_max',
				'type'              => 'number',
				'custom_attributes' => $num,
			),
			array(
				'title'             => __( 'Return window (days)', 'lean-schema-wc' ),
				'desc_tip'          => __( '0 = returns not accepted. Blank = omit return policy.', 'lean-schema-wc' ),
				'id'                => 'lsw_return_days',
				'type'              => 'number',
				'custom_attributes' => $num,
			),
			array(
				'title'   => __( 'Return fees', 'lean-schema-wc' ),
				'id'      => 'lsw_return_fees',
				'type'    => 'select',
				'default' => 'FreeReturn',
				'options' => array(
					'FreeReturn'                       => __( 'Free returns', 'lean-schema-wc' ),
					'ReturnFeesCustomerResponsibility' => __( 'Customer pays return shipping', 'lean-schema-wc' ),
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lsw_shipping',
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Product edit fields
	 * ------------------------------------------------------------------- */

	private function needs_gtin_field() {
		// WooCommerce 9.2+ has its own GTIN/UPC/EAN/ISBN field.
		return ! method_exists( 'WC_Product', 'get_global_unique_id' );
	}

	public function product_tab( $tabs ) {
		$tabs['lsw_schema'] = array(
			'label'    => __( 'Schema', 'lean-schema-wc' ),
			'target'   => 'lsw_schema_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public function product_panel() {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		$p = $product_object;

		$store = function ( $value, $none ) {
			/* translators: %s: store-wide value */
			return '' === (string) $value ? $none : sprintf( __( 'Store default: %s', 'lean-schema-wc' ), $value );
		};

		$term      = $this->primary_category( $p->get_id() );
		$cond_opts = array(
			'NewCondition'         => __( 'New', 'lean-schema-wc' ),
			'UsedCondition'        => __( 'Used', 'lean-schema-wc' ),
			'RefurbishedCondition' => __( 'Refurbished', 'lean-schema-wc' ),
		);
		$fee_opts  = array(
			'FreeReturn'                       => __( 'Free returns', 'lean-schema-wc' ),
			'ReturnFeesCustomerResponsibility' => __( 'Customer pays return shipping', 'lean-schema-wc' ),
		);
		$def_cond  = get_option( 'lsw_condition', 'NewCondition' );
		$def_fees  = get_option( 'lsw_return_fees', 'FreeReturn' );
		$def_cat   = trim( (string) get_option( 'lsw_default_category', '' ) );

		echo '<div id="lsw_schema_data" class="panel woocommerce_options_panel hidden">';

		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_DISABLE,
				'label'       => __( 'Disable schema', 'lean-schema-wc' ),
				'description' => __( 'Don\'t output product structured data for this product', 'lean-schema-wc' ),
				'value'       => 'yes' === $p->get_meta( self::META_DISABLE ) ? 'yes' : 'no',
			)
		);
		echo '</div>';

		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_BRAND,
				'label'       => __( 'Brand', 'lean-schema-wc' ),
				'value'       => $p->get_meta( self::META_BRAND ),
				'placeholder' => $this->brand( $p ) ? $this->brand( $p ) : '',
				'desc_tip'    => true,
				'description' => __( 'Leave blank to use the brand taxonomy, a Brand attribute, or the default brand.', 'lean-schema-wc' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_BRAND_URL,
				'label'       => __( 'Brand URL', 'lean-schema-wc' ),
				'type'        => 'url',
				'value'       => $p->get_meta( self::META_BRAND_URL ),
				'placeholder' => (string) get_option( 'lsw_brand_url', '' ),
				'desc_tip'    => true,
				'description' => __( 'Brand website. Leave blank to use the store-wide Brand URL.', 'lean-schema-wc' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_CATEGORY,
				'label'       => __( 'Category', 'lean-schema-wc' ),
				'value'       => $p->get_meta( self::META_CATEGORY ),
				'placeholder' => '' !== $def_cat ? $store( $def_cat, '' ) : ( $term ? $term->name : __( 'e.g. Dietary Supplement', 'lean-schema-wc' ) ),
				'desc_tip'    => true,
				'description' => __( 'Schema category. Leave blank to use the store-wide Default category, or the product\'s main WooCommerce category if that is not set.', 'lean-schema-wc' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_MPN,
				'label'       => __( 'MPN', 'lean-schema-wc' ),
				'value'       => $p->get_meta( self::META_MPN ),
				'desc_tip'    => true,
				'description' => __( 'Manufacturer part number.', 'lean-schema-wc' ),
			)
		);
		if ( $this->needs_gtin_field() ) {
			woocommerce_wp_text_input(
				array(
					'id'          => self::META_GTIN,
					'label'       => __( 'GTIN / UPC / EAN', 'lean-schema-wc' ),
					'value'       => $p->get_meta( self::META_GTIN ),
					'desc_tip'    => true,
					'description' => __( '8, 12, 13 or 14 digits.', 'lean-schema-wc' ),
				)
			);
		}
		echo '</div>';

		echo '<div class="options_group">';
		echo '<p class="form-field"><em>' . esc_html__( 'Overrides — leave blank to use the store-wide values from WooCommerce → Settings → Products → Schema.', 'lean-schema-wc' ) . '</em></p>';
		woocommerce_wp_select(
			array(
				'id'      => self::META_CONDITION,
				'label'   => __( 'Item condition', 'lean-schema-wc' ),
				'value'   => $p->get_meta( self::META_CONDITION ),
				'options' => array( '' => $store( isset( $cond_opts[ $def_cond ] ) ? $cond_opts[ $def_cond ] : '', '' ) ) + $cond_opts,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_SHIP_RATE,
				'label'             => __( 'Shipping rate', 'lean-schema-wc' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'type'              => 'number',
				'value'             => $p->get_meta( self::META_SHIP_RATE ),
				'placeholder'       => $store( get_option( 'lsw_ship_rate', '' ), __( 'Not set', 'lean-schema-wc' ) ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => 'any',
				),
				'desc_tip'          => true,
				'description'       => __( '0 = free shipping for this product.', 'lean-schema-wc' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_RETURN_DAYS,
				'label'             => __( 'Return window (days)', 'lean-schema-wc' ),
				'type'              => 'number',
				'value'             => $p->get_meta( self::META_RETURN_DAYS ),
				'placeholder'       => $store( get_option( 'lsw_return_days', '' ), __( 'Not set', 'lean-schema-wc' ) ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( '0 = no returns (final sale).', 'lean-schema-wc' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => self::META_RETURN_FEES,
				'label'   => __( 'Return fees', 'lean-schema-wc' ),
				'value'   => $p->get_meta( self::META_RETURN_FEES ),
				'options' => array( '' => $store( isset( $fee_opts[ $def_fees ] ) ? $fee_opts[ $def_fees ] : '', '' ) ) + $fee_opts,
			)
		);
		echo '</div>';

		echo '</div>';
	}

	public function save_product_fields( WC_Product $product ) {
		// Nonce is verified by WooCommerce before this hook runs.
		$fields = array(
			self::META_BRAND       => 'text',
			self::META_BRAND_URL   => 'url',
			self::META_CATEGORY    => 'text',
			self::META_MPN         => 'text',
			self::META_CONDITION   => 'condition',
			self::META_SHIP_RATE   => 'decimal',
			self::META_RETURN_DAYS => 'int',
			self::META_RETURN_FEES => 'fees',
		);
		if ( $this->needs_gtin_field() ) {
			$fields[ self::META_GTIN ] = 'text';
		}

		foreach ( $fields as $key => $type ) {
			if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				continue;
			}
			$val = trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

			switch ( $type ) {
				case 'url':
					$val = esc_url_raw( $val );
					break;
				case 'condition':
					$val = in_array( $val, self::CONDITIONS, true ) ? $val : '';
					break;
				case 'fees':
					$val = in_array( $val, self::RETURN_FEES, true ) ? $val : '';
					break;
				case 'decimal':
					$val = ( '' !== $val && is_numeric( $val ) && (float) $val >= 0 ) ? wc_format_decimal( $val ) : '';
					break;
				case 'int':
					$val = ( '' !== $val && is_numeric( $val ) ) ? (string) absint( $val ) : '';
					break;
			}

			if ( '' === $val ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $val );
			}
		}

		if ( isset( $_POST[ self::META_DISABLE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$product->update_meta_data( self::META_DISABLE, 'yes' );
		} else {
			$product->delete_meta_data( self::META_DISABLE );
		}
	}

	public function variation_fields( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_MPN . "_{$loop}",
				'name'          => self::META_MPN . "[{$loop}]",
				'label'         => __( 'MPN', 'lean-schema-wc' ),
				'value'         => get_post_meta( $variation->ID, self::META_MPN, true ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);

		if ( $this->needs_gtin_field() ) {
			woocommerce_wp_text_input(
				array(
					'id'            => self::META_GTIN . "_{$loop}",
					'name'          => self::META_GTIN . "[{$loop}]",
					'label'         => __( 'GTIN / UPC / EAN', 'lean-schema-wc' ),
					'value'         => get_post_meta( $variation->ID, self::META_GTIN, true ),
					'wrapper_class' => 'form-row form-row-last',
				)
			);
		}
	}

	public function save_variation_fields( $variation_id, $i ) {
		$keys = array( self::META_MPN );
		if ( $this->needs_gtin_field() ) {
			$keys[] = self::META_GTIN;
		}
		foreach ( $keys as $key ) {
			if ( ! isset( $_POST[ $key ][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				continue;
			}
			$val = sanitize_text_field( wp_unslash( $_POST[ $key ][ $i ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' === $val ) {
				delete_post_meta( $variation_id, $key );
			} else {
				update_post_meta( $variation_id, $key, $val );
			}
		}
	}
}

Lean_Schema_WC::instance();
