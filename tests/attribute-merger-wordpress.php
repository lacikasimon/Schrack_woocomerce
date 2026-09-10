<?php
/**
 * Integration fixture for a DISPOSABLE WordPress/WooCommerce install only.
 * Set its home/siteurl to http://merger.test and mount this plugin at
 * wp-content/plugins/schrack-woocommerce-sync. Disable WP-Cron for the test.
 *
 * wp eval-file tests/attribute-merger-wordpress.php seed
 * wp --require=scripts/merge-attributes.php schrack-merge-attributes --batch-size=2
 * wp eval-file tests/attribute-merger-wordpress.php dry
 * wp --require=scripts/merge-attributes.php schrack-merge-attributes --apply --backup=/private/before.sql --batch-size=2
 * wp eval-file tests/attribute-merger-wordpress.php verify
 * wp --require=scripts/merge-attributes.php schrack-merge-attributes
 * wp eval-file tests/attribute-merger-wordpress.php again
 * wp eval-file tests/attribute-merger-wordpress.php imports
 */
if ( 'http://merger.test' !== get_option( 'home' ) ) { throw new RuntimeException( 'Disposable test site only.' ); }
function expect_same( $a, $b, $label ) { if ( $a !== $b ) { throw new RuntimeException( $label ); } }
function attr_raw( $name, $value = '', $tax = 1 ) { return array( 'name' => $name, 'value' => $value, 'position' => 0, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => $tax ); }
function snapshot() {
 global $wpdb;
 $ids = implode( ',', array_values( get_option( 'merger_fixture_ids' ) ) );
 return array(
  'definitions' => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id", ARRAY_A ),
  'meta' => $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$ids}) ORDER BY meta_id", ARRAY_A ),
  'relationships' => $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ({$ids}) ORDER BY object_id, term_taxonomy_id", ARRAY_A ),
 );
}
$mode = $args[0] ?? 'seed';
if ( 'seed' === $mode ) {
 global $wpdb;
	foreach ( array( 'tip', 'tip_2', 'tip_10', 'same' ) as $slug ) {
	 if ( wc_attribute_taxonomy_id_by_name($slug) ) { continue; }
  $id = wc_create_attribute( array( 'name' => 'same' === $slug ? 'Same' : 'Tip', 'slug' => $slug ) );
  if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
 }
 foreach ( array('tip','tip_2','tip_10','same') as $slug ) { if (!taxonomy_exists('pa_'.$slug)) { register_taxonomy('pa_'.$slug, array('product'), array('hierarchical'=>false)); } }
 $wpdb->insert( $wpdb->prefix . 'woocommerce_attribute_taxonomies', array( 'attribute_name' => 'same', 'attribute_label' => 'Same', 'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0 ) );
 $term_ids = array();
 foreach ( array( 'pa_tip' => array( '0' ), 'pa_tip_2' => array( 'first', 'A, B', 'C\\D', 'unused' ), 'pa_tip_10' => array( 'later' ), 'pa_same' => array( 'retained' ) ) as $tax => $names ) {
  foreach ( $names as $name ) {
   $term = wp_insert_term( wp_slash( $name ), $tax );
   if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
   $term_ids[$tax][$name] = (int) $term['term_id'];
  }
 }
 add_term_meta( $term_ids['pa_tip_2']['unused'], 'swatch', array( 'colour' => '#123456' ) );
 add_term_meta( $term_ids['pa_tip_2']['unused'], 'order', 7 );
 $cases = array(
  'fallback' => array( 'pa_tip' => array(), 'pa_tip_2' => array( 'first' ), 'pa_tip_10' => array( 'later' ) ),
  'zero' => array( 'pa_tip' => array( '0' ), 'pa_tip_10' => array( 'later' ) ),
  'multi' => array( 'pa_tip_2' => array( 'A, B', 'C\\D' ), 'pa_tip_10' => array( 'later' ) ),
  'empty' => array( 'pa_tip' => array(), 'pa_tip_2' => array() ),
  'trash' => array( 'pa_tip_10' => array( 'later' ) ),
  'local' => array( 'pa_tip' => array(), 'pa_tip_2' => array() ),
  'same' => array( 'pa_same' => array( 'retained' ) ),
 );
 $ids = array();
 foreach ( $cases as $case => $taxes ) {
  $p = new WC_Product_Simple(); $p->set_name( $case ); $p->set_regular_price( '9.50' ); $p->set_sku( 'MERGER-' . $case ); $p->set_status( 'trash' === $case ? 'trash' : 'publish' ); $p->save();
  $ids[$case] = $p->get_id(); $raw = array();
  foreach ( $taxes as $tax => $names ) { $raw[$tax] = attr_raw( $tax ); wp_set_object_terms( $p->get_id(), array_map( fn($n) => $term_ids[$tax][$n], $names ), $tax ); }
  $raw['untouched'] = attr_raw( 'Untouched', 'A\\B | Keep', 0 );
  if ( 'local' === $case ) { $raw['tip'] = attr_raw( 'Tip', 'Local | Other', 0 ); }
  update_post_meta( $p->get_id(), '_product_attributes', wp_slash( $raw ) );
 }
 update_option( 'schrack_wc_sync_dynamic_attributes', array( 'tip_2' => 'Tip', 'tip_10' => 'Tip', 'unrelated' => 'Keep' ) );
 update_option( 'merger_fixture_ids', $ids );
 update_option( 'merger_snapshot', snapshot() );
 echo "Seeded " . count( $ids ) . " products.\n";
} elseif ( 'dry' === $mode ) {
 expect_same( get_option( 'merger_snapshot' ), snapshot(), 'Dry-run must not modify catalog data' );
 echo "Dry-run leaves all definitions, product metadata and relationships unchanged.\n";
} elseif ( 'verify' === $mode ) {
 global $wpdb;
 $ids = get_option( 'merger_fixture_ids' );
 foreach ( array( 'fallback' => array( 'first' ), 'zero' => array( '0' ), 'multi' => array( 'A, B', 'C\\D' ), 'empty' => array(), 'trash' => array( 'later' ), 'local' => array( 'Local', 'Other' ) ) as $case => $expected ) {
  $raw = get_post_meta( $ids[$case], '_product_attributes', true );
  expect_same( array( 'pa_tip', 'untouched' ), (function($k){sort($k);return $k;})(array_keys($raw)), $case . ' attribute keys' );
  expect_same( 'A\\B | Keep', $raw['untouched']['value'], 'Unrelated backslashes preserved' );
  $actual = wp_get_object_terms( $ids[$case], 'pa_tip', array( 'fields' => 'names' ) ); sort($expected); sort($actual);
  expect_same( $expected, $actual, $case . ' values' );
  expect_same( '9.50', get_post_meta( $ids[$case], '_regular_price', true ), 'Unrelated price preserved' );
 }
 expect_same( array('retained'), wp_get_object_terms($ids['same'], 'pa_same', array('fields'=>'names')), 'Same-slug terms survived' );
 expect_same( 2, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_attribute_taxonomies"), 'Only canonical definitions remain' );
 expect_same( 0, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('pa_tip_2','pa_tip_10')"), 'Source terms removed' );
 $unused = get_term_by('name','unused','pa_tip');
 expect_same( array('colour'=>'#123456'), get_term_meta($unused->term_id, 'swatch', true), 'Unused value metadata retained' );
 expect_same( '7', get_term_meta($unused->term_id, 'order', true), 'Unused value order retained' );
 $registry = get_option('schrack_wc_sync_merged_attributes');
 expect_same('tip', $registry['slugs']['tip_10'], 'Importer redirect');
 expect_same('tip', $registry['labels']['tip'], 'New feed column redirect');
 expect_same(array('unrelated'=>'Keep','tip'=>'Tip','same'=>'Same'), get_option('schrack_wc_sync_dynamic_attributes'), 'Filter registry');
 expect_same(0, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE taxonomy IN ('pa_tip_2','pa_tip_10')"), 'No stale filter index taxonomies');
 expect_same(true, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE taxonomy = 'pa_tip'") > 0, 'Filter index rebuilt');
 update_option('merger_after_snapshot',snapshot());
 echo "Verified product values, zero, multivalue, local, trash, same-slug definitions, unused terms/meta, importer/filter registry and filter lookup.\n";
} elseif ( 'again' === $mode ) {
 expect_same(get_option('merger_after_snapshot'),snapshot(),'Second run is a no-op');
 echo "Second run is a no-op.\n";
} elseif ( 'imports' === $mode ) {
 $plugin = WP_PLUGIN_DIR . '/schrack-woocommerce-sync/includes/';
 foreach (array('attribute-merger','product-exporter','product-importer','product-mapper') as $class) { require_once $plugin . 'class-schrack-' . $class . '.php'; }
 $importer = (new ReflectionClass(Schrack_Product_Importer::class))->newInstanceWithoutConstructor();
 $column = static fn($name) => Schrack_WC_Product_CSV_Exporter::schrack_attribute_column_id($name, 'Tip', true);
 $data = $importer->decode_structured_meta(array($column('pa_tip')=>'', $column('pa_tip_2')=>'0', $column('pa_tip_10')=>'later', $column('pa_tip_999')=>'new column', 'name'=>'unchanged'));
 expect_same(1,count($data['raw_attributes']),'Old CSV duplicate columns collapse');
 expect_same('pa_tip',$data['raw_attributes'][0]['name'],'Old CSV slug redirects');
 expect_same(array('0'),$data['raw_attributes'][0]['value'],'Old CSV first populated value wins');
 expect_same('unchanged',$data['name'],'CSV unrelated fields preserved');
 $data = $importer->decode_structured_meta(array($column('pa_tip_2')=>'A\\, B, C'));
 expect_same(array('A, B','C'),$data['raw_attributes'][0]['value'],'Escaped comma values still decode');
 $mapper = (new ReflectionClass(Schrack_Product_Mapper::class))->newInstanceWithoutConstructor();
 $assign = (new ReflectionClass($mapper))->getMethod('assign_extracted_attributes');
 $product = wc_get_product(get_option('merger_fixture_ids')['fallback']);
 $assign->invoke($mapper,$product,array('extracted_attributes'=>array('tip_2'=>array('label'=>'Tip','value'=>'first')), 'dynamic_technical_attributes'=>array('tip_10'=>array('label'=>'Tip','value'=>'later'),'tip_999'=>array('label'=>'Tip','value'=>'last'))));
 $product->save();
 expect_same(array('first'),wp_get_object_terms($product->get_id(),'pa_tip',array('fields'=>'names')),'Supplier import keeps first same-label value');
 expect_same(false,taxonomy_exists('pa_tip_10'),'Supplier import does not recreate removed taxonomy');
 echo "Verified supplier and wide CSV import redirects, first populated values and escaped commas.\n";
}
