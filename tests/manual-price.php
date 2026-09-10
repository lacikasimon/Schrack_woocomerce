<?php
/**
 * Standalone price regressions with in-memory WooCommerce/WordPress doubles.
 * Run: php tests/manual-price.php
 */

define( 'ABSPATH', __DIR__ );

$test_meta     = array();
$test_products = array();
$checks        = 0;

function get_post_meta( int $id, string $key, bool $single = true ): mixed {
	return $GLOBALS['test_meta'][ $id ][ $key ] ?? '';
}

function update_post_meta( int $id, string $key, mixed $value ): void {
	$GLOBALS['test_meta'][ $id ][ $key ] = $value;
}

function delete_post_meta( int $id, string $key ): void {
	unset( $GLOBALS['test_meta'][ $id ][ $key ] );
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function current_time( string $type ): string {
	return '2026-09-10 12:00:00';
}

function wp_unslash( string $value ): string {
	return stripslashes( $value );
}

function wc_get_product( int $id ): mixed {
	return $GLOBALS['test_products'][ $id ] ?? false;
}

class WC_Product {
	public function __construct( private int $id, array $meta ) {
		$GLOBALS['test_meta'][ $id ]     = $meta;
		$GLOBALS['test_products'][ $id ] = $this;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		return get_post_meta( $this->id, $key, $single );
	}

	public function update_meta_data( string $key, mixed $value ): void {
		update_post_meta( $this->id, $key, $value );
	}

	public function delete_meta_data( string $key ): void {
		delete_post_meta( $this->id, $key );
	}

	public function get_regular_price( string $context = 'view' ): string {
		return (string) $this->get_meta( '_regular_price' );
	}

	public function get_price( string $context = 'view' ): string {
		return (string) $this->get_meta( '_price' );
	}

	public function set_regular_price( string $price ): void {
		$this->update_meta_data( '_regular_price', $price );
	}

	public function set_price( string $price ): void {
		$this->update_meta_data( '_price', $price );
	}
}

require_once __DIR__ . '/../includes/class-schrack-manual-price.php';
require_once __DIR__ . '/../includes/class-schrack-product-admin-fields.php';
require_once __DIR__ . '/../includes/class-schrack-product-mapper.php';
require_once __DIR__ . '/../includes/class-schrack-price-sync.php';

function check_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
	++$GLOBALS['checks'];
}

$manual = new WC_Product( 1, array( '_regular_price' => '120.00', '_price' => '120.00', '_sku' => 'OWN-001' ) );
$admin  = new Schrack_Product_Admin_Fields();
$_POST  = array( Schrack_Manual_Price::META_PRICE => '80,00' );
$admin->save_manual_price( $manual );
check_same( '80.00', $manual->get_price(), 'A lower manual price must be accepted for a handmade product' );
check_same( '', $manual->get_meta( Schrack_Manual_Price::META_AUTOMATIC ), 'The regular price must not become an automatic price' );
check_same( 'active', $manual->get_meta( Schrack_Manual_Price::META_STATUS ), 'The manual price must remain active' );

$manual->update_meta_data( Schrack_Manual_Price::META_AUTOMATIC, '999.00' );
$result = Schrack_Manual_Price::set_product_price( $manual, 75 );
check_same( false, $result['manual_overridden'], 'Stale automatic prices must not override manual products' );
check_same( '75.00', $manual->get_price(), 'The newly entered manual price must be applied' );
check_same( '', $manual->get_meta( Schrack_Manual_Price::META_AUTOMATIC ), 'Stale automatic metadata must be removed' );

foreach ( array( 'object', 'fast' ) as $mode ) {
	$manual->update_meta_data( Schrack_Manual_Price::META_AUTOMATIC, '999.00' );
	$result = 'object' === $mode
		? Schrack_Manual_Price::resolve_product( $manual, 500 )
		: Schrack_Manual_Price::resolve_product_id( 1, 500 );
	check_same( 75.0, $result['price'], $mode . ': computed prices must not replace manual product prices' );
	check_same( '', $manual->get_meta( Schrack_Manual_Price::META_AUTOMATIC ), $mode . ': automatic metadata must not be retained' );
}

