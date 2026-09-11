<?php
/**
 * Standalone cart lifecycle regressions; no WordPress database or credentials.
 * php tests/required-services-cart.php
 * Optionally exercise the SAME cases against an official WC_Cart source file:
 * php tests/required-services-cart.php /path/to/woocommerce/includes/class-wc-cart.php
 * That mode replaces only the cart double; products, sessions and totals stay isolated.
 */
define( 'ABSPATH', __DIR__ );
$hooks = $products = $notices = array();
$checks = 0;
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$hook][$priority][] = array( $callback, $args ); }
function add_action( ...$args ) { add_filter( ...$args ); }
function apply_filters( $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['hooks'][$hook] ?? array(); ksort( $callbacks );
	foreach ( $callbacks as $group ) { foreach ( $group as [$fn, $count] ) { $value = $fn( ...array_slice( array_merge( array( $value ), $args ), 0, $count ) ); } }
	return $value;
}
function do_action( $hook, ...$args ) {
	$callbacks = $GLOBALS['hooks'][$hook] ?? array(); ksort( $callbacks );
	foreach ( $callbacks as $group ) { foreach ( $group as [$fn, $count] ) { $fn( ...array_slice( $args, 0, $count ) ); } }
}
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function wc_get_product( $id ) { return $GLOBALS['products'][$id] ?? false; }
function post_password_required( $id ) { return wc_get_product( $id )->protected; }
function wc_add_notice( $message, $type = 'success' ) { $GLOBALS['notices'][] = array( $message, $type ); }
function WC() { return $GLOBALS['wc']; }
function absint( $id ) { return abs( (int) $id ); }
function get_post_type( $id ) { return wc_get_product( $id )->is_type( 'variation' ) ? 'product_variation' : 'product'; }
function wp_get_post_parent_id( $id ) { return wc_get_product( $id )->get_parent_id(); }
function wc_get_product_variation_attributes( $id ) { return array(); }
function wc_get_cart_item_data_hash( $product ) { return 'test-hash'; }
function wc_do_deprecated_action( ...$args ) {}
function wc_stock_amount( $quantity ) { return $quantity; }
function wc_wp_theme_get_element_class_name( $element ) { return ''; }
function wc_get_cart_url() { return '/cart'; }
function wc_format_stock_quantity_for_display( $quantity, $product ) { return $quantity; }
class WC_Product {
	public array $meta = array();
	public string $type = 'simple', $status = 'publish';
	public bool $protected = false, $purchasable = true, $in_stock = true, $sold_individually = false, $backorders = false;
	public int $parent_id = 0;
	public float $stock = 100, $price = 10;
	public function __construct( public int $id ) { $GLOBALS['products'][$id] = $this; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent_id; }
	public function get_meta( $key, $single = true ) { return $this->meta[$key] ?? ''; }
	public function is_type( $type ) { return in_array( $this->type, (array) $type, true ); }
	public function get_status() { return $this->status; }
	public function get_name() { return 'Product ' . $this->id; }
	public function is_purchasable() { return $this->purchasable; }
	public function is_in_stock() { return $this->in_stock; }
	public function get_stock_managed_by_id() { return $this->id; }
	public function has_enough_stock( $quantity ) { return $this->backorders || $quantity <= $this->stock; }
	public function is_sold_individually() { return $this->sold_individually; }
	public function get_min_purchase_quantity() { return 1; }
	public function get_max_purchase_quantity() { return $this->backorders ? -1 : $this->stock; }
	public function managing_stock() { return true; }
	public function get_stock_quantity() { return $this->stock; }
	public function get_attributes() { return array(); }
	public function get_variation_attributes() { return array(); }
}
if ( isset( $argv[1] ) ) {
	define( 'WC_ABSPATH', dirname( dirname( realpath( $argv[1] ) ) ) . '/' );
	require $argv[1];
} else {
	class WC_Legacy_Cart {}
	// Preserve native hook order: duplicate adds first fire quantity-update, then add-to-cart.
	class WC_Cart extends WC_Legacy_Cart {
		public array $cart_contents = array(), $removed_cart_contents = array();
		public string $cart_context = 'shortcode';
		public function get_cart_contents() { return $this->cart_contents; }
		public function set_cart_contents( $items ) { $this->cart_contents = $items; }
		public function get_removed_cart_contents() { return $this->removed_cart_contents; }
		public function add_to_cart( $id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ) {
			try {
				$product = wc_get_product( $variation_id ?: $id );
				$data = apply_filters( 'woocommerce_add_cart_item_data', $data, $id, $variation_id, $quantity );
				$key = md5( serialize( array( $id, $variation_id, $variation, $data ) ) );
				if ( isset( $this->cart_contents[$key] ) ) { $this->set_quantity( $key, $quantity + $this->cart_contents[$key]['quantity'], false ); }
				else { $this->cart_contents[$key] = apply_filters( 'woocommerce_add_cart_item', array_merge( $data, array( 'key' => $key, 'product_id' => $id, 'variation_id' => $variation_id, 'variation' => $variation, 'data' => $product, 'quantity' => $quantity ) ), $key ); }
				do_action( 'woocommerce_add_to_cart', $key, $id, $quantity, $variation_id, $variation, $data );
				return $key;
			} catch ( Exception $error ) { wc_add_notice( $error->getMessage(), 'error' ); return false; }
		}
		public function set_quantity( $key, $quantity = 1, $refresh = true ) {
			if ( $quantity <= 0 ) return $this->remove_cart_item( $key );
			$old = $this->cart_contents[$key]['quantity']; $this->cart_contents[$key]['quantity'] = $quantity;
			do_action( 'woocommerce_after_cart_item_quantity_update', $key, $quantity, $old, $this );
			if ( $refresh ) $this->calculate_totals();
			return true;
		}
		public function remove_cart_item( $key ) {
			if ( ! isset( $this->cart_contents[$key] ) ) return false;
			$this->removed_cart_contents[$key] = $this->cart_contents[$key]; unset( $this->removed_cart_contents[$key]['data'] );
			do_action( 'woocommerce_remove_cart_item', $key, $this ); unset( $this->cart_contents[$key] );
			do_action( 'woocommerce_cart_item_removed', $key, $this ); return true;
		}
		public function restore_cart_item( $key ) {
			if ( ! isset( $this->removed_cart_contents[$key] ) ) return false;
			$this->cart_contents[$key] = $this->removed_cart_contents[$key];
			$this->cart_contents[$key]['data'] = wc_get_product( $this->cart_contents[$key]['variation_id'] ?: $this->cart_contents[$key]['product_id'] );
			do_action( 'woocommerce_restore_cart_item', $key, $this ); unset( $this->removed_cart_contents[$key] );
			do_action( 'woocommerce_cart_item_restored', $key, $this ); return true;
		}
	}
}
class Schrack_Test_Cart extends WC_Cart {
	public float $test_total = 0;
	public function __construct() {} // No real session/database/customer.
	public function get_cart() { return $this->get_cart_contents(); }
	public function calculate_totals() {
		$this->test_total = array_sum( array_map( fn( $item ) => $item['quantity'] * $item['data']->price, $this->get_cart_contents() ) );
	}
}
require __DIR__ . '/../includes/class-schrack-product-services.php';
require __DIR__ . '/../includes/class-schrack-required-services-cart.php';
$wc = (object) array( 'cart' => new Schrack_Test_Cart() );
$manager = new Schrack_Required_Services_Cart(); $manager->init();
function expect( $expected, $actual, $message ) {
	if ( $expected !== $actual ) throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
	++$GLOBALS['checks'];
}
function fixture() {
	$GLOBALS['wc']->cart = new Schrack_Test_Cart(); $GLOBALS['products'] = $GLOBALS['notices'] = array();
	foreach ( array( 1, 2, 3, 4, 5, 10, 11, 12 ) as $id ) new WC_Product( $id );
	wc_get_product( 1 )->meta[Schrack_Product_Services::REQUIRED_META_KEY] = array( 2, 3 );
	wc_get_product( 4 )->meta[Schrack_Product_Services::REQUIRED_META_KEY] = array( 2 );
	wc_get_product( 10 )->type = 'variable';
	wc_get_product( 10 )->meta[Schrack_Product_Services::REQUIRED_META_KEY] = array( 2 );
	foreach ( array( 11, 12 ) as $id ) { wc_get_product( $id )->type = 'variation'; wc_get_product( $id )->parent_id = 10; }
	return WC()->cart;
}
function child( $parent, $id ) {
	foreach ( WC()->cart->get_cart_contents() as $key => $item ) if ( ( $item[Schrack_Required_Services_Cart::PARENT_KEY] ?? '' ) === $parent && $item['product_id'] === $id ) return $key;
	throw new RuntimeException( 'Child missing' );
}
function qty( $key ) { return WC()->cart->get_cart_contents()[$key]['quantity']; }
$cart = fixture(); $main = $cart->add_to_cart( 1, 2 );
expect( 3, count( $cart->get_cart_contents() ), 'Two mandatory services accompany the product' );
expect( 2, qty( child( $main, 2 ) ), 'Service quantity follows product' );
$cart->calculate_totals(); expect( 60.0, $cart->test_total, 'All three native prices are charged' );
expect( $main, $cart->add_to_cart( 1, 1 ), 'Repeated add merges the same parent line' );
expect( 3, qty( child( $main, 3 ) ), 'Repeated add increases each service once' );
$cart->set_quantity( $main, 5 ); expect( 5, qty( child( $main, 2 ) ), 'Parent quantity change updates service' );
$cart->set_quantity( child( $main, 2 ), 9 ); expect( 5, qty( child( $main, 2 ) ), 'Direct child edits cannot break quantity invariant' );
$other = $cart->add_to_cart( 4 ); $standalone = $cart->add_to_cart( 2 ); $unrelated = $cart->add_to_cart( 5 );
expect( 7, count( $cart->get_cart_contents() ), 'Shared services and standalone purchases have independent lines' );
$removed = child( $main, 2 ); $cart->remove_cart_item( $removed );
expect( array( $other, child( $other, 2 ), $standalone, $unrelated ), array_keys( $cart->get_cart_contents() ), 'Deleting service removes only its parent and sibling services' );
$cart->restore_cart_item( $removed ); expect( 7, count( $cart->get_cart_contents() ), 'Undo on service restores the entire group' );
$cart->remove_cart_item( $main ); expect( 4, count( $cart->get_cart_contents() ), 'Removing parent removes its services' );
$cart->restore_cart_item( $main ); expect( 7, count( $cart->get_cart_contents() ), 'Undo on product restores required services' );
$cart->set_quantity( child( $main, 3 ), 0 ); expect( 4, count( $cart->get_cart_contents() ), 'Setting service quantity to zero removes the group' );

