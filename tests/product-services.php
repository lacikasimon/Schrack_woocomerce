<?php
/**
 * Service linking and storefront regressions with in-memory WordPress/WooCommerce doubles.
 * Run: php tests/product-services.php
 */

define( 'ABSPATH', __DIR__ );
$products = array();
$hooks    = array();
$styles   = array();
$can_edit = true;
$checks   = 0;

function add_action( string $hook, mixed $callback ): void { $GLOBALS['hooks'][ $hook ] = $callback; }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'valid' === $nonce && 'schrack_save_product_services' === $action; }
function current_user_can( string $capability, int $id ): bool { return $GLOBALS['can_edit'] && 'edit_post' === $capability && 1 === $id; }
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function wc_get_product( int $id ): mixed { return $GLOBALS['products'][ $id ] ?? false; }
function wp_enqueue_style( string $handle ): void { $GLOBALS['styles'][] = $handle; }
function post_password_required( int $id ): bool { return $GLOBALS['products'][ $id ]->protected; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( string $text ): string { return esc_html( $text ); }
function esc_url( string $text ): string { return esc_attr( $text ); }
function esc_html_e( string $text, string $domain = '' ): void { echo esc_html( $text ); }
function esc_attr_e( string $text, string $domain = '' ): void { echo esc_attr( $text ); }
function wp_strip_all_tags( string $text ): string { return strip_tags( $text ); }
function strip_shortcodes( string $text ): string { return preg_replace( '/\[[^\]]*\]/', '', $text ); }
function wp_trim_words( string $text, int $limit ): string { return implode( ' ', array_slice( preg_split( '/\s+/', trim( $text ) ), 0, $limit ) ); }
function wp_kses_post( string $text ): string { return $text; } // Trusted fixture markup only; not a KSES integration test.
function wp_nonce_field( string $action, string $name ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="valid">'; }
function sanitize_key( string $text ): string { return $text; }
function sanitize_text_field( string $text ): string { return strip_tags( $text ); }
function sanitize_hex_color( string $text ): string { return $text; }
function absint( mixed $value ): int { return abs( (int) $value ); }

class WC_Product {
	public array $meta = array();
	public string $status = 'publish';
	public string $type = 'simple';
	public bool $visible = true;
	public bool $protected = false;
	public string $description = '';
	public string $price_html = '<span class="amount">1.500,00 lei</span>';

	public function __construct( private int $id, public string $name ) { $GLOBALS['products'][ $id ] = $this; }
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function is_type( string $type ): bool { return $this->type === $type; }
	public function get_status(): string { return $this->status; }
	public function is_visible(): bool { return $this->visible; }
	public function get_name(): string { return $this->name; }
	public function get_formatted_name(): string { return $this->name . ' (SRV-' . $this->id . ')'; }
	public function get_permalink(): string { return '/serviciu-' . $this->id; }
	public function get_short_description(): string { return $this->description; }
	public function get_price_html(): string { return $this->price_html; }
	public function get_image( string $size ): string { return '<img src="/placeholder.svg" alt="" width="80" height="80">'; }
}

require_once __DIR__ . '/../includes/class-schrack-product-services.php';
require_once __DIR__ . '/../includes/class-schrack-product-page-renderer.php';

function check_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
	++$GLOBALS['checks'];
}

$system = new WC_Product( 1, 'Sistem fotovoltaic 6 kW' );
$mount  = new WC_Product( 2, 'Montaj sistem fotovoltaic' );
$check  = new WC_Product( 3, 'Verificare și punere în funcțiune' );
$draft  = new WC_Product( 4, 'Draft service' );
$draft->status = 'draft';
$hidden = new WC_Product( 5, 'Hidden service' );
$hidden->visible = false;
$private = new WC_Product( 6, 'Private service' );
$private->status = 'private';
$protected = new WC_Product( 7, 'Protected service' );
$protected->protected = true;
$trashed = new WC_Product( 8, 'Trashed service' );
$trashed->status = 'trash';
$variation = new WC_Product( 9, 'Individual variation' );
$variation->type = 'variation';
$variable = new WC_Product( 10, 'Montaj cu opțiuni' );
$variable->type = 'variable';
$variable->price_html = '<span class="amount">500,00–900,00 lei</span>';
$no_price = new WC_Product( 11, 'Evaluare la cerere' );
$no_price->price_html = '';

$services = new Schrack_Product_Services();
$services->init();
check_same( array( $services, 'render_field' ), $hooks['woocommerce_product_options_related'], 'Selector must be in the linked-products panel' );
check_same( array( $services, 'save' ), $hooks['woocommerce_admin_process_product_object'], 'Links must save through WooCommerce CRUD' );

$_POST = array(
	'schrack_product_services_nonce' => 'valid',
	Schrack_Product_Services::META_KEY => array( '3', '2', '3', '1', '999', '0', '-2', '2oops', array( '2' ), '8', '9' ),
);
call_user_func( $hooks['woocommerce_admin_process_product_object'], $system );
check_same( array( 3, 2 ), $system->get_meta( Schrack_Product_Services::META_KEY ), 'Saving must retain order, deduplicate, and reject self/missing/invalid/trashed/variation links' );

$product_object = $system;
ob_start();
call_user_func( $hooks['woocommerce_product_options_related'] );
$field = ob_get_clean();
check_same( true, str_contains( $field, 'data-action="woocommerce_json_search_products"' ), 'Search must use the native product endpoint' );
check_same( true, str_contains( $field, 'data-exclude="1"' ), 'Search must exclude the source product' );
check_same( true, str_contains( $field, 'value="2" selected="selected"' ), 'Reopening the editor must display saved links' );
check_same( true, strpos( $field, 'value="3" selected' ) < strpos( $field, 'value="2" selected' ), 'Editor must preserve service order' );

foreach ( array( array(), array( 'schrack_product_services_nonce' => 'invalid' ), array( 'schrack_product_services_nonce' => array() ), array( 'schrack_product_services_nonce' => 'valid', Schrack_Product_Services::META_KEY => 'bad input' ) ) as $request ) {
	$_POST = $request;
	$services->save( $system );
	check_same( array( 3, 2 ), $system->get_meta( Schrack_Product_Services::META_KEY ), 'Imports/quick edits and invalid requests must preserve links' );
}
$_POST = array( 'schrack_product_services_nonce' => 'valid' );
$can_edit = false;
$services->save( $system );
check_same( array( 3, 2 ), $system->get_meta( Schrack_Product_Services::META_KEY ), 'An unauthorized save must preserve links' );
$can_edit = true;
$services->save( $system );
check_same( '', $system->get_meta( Schrack_Product_Services::META_KEY ), 'Removing every selection must clear the stored links' );
check_same( '', Schrack_Product_Services::render( $system ), 'No selections must produce no section' );
check_same( array(), $styles, 'An empty section must not enqueue styles' );

$system->update_meta_data( Schrack_Product_Services::META_KEY, array( 1, 3, 2, 2, 4, 5, 6, 7, 8, 9, 999, 10, 11 ) );
$mount->name = 'Montaj <test> & service "special"';
$mount->description = '<b>Montaj</b> [internal] pentru acoperiș & garaj.';
$html = Schrack_Product_Services::render( $system );
check_same( 4, substr_count( $html, '<article ' ), 'Only unique public service products may appear' );
check_same( true, strpos( $html, '/serviciu-3' ) < strpos( $html, '/serviciu-2' ), 'Storefront must preserve editor order' );
check_same( true, str_contains( $html, 'Montaj &lt;test&gt; &amp; service &quot;special&quot;' ), 'Service names must be escaped' );
check_same( false, str_contains( $html, '[internal]' ), 'Excerpts must omit shortcodes' );
check_same( true, str_contains( $html, $variable->price_html ), 'Variable service price ranges must remain intact' );
check_same( true, str_contains( $html, '/serviciu-10' ), 'Variable services must link to their option selection page' );
check_same( true, str_contains( $html, 'Evaluare la cerere' ), 'Services with no entered price must still be discoverable' );
check_same( false, str_contains( $html, 'add-to-cart' ), 'Recommendations must not automatically buy a service' );
foreach ( array( 1, 4, 5, 6, 7, 8, 9, 999 ) as $excluded_id ) {
	check_same( false, str_contains( $html, 'href="/serviciu-' . $excluded_id . '"' ), 'Unavailable/self product must not leak into the storefront: ' . $excluded_id );
}
$mount->price_html = '<span class="customer-price">1.200,00 lei</span>';
check_same( true, str_contains( Schrack_Product_Services::render( $system ), $mount->price_html ), 'Rendering again must use current WooCommerce price HTML' );
foreach ( array( array( 4, 5, 6, 7, 8, 9, 999 ), 'malformed metadata' ) as $ids ) {
	$system->update_meta_data( Schrack_Product_Services::META_KEY, $ids );
	check_same( '', Schrack_Product_Services::render( $system ), 'No eligible services must leave no empty section' );
}

$renderer = new Schrack_Product_Page_Renderer();
$sanitize = new ReflectionMethod( $renderer, 'sanitize_settings' );
check_same( true, $sanitize->invoke( $renderer, array() )['show_recommended_services'], 'Existing Elementor widgets must show services by default' );
check_same( false, $sanitize->invoke( $renderer, array( 'show_recommended_services' => '' ) )['show_recommended_services'], 'The Elementor switch must allow hiding services' );

if ( in_array( '--preview', $argv, true ) ) {
	$mount->name = 'Montaj sistem fotovoltaic';
	$mount->description = 'Instalarea panourilor, montajul invertorului și conectarea sistemului fotovoltaic.';
	$check->description = 'Verificarea conexiunilor electrice, testarea protecțiilor și configurarea sistemului.';
	$check->price_html = '<span class="amount">450,00 lei</span>';
	$system->update_meta_data( Schrack_Product_Services::META_KEY, array( 2, 3, 10, 11 ) );
	echo Schrack_Product_Services::render( $system );
} else {
	echo 'Passed ' . $checks . " service linking and display checks.\n";
}
