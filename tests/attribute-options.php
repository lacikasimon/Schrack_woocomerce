<?php
/** Catalog facet fixtures use in-memory SQLite; no WordPress writes or network. */
require __DIR__ . '/product-filter-counts.php';
function apply_filters( string $hook, mixed $value ): mixed { return $GLOBALS['facet_rollback'] ?? $value; }
function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( string $value ): string { return esc_attr( $value ); }
function esc_html_e( string $value, string $domain ): void { echo esc_html( $value ); }
function esc_attr_e( string $value, string $domain ): void { echo esc_attr( $value ); }
function __( string $value, string $domain ): string { return $value; }
function checked( bool $value ): void { if ( $value ) { echo 'checked="checked"'; } }
$r = new Schrack_Product_Filter_Renderer();
$render = new ReflectionMethod( $r, 'attribute_filters_html' );
$empty = array( 'category' => 10, 'attributes' => array() );
$html = $render->invoke( $r, $empty );
verify_count( 2 === substr_count( $html, 'data-attribute-options-pending' ) && ! str_contains( $html, 'type="checkbox"' ), 'Unselected values are absent from first response; all group labels remain.' );
verify_count( str_contains( $html, 'data-attribute-category="10"' ) && str_contains( $html, '<noscript>' ), 'Category context and native GET fallback are available.' );
$selected = $empty; $selected['attributes']['pa_ip'] = array(1);
$html = $render->invoke( $r, $selected );
verify_count( str_contains( $html, 'name="attr[pa_ip][]"' ) && str_contains( $html, 'checked="checked"' ) && 1 === substr_count( $html, 'data-attribute-options-pending' ), 'Active URL selections retain complete original controls and counts.' );
$html = $r->render_attribute_options( 10, 'pa_ip' );
verify_count( str_contains( $html, 'IP10' ) && str_contains( $html, 'IP2' ) && ! str_contains( $html, 'pa_color' ) && ! str_contains( $html, 'data-attribute-options-pending' ), 'One requested group includes all available values in original order.' );
foreach ( array('product_cat','pa_missing','pa_ip"><script>') as $taxonomy ) { verify_count( '' === $r->render_attribute_options(10,$taxonomy), 'Unsupported taxonomy requests return no facet markup.' ); }
$GLOBALS['facet_rollback'] = false;
verify_count( ! str_contains( $render->invoke( $r, $empty ), 'data-attribute-options-pending' ), 'Rollback restores complete first-response controls.' );
unset($GLOBALS['facet_rollback']); $_GET['schrack_filters_full'] = '1';
verify_count( ! str_contains( $render->invoke( $r, $empty ), 'data-attribute-options-pending' ), 'JavaScript-free form fallback retains complete controls.' );
$_GET['schrack_filters_full'] = array('1');
verify_count( str_contains( $render->invoke( $r, $empty ), 'data-attribute-options-pending' ), 'Array query input cannot enable the scalar fallback.' );
echo "Attribute options total: {$checks} checks passed.\n";