$cart = fixture(); $unrelated = $cart->add_to_cart( 5 ); wc_get_product( 2 )->stock = 1;
expect( false, $cart->add_to_cart( 1, 2 ), 'Insufficient service stock rejects a new parent' );
expect( array( $unrelated ), array_keys( $cart->get_cart_contents() ), 'Failure preserves unrelated cart lines' );
$main = $cart->add_to_cart( 1 ); $snapshot = $cart->get_cart_contents();
expect( false, $cart->add_to_cart( 1 ), 'Insufficient service stock rejects repeated parent add' );
expect( $snapshot, $cart->get_cart_contents(), 'Repeated-add failure rolls every quantity back' );
$cart->set_quantity( $main, 2 ); expect( $snapshot, $cart->get_cart_contents(), 'Quantity-update failure rolls back the group' );
expect( false, $cart->add_to_cart( 4 ), 'Stock check includes the same service linked to another parent' );
expect( $snapshot, $cart->get_cart_contents(), 'Aggregate stock failure is atomic' );
wc_get_product( 2 )->backorders = true;
expect( $main, $cart->add_to_cart( 1 ), 'Explicit WooCommerce backorders remain supported' );

foreach ( array( 'in_stock', 'purchasable' ) as $flag ) {
	$cart = fixture(); wc_get_product( 2 )->$flag = false;
	expect( false, $cart->add_to_cart( 1 ), 'Unavailable service rejects parent: ' . $flag ); expect( array(), $cart->get_cart_contents(), 'No partial lines remain' );
}
$cart = fixture(); wc_get_product( 2 )->protected = true; expect( false, $cart->add_to_cart( 1 ), 'Password-protected required service blocks purchase' );
$cart = fixture(); wc_get_product( 2 )->status = 'draft'; expect( false, $cart->add_to_cart( 1 ), 'Unpublished service blocks purchase' );
$cart = fixture(); wc_get_product( 2 )->type = 'variable'; expect( false, $cart->add_to_cart( 1 ), 'Service requiring variant selection cannot be auto-added' );
$cart = fixture(); wc_get_product( 2 )->sold_individually = true; expect( false, $cart->add_to_cart( 1, 2 ), 'Individual service prevents quantity mismatch' );
$cart = fixture(); wc_get_product( 2 )->meta[Schrack_Product_Services::REQUIRED_META_KEY] = array( 1 ); expect( false, $cart->add_to_cart( 1 ), 'Cycles cannot recurse or produce partial carts' );
$cart = fixture(); unset( $GLOBALS['products'][2] ); expect( false, $cart->add_to_cart( 1 ), 'Deleted required service blocks purchase' );
$cart = fixture(); $key = $cart->add_to_cart( 2, 1, 0, array(), array( Schrack_Required_Services_Cart::PARENT_KEY => 'forged', Schrack_Required_Services_Cart::SERVICES_KEY => array( 5 ) ) );
expect( false, isset( $cart->get_cart_contents()[$key][Schrack_Required_Services_Cart::PARENT_KEY] ), 'Caller cannot forge a service relationship' );
expect( false, isset( $cart->get_cart_contents()[$key][Schrack_Required_Services_Cart::SERVICES_KEY] ), 'Caller cannot forge required product configuration' );

