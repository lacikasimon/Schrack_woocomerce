<?php
/** Facet SQL regressions on disposable in-memory SQLite; no WordPress/database credentials. */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
function absint( mixed $value ): int { return abs( (int) $value ); }
function taxonomy_exists( string $taxonomy ): bool { return in_array( $taxonomy, array( 'product_cat', 'pa_ip', 'pa_color', 'pa_empty' ), true ); }
function get_term_children( int $id, string $taxonomy ): array { return 10 === $id ? array( 11 ) : array(); }
function wc_attribute_taxonomy_name( string $slug ): string { return 'pa_' . $slug; }
function get_option( string $name, mixed $default = false ): mixed { return $default; }
function is_wp_error( mixed $value ): bool { return false; }
class Schrack_Attribute_Extractor {
	public static function slugs(): array { return array( 'ip', 'color', 'empty' ); }
	public static function label_for_slug( string $slug ): string { return $slug; }
}
class WP_Term {
	public function __construct( public int $term_id, public string $name ) {}
}
class Facet_Test_DB {
	public string $prefix = 'testshop_';
	public string $posts = 'testshop_posts';
	public string $term_relationships = 'testshop_term_relationships';
	public string $term_taxonomy = 'testshop_term_taxonomy';
	public int $queries = 0;
	public PDO $db;
	public function __construct() { $this->db = new PDO( 'sqlite::memory:' ); }
	public function prepare( string $sql, array $params ): string {
		$index = 0;
		return preg_replace_callback( '/%[sd]/', function ( array $match ) use ( &$index, $params ): string {
			$value = $params[ $index++ ];
			return '%d' === $match[0] ? (string) (int) $value : $this->db->quote( (string) $value );
		}, $sql );
	}
	public function get_results( string $sql, string $mode ): array {
		++$this->queries;
		return $this->db->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}
}
$wpdb = new Facet_Test_DB();
$wpdb->db->exec( "CREATE TABLE testshop_posts (ID INTEGER, post_type TEXT, post_status TEXT);
CREATE TABLE testshop_term_taxonomy (term_taxonomy_id INTEGER, term_id INTEGER, taxonomy TEXT);
CREATE TABLE testshop_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER);
CREATE TABLE testshop_wc_product_meta_lookup (product_id INTEGER, stock_status TEXT);
INSERT INTO testshop_posts VALUES (1,'product','publish'),(2,'product','publish'),(3,'product','publish'),(4,'product','draft'),(5,'product','publish'),(6,'product_variation','publish');
INSERT INTO testshop_wc_product_meta_lookup VALUES (1,'instock'),(2,'onbackorder'),(3,'outofstock'),(4,'instock'),(5,'instock'),(6,'instock');
INSERT INTO testshop_term_taxonomy VALUES (101,1,'pa_ip'),(102,2,'pa_ip'),(103,3,'pa_color'),(104,4,'pa_color'),(110,10,'product_cat'),(111,11,'product_cat'),(112,12,'product_cat');
INSERT INTO testshop_term_relationships VALUES (1,101),(1,103),(1,110),(1,111),(2,102),(2,103),(2,111),(3,101),(3,104),(3,110),(4,101),(4,104),(4,110),(5,101),(5,104),(5,112),(6,101),(6,110);" );
function get_terms( array $args ): array {
	$GLOBALS['term_requests'][] = $args;
	$terms = array( 1 => 'IP10', 2 => 'IP2', 3 => 'Alb', 4 => 'Negru' );
	return array_map( static fn( int $id ): WP_Term => new WP_Term( $id, $terms[ $id ] ), $args['include'] );
}
require __DIR__ . '/../includes/class-schrack-product-filter-renderer.php';
$renderer = new Schrack_Product_Filter_Renderer();
$batch = new ReflectionMethod( $renderer, 'available_attribute_counts' );
$old = new ReflectionMethod( $renderer, 'available_term_counts' );
$checks = 0;
function verify_count( bool $ok, string $message ): void {
	++$GLOBALS['checks'];
	if ( ! $ok ) { throw new RuntimeException( $message ); }
}
foreach ( array( 0, 10, 11, 12, 999 ) as $category ) {
	$before = $wpdb->queries;
	$counts = $batch->invoke( $renderer, array( 'pa_ip', 'pa_color' ), $category );
	verify_count( 1 === $wpdb->queries - $before, 'All facets must use one aggregate query.' );
	foreach ( array( 'pa_ip', 'pa_color' ) as $taxonomy ) {
		verify_count( ( $counts[ $taxonomy ] ?? array() ) === $old->invoke( $renderer, $taxonomy, array(), $category ), 'Counts must match previous semantics, including descendants, backorders, distinct products and publication status.' );
	}
}
$scoped = $batch->invoke( $renderer, array( 'pa_ip', 'pa_color' ), 10 );
verify_count( array( 1 => 1, 2 => 1 ) === $scoped['pa_ip'] && array( 3 => 2 ) === $scoped['pa_color'], 'Drafts, unavailable products, variations and unrelated categories must be excluded.' );
$before = $wpdb->queries;
verify_count( array() === $batch->invoke( $renderer, array(), 10 ) && $before === $wpdb->queries, 'No attribute registry must perform no query.' );
$options = new ReflectionMethod( $renderer, 'attribute_filter_options' );
$before = $wpdb->queries;
$groups = $options->invoke( $renderer, 10 );
verify_count( array( 'pa_ip', 'pa_color' ) === array_keys( $groups ), 'Empty facets must be omitted.' );
verify_count( array( 'IP2', 'IP10' ) === array_column( $groups['pa_ip']['terms'], 'name' ), 'Natural option order must remain intact.' );
verify_count( array( 3 ) === $GLOBALS['term_requests'][1]['include'], 'Only available terms from the selected category should be fetched.' );
$options->invoke( $renderer, 10 );
verify_count( 1 === $wpdb->queries - $before, 'Repeated renders within the same request must reuse their counts.' );
$wpdb->db->exec( "UPDATE testshop_wc_product_meta_lookup SET stock_status='outofstock' WHERE product_id=2" );
$updated = $batch->invoke( new Schrack_Product_Filter_Renderer(), array( 'pa_color' ), 10 );
verify_count( array( 3 => 1 ) === $updated['pa_color'], 'Fresh count requests must observe stock changes.' );
echo "Product filter counts: {$checks} checks passed.\n";
