<?php
/** Run: php tests/edoc-contract.php — isolated protocol/catalog behavior tests, no HTTP. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['options'] = array(); $GLOBALS['products'] = array(); $GLOBALS['inclusive'] = false;
function get_option( $key, $fallback = false ) { return $GLOBALS['options'][ $key ] ?? $fallback; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
function wp_parse_url( $s ) { return parse_url( $s ); }
function wp_get_environment_type() { return 'production'; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function get_the_terms( $id, $tax ) { return false; }
function wc_get_product_id_by_sku( $sku ) { foreach ( $GLOBALS['products'] as $id => $p ) { if ( $p->get_sku() === $sku ) { return $id; } } return 0; }
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? false; }
function wc_prices_include_tax() { return $GLOBALS['inclusive']; }
function wc_tax_enabled() { return true; }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal( $n, $decimals = 2 ) { return number_format( (float) $n, $decimals, '.', '' ); }
function current_time( $format ) { return '2026-09-07 12:00:00'; }
class WC_Tax {
 public static function get_tax_class_slugs() { return array( 'reduced-rate' ); }
 public static function get_base_tax_rates( $class ) { return array( array( 'rate' => '' === $class ? '21.0000' : '11.0000', 'compound' => 'no' ) ); }
}
class WC_Product {
 public int $id = 0; public array $meta = array(); public array $props = array();
 public function __call( $name, $args ) { $field = substr( $name, 4 ); if ( str_starts_with( $name, 'set_' ) ) { $this->props[ $field ] = $args[0]; return; } if ( str_starts_with( $name, 'get_' ) ) { return array_key_exists( $field, $this->props ) ? $this->props[ $field ] : ''; } throw new RuntimeException( $name ); }
 public function set_stock_quantity( $quantity ) { $this->props['stock_quantity'] = '' === $quantity ? null : (float) $quantity; }
 public function get_meta( $key, ...$rest ) { return $this->meta[ $key ] ?? ''; }
 public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
 public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
 public function is_type( $s ) { return 'simple' === $s; }
 public function is_on_sale( ...$rest ) { return ! empty( $this->props['sale_price'] ) && (float) $this->props['sale_price'] < (float) ( $this->props['regular_price'] ?? 0 ); }
 public function save() { $this->id = $this->id ?: count( $GLOBALS['products'] ) + 1; $GLOBALS['products'][ $this->id ] = $this; return $this->id; }
}
class WC_Product_Simple extends WC_Product {}
foreach ( array( 'settings', 'category-markup', 'manual-price', 'edoc-client', 'edoc-importer' ) as $class ) { require __DIR__ . '/../includes/class-schrack-' . $class . '.php'; }
$count = 0;
function check( $yes, $label ) { global $count; ++$count; if ( ! $yes ) { throw new RuntimeException( 'FAILED: ' . $label ); } }
foreach ( json_decode( file_get_contents( __DIR__ . '/edoc-hmac-vectors.json' ), true ) as $v ) {
 $actual = Schrack_EDoc_Client::canonical( $v['method'], $v['route'], $v['query'], $v['key_id'], $v['timestamp'], $v['nonce'], $v['body'] );
 check( $actual === $v['canonical'], 'canonical independent fixture' );
 check( hash_hmac( 'sha256', $actual, $v['secret'] ) === $v['signature'], 'signature independent fixture' );
}
foreach ( array( 'http://example.com', 'file:///etc/passwd', 'https://name:password@example.com', 'https://example.com?query=1', 'https://example.com#fragment' ) as $url ) { check( ! Schrack_EDoc_Client::valid_url( $url ), 'reject unsafe origin' ); }
check( Schrack_EDoc_Client::valid_url( 'https://erp.example.com/install' ), 'HTTPS subdirectory origin' );
check( ! Schrack_EDoc_Client::enabled(), 'disabled by default' );
check( ! str_contains( Schrack_EDoc_Client::safe_error( new RuntimeException( 'database-password-private' ) ), 'database-password-private' ), 'unexpected exception redacted' );
check( 'Mesaj controlat' === Schrack_EDoc_Client::safe_error( new Schrack_EDoc_Exception( 'Mesaj controlat' ) ), 'controlled exception retained' );
$config = array( 'entity_id' => 7, 'tax_map' => array( '21' => '', '11' => 'reduced-rate' ) );
$GLOBALS['options'][Schrack_EDoc_Client::OPTION] = $config;
$i = new Schrack_EDoc_Importer( new Schrack_Settings() );
$row = array( 'id' => 1, 'entity_id' => 7, 'article_id' => 9, 'sku' => 'ERP-7-9', 'currency' => 'RON', 'name' => 'Cablu', 'code' => 'C-09', 'unit' => 'm', 'barcodes' => array( '12345' ), 'purchase_price' => '10.0000', 'vat_rate' => 21, 'enabled' => true, 'available' => true );
check( 'created' === $i->import_item( $row, $config ), 'create third source' );
$p = wc_get_product( 1 );
check( 'draft' === $p->get_status(), 'new products draft' );
check( '12.00' === $p->get_regular_price(), 'net shop markup' );
check( 'instock' === $p->get_stock_status() && false === $p->get_manage_stock() && null === $p->get_stock_quantity() && 'no' === $p->get_backorders(), 'availability only' );
check( 'edoc' === $p->get_meta( '_schrack_catalog_source' ) && '' === $p->get_meta( '_schrack_item_number' ), 'SOAP isolation' );
$p->set_status( 'publish' ); $p->set_name( 'Nume editorial' ); $p->set_description( 'Descriere proprie' ); $p->set_category_ids( array( 4 ) );
$GLOBALS['inclusive'] = true;
check( 'updated' === $i->import_item( $row, $config ), 'idempotent update' );
check( count( $GLOBALS['products'] ) === 1 && '14.52' === $p->get_regular_price(), 'gross shop markup correct exactly once' );
check( 'publish' === $p->get_status() && 'Nume editorial' === $p->get_name() && 'Descriere proprie' === $p->get_description() && array( 4 ) === $p->get_category_ids(), 'shop editorial data preserved' );
$p->update_meta_data( Schrack_Manual_Price::META_PRICE, '20' ); $i->import_item( $row, $config );
check( '20.00' === $p->get_regular_price(), 'manual price preserved when above automatic' );
$row['purchase_price'] = '30'; $i->import_item( $row, $config );
check( '43.56' === $p->get_regular_price() && '' === $p->get_meta( Schrack_Manual_Price::META_PRICE ), 'higher automatic replaces manual price' );
$row['available'] = false; $i->import_item( $row, $config );
$p->set_stock_status( 'instock' ); Schrack_EDoc_Importer::guard_publication( $p );
check( 'outofstock' === $p->get_stock_status(), 'availability cannot be overridden by product edit' );
$row['enabled'] = false; $i->import_item( $row, $config );
check( 'draft' === $p->get_status() && 'outofstock' === $p->get_stock_status() && count( $GLOBALS['products'] ) === 1, 'tombstone retains product' );
$p->set_status( 'publish' ); Schrack_EDoc_Importer::guard_publication( $p );
check( 'draft' === $p->get_status(), 'withdrawn cannot manually publish' );
$row['enabled'] = true; $row['vat_rate'] = 9; $i->import_item( $row, $config );
check( 'draft' === $p->get_status() && 'outofstock' === $p->get_stock_status(), 'missing VAT mapping blocks' );
$row['vat_rate'] = 21; $row['purchase_price'] = null; $i->import_item( $row, $config );
check( 'draft' === $p->get_status(), 'missing positive price blocks' );
$p->update_meta_data( '_schrack_catalog_source', 'telesystem' );
try { $i->import_item( $row, $config ); check( false, 'collision must reject' ); } catch ( RuntimeException $e ) { check( 'telesystem' === $p->get_meta( '_schrack_catalog_source' ), 'collision untouched' ); }
try { $i->import_item( array_merge( $row, array( 'entity_id' => 8, 'sku' => 'ERP-8-9' ) ), $config ); check( false, 'entity mismatch must reject' ); } catch ( RuntimeException $e ) { check( count( $GLOBALS['products'] ) === 1, 'cross entity rejected' ); }
check( null === $i->tax_class( 21, array( '21' => 'reduced-rate' ) ), 'mismatched actual Woo tax rejects' );
echo 'OK: ' . $count . " protocol/catalog assertions\n";