// Simulate a third-party add hook refusing the second service after the first was added.
$reject_id = 0;
add_filter( 'woocommerce_add_cart_item', function ( $item ) use ( &$reject_id ) { if ( $item['product_id'] === $reject_id ) throw new Exception( 'Third-party rejection' ); return $item; } );
$cart = fixture(); $other = $cart->add_to_cart( 5 ); $snapshot = $cart->get_cart_contents(); $reject_id = 3;
expect( false, $cart->add_to_cart( 1 ), 'Late failure returns failure to the caller' );
expect( $snapshot, $cart->get_cart_contents(), 'Late second-service failure restores the entire previous cart' );
expect( 10.0, $cart->test_total, 'Rollback recomputes totals after intermediate child additions' ); $reject_id = 0;
$reject_validation = false;
add_filter( 'woocommerce_add_to_cart_validation', function ( $valid, $id ) use ( &$reject_validation ) { return $valid && ! ( $reject_validation && 2 === $id ); }, 10, 2 );
$cart = fixture(); $reject_validation = true; expect( false, $cart->add_to_cart( 1 ), 'Service-specific Woo validation is honored' ); $reject_validation = false;

$cart = fixture(); $main = $cart->add_to_cart( 10, 2, 11 ); $other = $cart->add_to_cart( 10, 1, 12 );
expect( 4, count( $cart->get_cart_contents() ), 'Variations inherit services and have separate relationships' );
$cart->remove_cart_item( child( $main, 2 ) ); expect( 2, count( $cart->get_cart_contents() ), 'Removing one variation service preserves the other variation' );

