<?php
/** Search response/facet contracts with an empty query double and disposable SQLite facets. */
require __DIR__ . '/product-filter-counts.php';
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ) ); }
function sanitize_hex_color( string $value ): string { return $value; }
function __( string $value, string $domain ): string { return $value; }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( string $value ): string { return esc_attr( $value ); }
function esc_html_e( string $value, string $domain ): void { echo esc_html( $value ); }
function esc_attr_e( string $value, string $domain ): void { echo esc_attr( $value ); }
function checked( bool $value ): void { if ( $value ) { echo 'checked="checked"'; } }
function apply_filters( string $hook, mixed $value ): mixed { return 'schrack_wc_sync_render_category_explorer' === $hook ? false : $value; }
function add_filter( string $hook, mixed $callback, int $priority, int $accepted = 1 ): void { $GLOBALS['search_hooks'][$hook][] = $callback; }
function remove_filter( string $hook, mixed $callback, int $priority ): void { $GLOBALS['search_hooks'][$hook] = array_filter( $GLOBALS['search_hooks'][$hook], static fn( $item ) => $item !== $callback ); }
class WP_Query {
	public array $posts = array();
	public int $found_posts = 0, $max_num_pages = 0;
	public function __construct( public array $args ) {
		$GLOBALS['last_search_args'] = $args;
		if ( ! empty( $GLOBALS['fail_search_query'] ) ) { throw new RuntimeException( 'Simulated query failure.' ); }
	}
}
$settings = array( 'show_attribute_filters' => 'yes', 'pagination_mode' => 'numbered' );
$filters = array( 'search' => 'corp', 'category' => 10, 'min_price' => 50, 'max_price' => 250, 'orderby' => 'price', 'attributes' => array( 'pa_ip' => array(1) ) );
$renderer = new Schrack_Product_Filter_Renderer();
$before = $wpdb->queries;
$result = $renderer->render_results( $settings, $filters, 10 );
verify_count( null === $result['facets_html'] && $wpdb->queries === $before, 'Search in the same category sends no redundant facet HTML and performs no facet SQL.' );
$args = $GLOBALS['last_search_args'];
verify_count( 'corp' === $args['schrack_product_filter_search'] && 50.0 === $args['schrack_product_filter_min_price'] && 250.0 === $args['schrack_product_filter_max_price'], 'Search keeps the entered price constraints.' );
verify_count( array(10) === $args['tax_query'][0]['terms'] && 'pa_ip' === $args['tax_query'][1]['taxonomy'] && array(1) === $args['tax_query'][1]['terms'], 'Search retains category and attribute constraints.' );
verify_count( 'price' === $args['schrack_product_filter_orderby'] && ! empty( $args['schrack_product_filter_hide_out_of_stock'] ), 'Stock and ordering remain authoritative.' );
$result = $renderer->render_results( $settings, $filters, 0 );
verify_count( is_string( $result['facets_html'] ) && str_contains( $result['facets_html'], 'data-facets-category="10"' ) && str_contains( $result['facets_html'], 'checked="checked"' ), 'Changing category sends current facets and preserves selected attribute values.' );
$result = $renderer->render_results( $settings, $filters );
verify_count( is_string( $result['facets_html'] ), 'Older clients without a category marker retain the complete facet response.' );
$settings['show_category_filter'] = 'no'; $settings['default_category'] = 10; $filters['category'] = 0;
verify_count( null === $renderer->render_results( $settings, $filters, 10 )['facets_html'], 'The category marker is compared after applying the configured default category.' );
$GLOBALS['fail_search_query'] = true;
try { $renderer->render_results( $settings, $filters, 10 ); } catch ( RuntimeException $error ) {}
verify_count( ! array_filter( $GLOBALS['search_hooks'] ), 'Failed queries remove all temporary filters.' );
echo "Search facets total: {$checks} checks passed.\n";
