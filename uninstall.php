<?php
/**
 * Removes Lean Schema settings. Product-level Brand / MPN / GTIN values are kept
 * because they are useful product data in their own right.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lsw_options = array(
	'lsw_org_enabled',
	'lsw_org_type',
	'lsw_same_as',
	'lsw_breadcrumbs',
	'lsw_default_brand',
	'lsw_brand_url',
	'lsw_default_category',
	'lsw_condition',
	'lsw_reviews_limit',
	'lsw_country',
	'lsw_ship_rate',
	'lsw_handling_min',
	'lsw_handling_max',
	'lsw_transit_min',
	'lsw_transit_max',
	'lsw_return_days',
	'lsw_return_fees',
);

foreach ( $lsw_options as $lsw_option ) {
	delete_option( $lsw_option );
}