$cart = fixture(); $main = $cart->add_to_cart( 1 ); $snapshot = $cart->get_cart_contents();
$manager->check_session( $cart ); expect( $snapshot, $cart->get_cart_contents(), 'Valid groups survive session hydration' );
$manager->check_checkout(); expect( array(), $notices, 'Valid cart passes checkout' );
$broken = $snapshot; unset( $broken[child( $main, 2 )] ); $cart->set_cart_contents( $broken );
$manager->check_checkout(); expect( true, count( $notices ) > 0, 'Checkout rejects a missing required service' );
$manager->check_session( $cart ); expect( array(), $cart->get_cart_contents(), 'Session cleanup removes incomplete groups without silently adding charges' );
$cart->set_cart_contents( $snapshot ); wc_get_product( 1 )->meta[Schrack_Product_Services::REQUIRED_META_KEY] = array( 2, 3, 5 );
$manager->check_session( $cart ); expect( array(), $cart->get_cart_contents(), 'Changed service configuration requires explicit re-addition' );
$cart = fixture(); $main = $cart->add_to_cart( 1 ); $items = $cart->get_cart_contents(); unset( $items[$main] ); $cart->set_cart_contents( $items );
$manager->check_session( $cart ); expect( array(), $cart->get_cart_contents(), 'Orphan services are removed on hydration' );
$cart = fixture(); $main = $cart->add_to_cart( 1 ); $cart->remove_cart_item( $main ); wc_get_product( 2 )->in_stock = false;
$cart->restore_cart_item( $main ); expect( array(), $cart->get_cart_contents(), 'Undo cannot restore an unavailable partial bundle' );
$cart = fixture(); $main = $cart->add_to_cart( 1 ); $service = $cart->get_cart_contents()[child( $main, 2 )];
expect( true, str_contains( $manager->quantity_html( '<input type="number">', child( $main, 2 ), $service ), 'type="hidden"' ), 'Classic and custom carts show linked quantity without an editable input' );
expect( 'Product 1', $manager->item_data( array(), $service )[0]['value'], 'Cart identifies the owning product' );
expect( '<input>', $manager->quantity_html( '<input>', $main, $cart->get_cart_contents()[$main] ), 'Parent quantity remains editable' );