$manual->update_meta_data( Schrack_Manual_Price::META_AUTOMATIC, '999.00' );
$manual->set_regular_price( '70.00' );
$manual->set_price( '60.00' );
$manual->update_meta_data( '_sale_price', '60.00' );
$_POST = array( Schrack_Manual_Price::META_PRICE => '' );
$admin->save_manual_price( $manual );
check_same( '70.00', $manual->get_regular_price(), 'Clearing manual protection must preserve the entered regular price' );
check_same( '60.00', $manual->get_price(), 'Clearing manual protection must preserve the active sale price' );
check_same( '', $manual->get_meta( Schrack_Manual_Price::META_AUTOMATIC ), 'Clearing must remove stale automatic metadata' );
check_same( '', $manual->get_meta( Schrack_Manual_Price::META_STATUS ), 'Clearing must remove manual status' );

$manual->update_meta_data( Schrack_Manual_Price::META_AUTOMATIC, '999.00' );
$_POST = array();
$admin->save_manual_price( $manual );
check_same( '', $manual->get_meta( Schrack_Manual_Price::META_AUTOMATIC ), 'An ordinary save must also remove stale automatic metadata' );

// Dependencies are deliberately uninitialized: skipped sync must not reach markup,
// supplier API, lookup table writes, or the product save operation.
$mapper = ( new ReflectionClass( Schrack_Product_Mapper::class ) )->newInstanceWithoutConstructor();
$sync   = ( new ReflectionClass( Schrack_Price_Sync::class ) )->newInstanceWithoutConstructor();
foreach ( array( '60.00', '0.00', '' ) as $price ) {
	$manual->set_price( $price );
	$before = $test_meta[1];
	check_same( (float) $price, $mapper->update_price( 1, 1000 ), 'Normal sync must keep the current manual product price' );
	check_same( (float) $price, $mapper->update_price_fast( 1, 1000 ), 'Fast sync must keep the current manual product price' );
	check_same( null, $sync->sync_product( 1 ), 'Single-product sync must skip the supplier API' );
	check_same( $before, $test_meta[1], 'Skipped sync must leave all metadata untouched, including sale/empty/zero prices' );
}

foreach ( array( 'schrack', 'telesystem', 'edoc', 'legacy-schrack', 'legacy-telesystem' ) as $source ) {
	$metadata = str_starts_with( $source, 'legacy-' )
		? array( '_' . substr( $source, 7 ) . '_item_number' => 'ITEM-1' )
		: array( '_schrack_catalog_source' => $source );
	$product = new WC_Product( 2, $metadata + array( '_regular_price' => '100.00', Schrack_Manual_Price::META_AUTOMATIC => '100.00' ) );
	check_same( true, Schrack_Manual_Price::is_supplier_product( $product ), $source . ': supplier identity must be recognized' );
	$result = Schrack_Manual_Price::set_product_price( $product, 120 );
	check_same( true, $result['manual_active'], $source . ': manual price above automatic must stay active' );
	$result = Schrack_Manual_Price::resolve_product( $product, 120 );
	check_same( false, $result['manual_overridden'], $source . ': equal automatic price must preserve the manual price' );
	$result = Schrack_Manual_Price::resolve_product_id( 2, 130 );
	check_same( true, $result['manual_overridden'], $source . ': higher automatic price must still override' );
	check_same( '120.00', $product->get_meta( Schrack_Manual_Price::META_PREVIOUS ), $source . ': previous price must remain recorded' );
	check_same( '', $product->get_meta( Schrack_Manual_Price::META_PRICE ), $source . ': overridden manual price must be removed' );
	Schrack_Manual_Price::set_product_price( $product, 150 );
	Schrack_Manual_Price::clear_product_price( $product );
	check_same( '130.00', $product->get_price(), $source . ': clearing must still restore the real automatic price' );
}

$other = new WC_Product( 3, array( '_schrack_catalog_source' => 'manual', '_schrack_item_number' => 'OLD-ID' ) );
check_same( false, Schrack_Manual_Price::is_supplier_product( $other ), 'An explicit non-supplier source must not be treated as a legacy supplier import' );

$erp = new WC_Product( 4, array( '_schrack_catalog_source' => 'edoc', '_regular_price' => '25.00', '_price' => '25.00' ) );
$before = $test_meta[4];
check_same( 25.0, $mapper->update_price( 4, 1000 ), 'Schrack mapping must not change ERP pricing' );
check_same( 25.0, $mapper->update_price_fast( 4, 1000 ), 'Fast Schrack mapping must not change ERP pricing' );
check_same( null, $sync->sync_product( 4 ), 'ERP products must not reach the Schrack SOAP client' );
check_same( $before, $test_meta[4], 'Skipped ERP sync must leave metadata untouched' );

echo 'Passed ' . $checks . " price regression checks.\n";
