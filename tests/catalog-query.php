<?php
/** Scoped WooCommerce query rewrite; no HTTP or database access. */
define( 'ABSPATH', __DIR__ );
$hooks = array(); $generating = false; $enabled = true; $throw = false; $checks = 0; $override = array();
$wpdb = (object) array( 'posts' => 'shop_posts', 'wc_product_meta_lookup' => 'shop_wc_product_meta_lookup' );
function add_filter( $hook, $fn, $priority, $accepted = 1 ) { $GLOBALS['hooks'][ $hook ][] = $fn; }
function remove_filter( $hook, $fn, $priority ) { $GLOBALS['hooks'][ $hook ] = array_values( array_filter( $GLOBALS['hooks'][ $hook ] ?? array(), fn( $item ) => $item !== $fn ) ); }
function apply_filters( $hook, $value, ...$args ) {
	if ( 'schrack_wc_sync_catalog_stock_lookup' === $hook ) { return $GLOBALS['enabled']; }
	foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $fn ) { $value = $fn( $value, ...$args ); }
	return $value;
}
function get_option( $key, $default = false ) { return $GLOBALS['generating']; }
class WP_Query { public function __construct( private array $args ) {} public function get( $key ) { return $this->args[ $key ] ?? ''; } }
function wc_get_products( $args ) {
	if ( $GLOBALS['throw'] ) { throw new RuntimeException( 'Query failed' ); }
	$wp = array_merge( array(
		'post_type' => 'product', 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC',
		'tax_query' => array( array( 'taxonomy' => 'product_cat', 'terms' => array( 'lights' ) ) ),
		'meta_query' => array( array( 'key' => '_stock_status', 'value' => 'instock', 'compare' => '=' ) ),
	), $GLOBALS['override'] );
	$GLOBALS['original_wp_args'] = $wp;
	$wp = apply_filters( 'woocommerce_product_data_store_cpt_get_products_query', $wp, $args );
	$GLOBALS['wp_args'] = $wp;
	$GLOBALS['where'] = apply_filters( 'posts_where', ' WHERE 1=1', new WP_Query( $wp ) );
	$GLOBALS['unrelated'] = apply_filters( 'posts_where', ' WHERE 1=1', new WP_Query( array() ) );
	$GLOBALS['nested'] = apply_filters( 'woocommerce_product_data_store_cpt_get_products_query', $GLOBALS['original_wp_args'], array() );
	return array( 10, 11 );
}
function _prime_post_caches( $ids, $terms, $meta ) { $GLOBALS['primed'] = array( $ids, $terms, $meta ); }
function verify_catalog( $ok, $why ) { if ( ! $ok ) { throw new RuntimeException( $why ); } $GLOBALS['checks']++; }
require __DIR__ . '/../includes/class-schrack-catalog-query.php';
$run = fn() => Schrack_Catalog_Query::product_ids( array( 'return' => 'ids', 'stock_status' => 'instock' ) );
verify_catalog( array( 10, 11 ) === $run(), 'IDs returned intact' );
verify_catalog( array() === $wp_args['meta_query'], 'Simple stock meta scan removed' );
verify_catalog( str_contains( $where, 'shop_wc_product_meta_lookup' ) && str_contains( $where, 'shop_posts.ID' ) && str_contains( $where, "stock_status = 'instock'" ), 'Actual tables and exact in-stock match used' );
verify_catalog( $wp_args['tax_query'] === $original_wp_args['tax_query'] && 'date' === $wp_args['orderby'], 'Category and order preserved' );
verify_catalog( ' WHERE 1=1' === $unrelated && $nested === $original_wp_args, 'Unrelated and nested requests unaffected' );
verify_catalog( ! array_filter( $hooks ), 'Temporary callbacks cleaned up' );
foreach ( array(
	array( 'meta_query' => array( array( 'key' => '_price', 'value' => 10, 'compare' => '>' ) ) ),
	array( 'meta_query' => array( array( 'key' => '_stock_status', 'value' => 'outofstock', 'compare' => '=' ) ) ),
	array( 'meta_query' => array( array( 'key' => '_stock_status', 'value' => array( 'instock', 'onbackorder' ), 'compare' => 'IN' ) ) ),
	array( 'meta_query' => array( array( array( 'key' => '_stock_status', 'value' => 'instock', 'compare' => '=' ) ) ) ),
	array( 'meta_key' => '_stock_status', 'orderby' => 'meta_value' ),
	array( 'post_type' => 'product_variation' ),
	array( 'fields' => 'all' ),
	array( 'suppress_filters' => true ),
) as $override ) {
	$run(); verify_catalog( $wp_args === $original_wp_args && ' WHERE 1=1' === $where, 'Unrecognized query uses native semantics' );
}
$override = array();
$generating = true; $run(); verify_catalog( $wp_args === $original_wp_args, 'Lookup regeneration bypasses optimization' ); $generating = false;
$enabled = false; $run(); verify_catalog( $wp_args === $original_wp_args, 'Opt out supported' ); $enabled = true;
$throw = true; try { $run(); } catch ( RuntimeException $error ) {} verify_catalog( ! array_filter( $hooks ), 'Query failure still removes callbacks' ); $throw = false;
Schrack_Catalog_Query::prime( array( 10, 11 ) ); verify_catalog( array( array( 10, 11 ), true, true ) === $primed, 'Posts terms and metadata primed in one batch' );
echo "Catalog query: $checks checks passed.\n";