$cart = fixture(); $main = $cart->add_to_cart( 1 ); $cart->remove_cart_item( $main ); unset( $GLOBALS['products'][1] );
$cart->restore_cart_item( $main ); expect( array(), $cart->get_cart_contents(), 'Undo of a deleted parent removes the entire restored group safely' );

// The AJAX handler preserves selected variant attributes and returns errors in place.
class TestJsonResponse extends Error { public function __construct( public array $response, public int $status = 200 ) {} }
class WC_AJAX { public static function get_refreshed_fragments() { throw new TestJsonResponse( array( 'fragments' => array(), 'cart_hash' => 'test' ) ); } }
function check_ajax_referer( $action, $field, $die ) { return ( $_POST[$field] ?? '' ) === 'valid'; }
function wp_send_json( $data, $status = 200 ) { throw new TestJsonResponse( $data, $status ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ); }
function wc_clean( $value ) { return trim( strip_tags( $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wc_get_notices( $type = '' ) { return $type ? array_map( fn( $row ) => array( 'notice' => $row[0] ), array_values( array_filter( $GLOBALS['notices'], fn( $row ) => $row[1] === $type ) ) ) : array(); }
function wc_set_notices( $notices ) { $GLOBALS['notices'] = array(); }
function ajax_response() { try { $GLOBALS['manager']->ajax_add(); } catch ( TestJsonResponse $response ) { return $response; } throw new RuntimeException( 'No response' ); }
$cart = fixture(); $_POST = array( 'security' => 'invalid', 'product_id' => 1 );
expect( 403, ajax_response()->status, 'AJAX rejects an invalid nonce' ); expect( array(), $cart->get_cart_contents(), 'Invalid nonce cannot mutate cart' );
$_POST = array( 'security' => 'valid', 'product_id' => 11, 'quantity' => '2', 'attribute_color' => 'blue' );
$received_variation = null;
add_filter( 'woocommerce_add_to_cart_validation', function ( $valid, $id, $quantity, $variation_id = 0, $variation = array() ) use ( &$received_variation ) { if ( $variation_id ) $received_variation = $variation; return $valid; }, 20, 5 );
expect( true, isset( ajax_response()->response['fragments'] ), 'AJAX returns fragments after adding all required lines' );
expect( array( 'attribute_color' => 'blue' ), $received_variation, 'AJAX forwards the chosen attribute even for any-value variations' );
expect( 2, count( $cart->get_cart_contents() ), 'AJAX variation add creates its mandatory service' );
$cart = fixture(); wc_get_product( 2 )->in_stock = false; $_POST = array( 'security' => 'valid', 'product_id' => 1 );
expect( true, ajax_response()->response['error'], 'Unavailable-service AJAX response is an inline error' );
expect( array(), $cart->get_cart_contents(), 'Rejected AJAX request leaves no partial cart' );

echo 'Passed ' . $checks . ' required-service cart checks' . ( isset( $argv[1] ) ? ' against official WC_Cart' : ' with cart doubles' ) . ".\n";
