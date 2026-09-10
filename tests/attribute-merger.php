<?php
/**
 * Run: php tests/attribute-merger.php [optional-wide-export.csv]
 * Pure planning/mapper regressions; no WordPress database is needed.
 */

define( 'ABSPATH', __DIR__ );
$checks = 0;
$options = array();
function wc_attribute_taxonomy_name( string $slug ): string { return 'pa_' . $slug; }
function sanitize_title( string $value ): string { return strtolower( str_replace( ' ', '-', $value ) ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wc_get_text_attributes( string $value ): array { return array_map( 'trim', explode( '|', $value ) ); }
function get_option( string $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }

require_once __DIR__ . '/../includes/class-schrack-attribute-merger.php';
require_once __DIR__ . '/../includes/class-schrack-product-mapper.php';

function same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
	++$GLOBALS['checks'];
}
function definition( int $id, string $slug, string $label ): array {
	return array( 'attribute_id' => $id, 'attribute_name' => $slug, 'attribute_label' => $label, 'attribute_type' => 'select' );
}
function attribute( string $name, int $position = 0, bool $taxonomy = true, string $value = '' ): array {
	return array( 'name' => $name, 'value' => $value, 'position' => $position, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => (int) $taxonomy );
}
function rejects( callable $callback, string $contains ): void {
	try { $callback(); } catch ( RuntimeException $error ) {
		same( true, str_contains( $error->getMessage(), $contains ), 'Expected failure reason' );
		return;
	}
	throw new RuntimeException( 'Expected rejection: ' . $contains );
}

$merger = new Schrack_Attribute_Merger( array(
	definition( 30, 'tip_10', 'Tip' ), definition( 20, 'tip_2', 'Tip' ), definition( 10, 'tip', 'Tip' ),
	definition( 40, 'tip-mm', 'Tip (mm)' ), definition( 41, 'inaltime', 'Înălțime' ), definition( 42, 'inaltime2', 'Inaltime' ),
) );
same( 1, count( $merger->groups() ), 'Different units and accents must not be merged' );
same( 'tip', $merger->groups()['tip'][0]['attribute_name'], 'Canonical taxonomy follows export order' );
same( 'înălțime x', Schrack_Attribute_Merger::label_key( " ÎNĂLȚIME\u{00a0} X " ), 'Unicode case and spaces' );

$raw = array( 'pa_tip_10' => attribute( 'pa_tip_10', 1 ), 'untouched' => attribute( 'untouched', 2, false, 'A\\B | C' ), 'pa_tip_2' => attribute( 'pa_tip_2', 5 ), 'pa_tip' => attribute( 'pa_tip', 9 ) );
$values = array( 'pa_tip' => array(), 'pa_tip_2' => array( '0' ), 'pa_tip_10' => array( 'later' ) );
$plan = $merger->plan_product( $raw, fn ( $tax ) => $values[ $tax ] );
same( array( '0' ), $plan['assign']['pa_tip'], 'First filled column uses natural order and keeps zero' );
same( true, $plan['decisions'][0]['conflict'], 'Different values are reported' );
same( 1, $plan['attributes']['pa_tip']['position'], 'Keep earliest display position' );
same( $raw['untouched'], $plan['attributes']['untouched'], 'Unrelated local attributes and backslashes unchanged' );
same( 2, count( $plan['attributes'] ), 'Collapse duplicate fields' );
$again = $merger->plan_product( $plan['attributes'], fn ( $tax ) => $plan['assign'][ $tax ] );
same( array(), $again['decisions'], 'Second run is a no-op' );

$values['pa_tip'] = array( 'A, B', 'C\\D' );
$plan = $merger->plan_product( $raw, fn ( $tax ) => $values[ $tax ] );
same( array( 'A, B', 'C\\D' ), $plan['assign']['pa_tip'], 'Retain entire first populated multivalue list' );
$values = array( 'pa_tip' => array( 'Fixa', 'СИНИЙ' ), 'pa_tip_2' => array( 'fixa', 'синий' ), 'pa_tip_10' => array() );
$plan = $merger->plan_product( $raw, fn ( $tax ) => $values[ $tax ] );
same( false, $plan['decisions'][0]['conflict'], 'Case-only value differences are not conflicts' );
same( array( 'Fixa', 'СИНИЙ' ), $plan['assign']['pa_tip'], 'The winning list retains its original spelling' );
$values['pa_tip'] = array( 'transparenta' ); $values['pa_tip_2'] = array( 'transparentă' );
same( true, $merger->plan_product( $raw, fn ( $tax ) => $values[ $tax ] )['decisions'][0]['conflict'], 'Accent differences remain conflicts' );
$values = array_fill_keys( array_keys( $values ), array() );
$plan = $merger->plan_product( $raw, fn ( $tax ) => $values[ $tax ] );
same( array(), $plan['assign']['pa_tip'], 'All empty attributes consolidate without invented values' );
$raw['tip'] = attribute( 'Tip', 0, false, 'local first | extra' );
$plan = $merger->plan_product( $raw, fn ( $tax ) => array() );
same( array( 'local first', 'extra' ), $plan['assign']['pa_tip'], 'Local values fill empty same-label global attributes' );
$raw['pa_tip_2']['is_variation'] = 1;
rejects( fn () => $merger->plan_product( $raw, fn () => array() ), 'Variation attribute' );
$same_slug = new Schrack_Attribute_Merger( array( definition( 2, 'tip', 'Tip' ), definition( 1, 'tip', 'Tip' ) ) );
same( 1, $same_slug->groups()['tip'][0]['attribute_id'], 'Same-slug DB duplicates keep lowest ID' );
rejects( fn () => new Schrack_Attribute_Merger( array( definition( 1, 'tip', 'Tip' ), definition( 2, 'tip', 'Other' ) ) ), 'Conflicting labels' );

$options[Schrack_Attribute_Merger::REGISTRY_OPTION] = array( 'slugs' => array( 'tip_2' => 'tip' ), 'labels' => array( 'tip' => 'tip' ) );
$reflection = new ReflectionClass( Schrack_Product_Mapper::class );
$mapper = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod( 'normalized_dynamic_attributes' );
$normalized = $normalize->invoke( $mapper, array(
	'tip' => array( 'label' => 'Tip', 'value' => '' ),
	'tip_2' => array( 'label' => 'Tip', 'value' => '0' ),
	'tip_999' => array( 'label' => 'Tip', 'value' => 'later' ),
	'other' => array( 'label' => 'Something else', 'value' => 'unchanged' ),
) );
same( array( 'label' => 'Tip', 'value' => '0' ), $normalized['tip'], 'Imports use saved redirects and keep first filled value' );
same( false, isset( $normalized['tip_999'] ), 'New same-label columns cannot recreate duplicates' );
same( 'unchanged', $normalized['other']['value'], 'Unmerged labels retain existing import behavior' );
$options = array();
$mapper = $reflection->newInstanceWithoutConstructor();
$normalized = $normalize->invoke( $mapper, array( 'tip_2' => array( 'label' => 'Tip', 'value' => 'old behavior' ) ) );
same( true, isset( $normalized['tip_2'] ), 'Merge behavior is opt-in through the saved registry' );

if ( isset( $argv[1] ) ) {
	$file = fopen( $argv[1], 'r' );
	$headers = fgetcsv( $file, 0, ',', '"', '' );
	$definitions = $columns = array();
	foreach ( $headers as $index => $header ) {
		if ( preg_match( '/^Atribut: (.*) \[(pa_.*)\]$/u', $header, $match ) ) {
			$columns[ $index ] = $match[2];
			$definitions[] = definition( $index, substr( $match[2], 3 ), $match[1] );
		}
	}
	$merger = new Schrack_Attribute_Merger( $definitions );
	$rows = $changed = $conflicts = 0;
	while ( false !== ( $row = fgetcsv( $file, 0, ',', '"', '' ) ) ) {
		++$rows;
		$raw = $values = array();
		foreach ( $columns as $index => $name ) {
			if ( '' !== trim( $row[ $index ] ) ) {
				$raw[ $name ] = attribute( $name, $index );
				// Treat the whole exported cell as one token to verify column priority independently of CSV value splitting.
				$values[ $name ] = array( $row[ $index ] );
			}
		}
		$plan = $merger->plan_product( array_reverse( $raw, true ), fn ( $tax ) => $values[ $tax ] );
		$changed += (int) (bool) $plan['decisions'];
		foreach ( $plan['decisions'] as $decision ) {
			++$checks;
			$conflicts += (int) $decision['conflict'];
			$expected = null;
			foreach ( $merger->groups()[ Schrack_Attribute_Merger::label_key( $decision['label'] ) ] as $definition ) {
				$name = 'pa_' . $definition['attribute_name'];
				if ( isset( $values[ $name ] ) ) { $expected = $values[ $name ]; break; }
			}
			same( $expected, $decision['values'], 'First nonempty CSV column for product ' . $row[0] );
		}
		$after_values = array_merge( $values, $plan['assign'] );
		same( array(), $merger->plan_product( $plan['attributes'], fn ( $tax ) => $after_values[ $tax ] )['decisions'], 'CSV row idempotence ' . $row[0] );
	}
	fclose( $file );
	echo json_encode( array( 'csv_rows' => $rows, 'duplicate_groups' => count( $merger->groups() ), 'products_with_populated_source_columns' => $changed, 'conflicts' => $conflicts ), JSON_UNESCAPED_UNICODE ) . "\n";
}
echo "Passed {$checks} attribute merger checks.\n";
