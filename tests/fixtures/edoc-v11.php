<?php
/** Additional isolated fixtures, loaded by edoc-contract.php after catalog assertions. */
define( 'ARRAY_A', 'ARRAY_A' );
define( 'SCHRACK_WC_SYNC_VERSION', 'test-v1.1' );
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function get_woocommerce_currency() { return 'RON'; }
function is_ssl() { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_generate_uuid4() { static $id = 100; return sprintf( '00000000-0000-4000-8000-%012d', ++$id ); }
function wp_safe_remote_request( $url, $args ) { $GLOBALS['edoc_http_calls'][] = array( $url, $args ); return $GLOBALS['edoc_http_response']; }
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $key ) { return $r['headers'][ $key ] ?? ''; }
function sanitize_file_name( $name ) { return str_replace( array( "\r", "\n", '"' ), '', $name ); }
function is_user_logged_in() { return ( $GLOBALS['edoc_user'] ?? 0 ) > 0; }
function get_current_user_id() { return $GLOBALS['edoc_user'] ?? 0; }
function current_user_can( $cap ) { return $GLOBALS['edoc_admin'] ?? false; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'nonce-' . $action; }
function add_action( ...$args ) { $GLOBALS['edoc_hooks'][] = $args; }
function add_filter( ...$args ) { $GLOBALS['edoc_hooks'][] = $args; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['edoc_routes'][ $route ] = $args; }
function wc_get_order( $id ) { return isset( $GLOBALS['edoc_orders'][ $id ] ) ? new WC_Order( $id ) : false; }
function wc_maybe_adjust_line_item_product_stock( $item ) { ++$GLOBALS['edoc_stock_calls']; $item->update_meta_data( '_reduced_stock', $item->get_quantity() ); return false; }
class WP_Error {
	public function __construct( public $code, public $message, public $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_HTTP_Response {
	public array $headers = array();
	public function __construct( public $data, public $status = 200 ) {}
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}
class WP_REST_Response extends WP_HTTP_Response {}
class WP_REST_Server {}
class WC_Countries { public function get_countries() { return array( 'RO' => 'Romania', 'HU' => 'Hungary', 'GB' => 'United Kingdom' ); } }
class WP_REST_Request implements ArrayAccess {
	public array $headers = array();
	public function __construct( public array $body, public int $id = 1, public string $route = '/schrack-sync/v1/erp/orders/1/edit' ) {}
	public function get_json_params() { return $this->body; }
	public function get_body() { return wp_json_encode( $this->body ); }
	public function get_route() { return $this->route; }
	public function get_method() { return 'POST'; }
	public function get_query_params() { return array(); }
	public function get_header( $key ) { return $this->headers[ $key ] ?? null; }
	public function offsetGet( mixed $offset ): mixed { return $this->id; }
	public function offsetExists( mixed $offset ): bool { return 'id' === $offset; }
	public function offsetSet( mixed $offset, mixed $value ): void {}
	public function offsetUnset( mixed $offset ): void {}
}
class EDocDatabase {
	public string $prefix = 'isolated_';
	public array $commands = array(); public array $orders = array(); public array $nonces = array();
	public bool $fail_finish = false;
	public function get_charset_collate() { return ''; }
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $query ) { return '1'; }
	public function get_row( $prepared, $mode ) { [ $sql, $args ] = $prepared; return str_contains( $sql, 'commands' ) ? ( $this->commands[ $args[0] ] ?? null ) : ( $this->orders[ $args[0] ] ?? null ); }
	public function insert( $table, $row ) {
		if ( isset( $this->commands[ $row['command_id'] ] ) ) { return false; }
		$this->commands[ $row['command_id'] ] = $row; return 1;
	}
	public function update( $table, $data, $where ) {
		if ( str_ends_with( $table, 'commands' ) ) {
			if ( $this->fail_finish ) { return false; }
			$this->commands[ $where['command_id'] ] = array_merge( $this->commands[ $where['command_id'] ], $data );
		} else { $this->orders[ $where['order_id'] ] = array_merge( $this->orders[ $where['order_id'] ], $data ); }
		return 1;
	}
	public function query( $prepared ) {
		[ $sql, $args ] = $prepared;
		if ( str_contains( $sql, 'nonces' ) ) { if ( isset( $this->nonces[ $args[0] ] ) ) { return 0; } $this->nonces[ $args[0] ] = true; return 1; }
		[ $id, $version, $hash, $json ] = $args;
		$this->orders[ $id ] = array_merge( $this->orders[ $id ] ?? array( 'delivered_version' => 0 ), array( 'version' => $version, 'snapshot_hash' => $hash, 'snapshot' => $json, 'capture_required' => 0 ) ); return 1;
	}
}
class EDocDate extends DateTimeImmutable {}
class WC_Order_Item_Product {
	public array $data; public array $meta = array( '_edoc_line_identity' => array( 'sku' => 'TEST' ) );
	public function __construct( int $id ) { $this->data = array( 'id' => $id, 'quantity' => 1, 'subtotal' => '10.00', 'total' => '10.00', 'name' => 'Test item', 'product_id' => 1, 'variation_id' => 0, 'subtotal_tax' => '0', 'total_tax' => '0' ); }
	public function __call( $name, $args ) { $key = substr( $name, 4 ); if ( str_starts_with( $name, 'set_' ) ) { $this->data[ $key ] = $args[0]; } else { return $this->data[ $key ] ?? ''; } }
	public function get_meta( $key, ...$args ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() {}
}
class WC_Order {
	public int $id; public array $data; public array $meta; public array $items;
	public bool $date_dirty = false;
	public function __construct( $id ) {
		$this->id = $id;
		$stored = $GLOBALS['edoc_orders'][ $id ];
		$this->data = $stored['data']; $this->meta = $stored['meta']; $this->items = unserialize( serialize( $stored['items'] ) );
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_user_id() { return $this->data['customer_id']; }
	public function get_items( $type = 'line_item' ) { return 'line_item' === $type ? $this->items : array(); }
	public function get_refunds() { return array(); }
	public function get_address( $kind ) { return $this->data[ $kind ]; }
	public function get_data_store() { return new class { public function read( $order ) { $fresh = new WC_Order( $order->id ); $order->data = $fresh->data; $order->meta = $fresh->meta; $order->items = $fresh->items; } }; }
	public function get_meta( $key, ...$args ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function read_meta_data( $force ) { $this->meta = $GLOBALS['edoc_orders'][ $this->id ]['meta']; }
	protected function maybe_set_user_billing_email() { if ( empty( $this->data['billing']['email'] ) ) { $this->data['billing']['email'] = 'customer@example.invalid'; } }
	public function __call( $name, $args ) {
		$key = substr( $name, 4 );
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			if ( str_starts_with( $key, $kind . '_' ) ) { $field = substr( $key, strlen( $kind ) + 1 ); if ( str_starts_with( $name, 'set_' ) ) { $this->data[ $kind ][ $field ] = $args[0]; return; } return $this->data[ $kind ][ $field ] ?? ''; }
		}
		if ( str_starts_with( $name, 'set_' ) ) { $this->data[ $key ] = $args[0]; if ( 'date_modified' === $key ) { $this->date_dirty = true; } return; }
		return $this->data[ $key ] ?? '';
	}
	public function recalculate_coupons() { ++$GLOBALS['edoc_coupon_calls']; foreach ( $this->items as $item ) { $item->set_total( wc_format_decimal( (float) $item->get_subtotal() * 0.9 ) ); } }
	public function calculate_totals( $tax ) { ++$GLOBALS['edoc_total_calls']; $this->data['total'] = array_sum( array_map( fn( $i ) => (float) $i->get_total(), $this->items ) ); $this->save(); }
	public function save() {
		$this->maybe_set_user_billing_email();
		++$GLOBALS['edoc_save_calls'];
		$GLOBALS['edoc_orders'][ $this->id ] = array( 'data' => $this->data, 'meta' => $this->meta, 'items' => $this->items );
		$GLOBALS['edoc_bridge']->capture_event( $this->id );
		return $this->id;
	}
	public function save_meta_data() {
		$GLOBALS['edoc_orders'][ $this->id ]['meta'] = $this->meta;
		// HPOS may auto-save metadata-only updates unless a CRUD property is pending.
		if ( null === $this->data['date_modified'] && ! $this->date_dirty ) { $this->data['date_modified'] = new EDocDate(); $this->save(); }
	}
	public function add_order_note( $note, $customer = false ) { $GLOBALS['edoc_notes'][] = array( $note, $customer ); }
	public function update_status( $status, $note, $manual ) { $this->data['status'] = $status; $this->save(); $this->add_order_note( $note, false ); return true; }
}
foreach ( array( 'orders', 'commands', 'invoices', 'bridge' ) as $class ) { require __DIR__ . '/../../includes/class-schrack-edoc-' . $class . '.php'; }
$GLOBALS['wpdb'] = new EDocDatabase();
$GLOBALS['edoc_bridge'] = $bridge = new Schrack_EDoc_Bridge( new Schrack_Settings() );
$GLOBALS['edoc_orders'][1] = array( 'data' => array( 'customer_id' => 7, 'number' => '1', 'status' => 'pending', 'currency' => 'RON', 'date_created' => new EDocDate( '2026-11-18T10:00:00Z' ), 'date_modified' => new EDocDate( '2026-11-18T10:00:00Z' ), 'billing' => array( 'company' => 'Original', 'email' => 'old@example.invalid' ), 'shipping' => array() ), 'meta' => array( 'billing_vat_number' => 'OLD' ), 'items' => array( 123 => new WC_Order_Item_Product( 123 ) ) );
$GLOBALS['edoc_stock_calls'] = $GLOBALS['edoc_coupon_calls'] = $GLOBALS['edoc_total_calls'] = $GLOBALS['edoc_save_calls'] = 0;
$GLOBALS['edoc_notes'] = array(); $GLOBALS['edoc_http_calls'] = array();
$GLOBALS['options'][Schrack_EDoc_Client::OPTION] = array( 'enabled' => true, 'url' => 'https://erp.example.invalid', 'key_id' => 'fixture-key', 'secret' => str_repeat( 'x', 32 ) );

$original = $bridge->capture( 1 );
$edit = array( 'command_id' => wp_generate_uuid4(), 'expected_version' => $original['version'], 'billing' => array( 'company' => 'New', 'vat_number' => 'RO123', 'email' => '' ), 'shipping' => array( 'email' => 'shipping@example.invalid' ), 'line_items' => array( array( 'id' => 123, 'quantity' => 2, 'unit_price' => '45.5000' ) ) );
$response = $bridge->edit_endpoint( new WP_REST_Request( $edit ) );
check( $response instanceof WP_REST_Response && 200 === $response->status, 'edit success' );
check( 2 === $response->data['version'] && 'New' === $response->data['order']['billing']['company'], 'edit captures a newer version' );
check( 'RO123' === $response->data['order']['billing']['vat_number'] && '' === $response->data['order']['billing']['email'], 'VAT and explicit email clearing persist' );
check( 'shipping@example.invalid' === $response->data['order']['shipping']['email'], 'shipping email persists without a native setter' );
check( 91.0 === (float) $response->data['order']['line_items'][0]['subtotal'] && 81.9 === (float) $response->data['order']['total'], 'net line subtotal and coupon totals recalculated' );
check( 1 === $GLOBALS['edoc_stock_calls'] && 1 === $GLOBALS['edoc_coupon_calls'] && 1 === $GLOBALS['edoc_total_calls'], 'one stock adjustment and coupon/tax calculation' );
check( 1 === count( $GLOBALS['edoc_notes'] ) && false === $GLOBALS['edoc_notes'][0][1] && ! str_contains( $GLOBALS['edoc_notes'][0][0], 'RO123' ), 'private note contains field names only' );
$saves = $GLOBALS['edoc_save_calls'];
$replay = $bridge->edit_endpoint( new WP_REST_Request( array_reverse( $edit, true ) ) );
check( $response->data === $replay->data && $saves === $GLOBALS['edoc_save_calls'] && 1 === count( $GLOBALS['edoc_notes'] ), 'normalized command replay has no repeated effects' );
$changed = $edit; $changed['billing']['company'] = 'Other';
check( 'command_conflict' === $bridge->edit_endpoint( new WP_REST_Request( $changed ) )->data['code'], 'same command ID different body conflicts' );
$changed['command_id'] = wp_generate_uuid4();
check( 'version_conflict' === $bridge->edit_endpoint( new WP_REST_Request( $changed ) )->data['code'], 'stale edit version conflicts' );
$invalid = $edit; $invalid['command_id'] = wp_generate_uuid4(); $invalid['line_items'][0]['id'] = 999;
$before = count( $GLOBALS['wpdb']->commands );
check( 'invalid_command' === $bridge->edit_endpoint( new WP_REST_Request( $invalid ) )->get_error_code() && count( $GLOBALS['wpdb']->commands ) === $before, 'unknown item rejected without journal entry' );
foreach ( array( array( 'quantity', 1.5 ), array( 'quantity', '2' ), array( 'unit_price', 4.5 ), array( 'unit_price', '0' ), array( 'unit_price', '1.12345' ) ) as [ $field, $value ] ) {
	$bad = $edit; $bad['line_items'][0][ $field ] = $value;
	check( is_wp_error( Schrack_EDoc_Commands::edit_body( $bad ) ), 'invalid line value rejected: ' . $field );
}
foreach ( array( array( 'email', 'invalid' ), array( 'country', 'ro' ), array( 'country', 'ZZ' ), array( 'city', str_repeat( 'ő', 256 ) ), array( 'other', 'x' ) ) as [ $field, $value ] ) {
	$bad = $edit; $bad['billing'][ $field ] = $value;
	check( is_wp_error( Schrack_EDoc_Commands::edit_body( $bad ) ), 'invalid address value rejected: ' . $field );
}
$GLOBALS['edoc_orders'][1]['data']['status'] = 'processing';
$version = $bridge->capture( 1 )['version'];
$lines = array( 'command_id' => wp_generate_uuid4(), 'expected_version' => $version, 'line_items' => $edit['line_items'] );
check( 'lines_read_only' === $bridge->edit_endpoint( new WP_REST_Request( $lines ) )->data['code'], 'processing lines are read only' );
$address = array( 'command_id' => wp_generate_uuid4(), 'expected_version' => $version, 'billing' => array( 'city' => 'Cluj', 'vat_number' => '' ) );
check( 200 === $bridge->edit_endpoint( new WP_REST_Request( $address ) )->status, 'processing address edits are allowed' );
check( '' === $bridge->capture( 1 )['order']['billing']['vat_number'], 'cleared VAT does not resurrect fallback metadata' );
$GLOBALS['edoc_orders'][1]['data']['status'] = 'refunded';
$address['command_id'] = wp_generate_uuid4(); $address['expected_version'] = $bridge->capture( 1 )['version'];
check( 'status_read_only' === $bridge->edit_endpoint( new WP_REST_Request( $address ) )->data['code'], 'refunded edit is read only' );

// Simulate interruption after effects, before the durable response, then retry safely.
$GLOBALS['edoc_orders'][1]['data']['status'] = 'pending';
$edit['command_id'] = wp_generate_uuid4(); $edit['expected_version'] = $bridge->capture( 1 )['version'];
$GLOBALS['wpdb']->fail_finish = true;
check( 'command_pending' === $bridge->edit_endpoint( new WP_REST_Request( $edit ) )->get_error_code(), 'unconfirmed response remains applying' );
$GLOBALS['wpdb']->fail_finish = false;
$notes = count( $GLOBALS['edoc_notes'] ); $saves = $GLOBALS['edoc_save_calls'];
check( 200 === $bridge->edit_endpoint( new WP_REST_Request( $edit ) )->status && count( $GLOBALS['edoc_notes'] ) === $notes && $GLOBALS['edoc_save_calls'] === $saves, 'interrupted edit verifies effect without replay' );
$edit['command_id'] = wp_generate_uuid4(); $edit['expected_version'] = $bridge->capture( 1 )['version'];
$GLOBALS['wpdb']->fail_finish = true; $bridge->edit_endpoint( new WP_REST_Request( $edit ) ); $GLOBALS['wpdb']->fail_finish = false;
$GLOBALS['edoc_orders'][1]['data']['billing']['company'] = 'Subsequent native edit';
check( 'version_conflict' === $bridge->edit_endpoint( new WP_REST_Request( $edit ) )->data['code'], 'interrupted edit with differing current fields conflicts' );

$invoice = array( 'command_id' => wp_generate_uuid4(), 'invoice' => array( 'erp_invoice_id' => 42, 'number' => 'FCT0042', 'issue_date' => '2026-11-18', 'currency' => 'RON', 'total' => '1234.50', 'state' => 'issued' ) );
$snapshot = $bridge->capture( 1 );
$GLOBALS['wpdb']->orders[1]['delivered_version'] = $snapshot['version'];
$result = $bridge->invoice_endpoint( new WP_REST_Request( $invoice ) );
check( 200 === $result->status && $snapshot === array_intersect_key( $result->data, $snapshot ), 'invoice association keeps the exact snapshot and version' );
check( $snapshot['version'] === $GLOBALS['wpdb']->orders[1]['delivered_version'] && $snapshot['version'] === $GLOBALS['wpdb']->orders[1]['version'], 'invoice does not enqueue another delivery' );
check( ! array_key_exists( '_edoc_invoice', $result->data['order'] ), 'invoice metadata excluded from order snapshot' );
$notes = count( $GLOBALS['edoc_notes'] );
check( $result->data === $bridge->invoice_endpoint( new WP_REST_Request( $invoice ) )->data && count( $GLOBALS['edoc_notes'] ) === $notes, 'invoice replay has no second note' );
$invoice['command_id'] = wp_generate_uuid4(); $invoice['invoice']['state'] = 'cancelled';
check( $snapshot['version'] === $bridge->invoice_endpoint( new WP_REST_Request( $invoice ) )->data['version'] && 'cancelled' === $GLOBALS['edoc_orders'][1]['meta']['_edoc_invoice']['state'], 'invoice cancellation also preserves version' );
$invoice['command_id'] = wp_generate_uuid4(); $invoice['invoice']['state'] = 'issued'; $GLOBALS['wpdb']->fail_finish = true;
$bridge->invoice_endpoint( new WP_REST_Request( $invoice ) ); $GLOBALS['wpdb']->fail_finish = false; $notes = count( $GLOBALS['edoc_notes'] );
check( 200 === $bridge->invoice_endpoint( new WP_REST_Request( $invoice ) )->status && $notes === count( $GLOBALS['edoc_notes'] ), 'interrupted invoice confirms metadata without another note' );
$GLOBALS['edoc_orders'][1]['data']['date_modified'] = null;
$null_snapshot = $bridge->capture( 1 ); $invoice['command_id'] = wp_generate_uuid4();
check( $null_snapshot === array_intersect_key( $bridge->invoice_endpoint( new WP_REST_Request( $invoice ) )->data, $null_snapshot ), 'invoice on old order without modified date avoids HPOS metadata auto-save' );
$invalid = $invoice; $invalid['invoice']['issue_date'] = '2026-02-30';
check( is_wp_error( Schrack_EDoc_Commands::invoice_body( $invalid ) ), 'impossible invoice date rejected' );

// Persisted v1 status commands survive dbDelta's additive schema migration and retain responses.
$legacy = array( 'command_id' => wp_generate_uuid4(), 'expected_version' => 1, 'status' => 'pending' );
$GLOBALS['wpdb']->commands[ $legacy['command_id'] ] = array( 'command_id' => $legacy['command_id'], 'request_hash' => hash( 'sha256', wp_json_encode( array( 1, 1, 'pending' ) ) ), 'state' => 'done', 'response' => '{"ok":true,"legacy":"unchanged"}', 'http_status' => 200 );
update_option( 'schrack_edoc_schema', '2' ); $bridge->init();
check( '3' === get_option( 'schrack_edoc_schema' ) && 'status' === $GLOBALS['wpdb']->commands[ $legacy['command_id'] ]['kind'], 'schema 2 upgrades to 3 without losing legacy journal rows' );
check( array( 'ok' => true, 'legacy' => 'unchanged' ) === $bridge->status_endpoint( new WP_REST_Request( $legacy ) )->data, 'legacy status hash replays original stored response' );
$status = array( 'command_id' => wp_generate_uuid4(), 'expected_version' => $bridge->capture( 1 )['version'], 'status' => 'on-hold' );
check( 200 === $bridge->status_endpoint( new WP_REST_Request( $status ) )->status, 'status command still executes' );
$health = $bridge->health_endpoint()->data;
check( 1 === $health['protocol_version'] && array( 'order_status', 'order_edit', 'order_invoice' ) === $health['features'] && SCHRACK_WC_SYNC_VERSION === $health['plugin_version'], 'health advertises additive features and release version' );
$bridge->routes();
foreach ( array( 'edit', 'invoice' ) as $kind ) {
	check( array( $bridge, 'authenticate' ) === $GLOBALS['edoc_routes']['/erp/orders/(?P<id>\d+)/' . $kind]['permission_callback'], 'new route uses the existing HMAC authentication: ' . $kind );
	$request = new WP_REST_Request( array(), 1, '/schrack-sync/v1/erp/orders/1/' . $kind );
	check( 'unauthorized' === $bridge->authenticate( $request )->get_error_code(), 'missing HMAC rejected without a fatal error' );
	$request->headers = array( 'X-EDoc-Key' => 'fixture-key', 'X-EDoc-Timestamp' => (string) time(), 'X-EDoc-Nonce' => wp_generate_uuid4() );
	$request->headers['X-EDoc-Signature'] = hash_hmac( 'sha256', Schrack_EDoc_Client::canonical( 'POST', $request->route, array(), 'fixture-key', $request->headers['X-EDoc-Timestamp'], $request->headers['X-EDoc-Nonce'], $request->get_body() ), str_repeat( 'x', 32 ) );
	check( true === $bridge->authenticate( $request ), 'valid HMAC accepted: ' . $kind );
	check( 'replayed_request' === $bridge->authenticate( $request )->get_error_code(), 'HMAC nonce replay rejected: ' . $kind );
	$response = $bridge->private_response_headers( new WP_REST_Response( array() ), new WP_REST_Server(), $request );
	check( 'no-store' === $response->headers['Cache-Control'], 'new route private response cannot be cached' );
	$GLOBALS['options'][Schrack_EDoc_Client::OPTION]['enabled'] = false;
	$request->headers['X-EDoc-Nonce'] = wp_generate_uuid4();
	$request->headers['X-EDoc-Signature'] = hash_hmac( 'sha256', Schrack_EDoc_Client::canonical( 'POST', $request->route, array(), 'fixture-key', $request->headers['X-EDoc-Timestamp'], $request->headers['X-EDoc-Nonce'], $request->get_body() ), str_repeat( 'x', 32 ) );
	check( 'disabled' === $bridge->authenticate( $request )->get_error_code(), 'disabled integration rejects a valid new-route signature' );
	$GLOBALS['options'][Schrack_EDoc_Client::OPTION]['enabled'] = true;
}

// The actual PDF client is used with simulated WordPress HTTP, including HMAC/transport args.
$GLOBALS['edoc_http_response'] = array( 'status' => 200, 'body' => '%PDF-1.4 synthetic', 'headers' => array( 'content-type' => 'application/pdf; charset=binary', 'content-disposition' => 'attachment; filename="../../factura.pdf"' ) );
$nonce = 'nonce-schrack_edoc_invoice_1';
$GLOBALS['edoc_user'] = 8;
check( is_wp_error( Schrack_EDoc_Invoices::pdf( 1, $nonce ) ) && ! $GLOBALS['edoc_http_calls'], 'other customer cannot request PDF' );
$GLOBALS['edoc_user'] = 0;
check( is_wp_error( Schrack_EDoc_Invoices::pdf( 1, $nonce ) ) && ! $GLOBALS['edoc_http_calls'], 'guest cannot request PDF' );
$GLOBALS['edoc_user'] = 7;
check( is_wp_error( Schrack_EDoc_Invoices::pdf( 1, 'bad' ) ) && ! $GLOBALS['edoc_http_calls'], 'bad nonce cannot request PDF' );
$pdf = Schrack_EDoc_Invoices::pdf( 1, $nonce );
check( 'factura.pdf' === $pdf['filename'] && str_starts_with( $pdf['body'], '%PDF-' ), 'owner can download a sanitized PDF' );
[ $url, $args ] = $GLOBALS['edoc_http_calls'][0];
check( str_ends_with( $url, '/api/webshop/v1/invoices/42/pdf' ) && 0 === $args['redirection'] && true === $args['sslverify'] && Schrack_EDoc_Client::MAX_PDF_BYTES + 1 === $args['limit_response_size'], 'PDF request is bounded and cannot forward credentials through redirects' );
$headers = $args['headers'];
check( hash_hmac( 'sha256', Schrack_EDoc_Client::canonical( 'GET', '/api/webshop/v1/invoices/42/pdf', array(), $headers['X-EDoc-Key'], $headers['X-EDoc-Timestamp'], $headers['X-EDoc-Nonce'], '' ), str_repeat( 'x', 32 ) ) === $headers['X-EDoc-Signature'], 'PDF uses exact GET HMAC canonicalization' );
$GLOBALS['edoc_admin'] = true; $GLOBALS['edoc_user'] = 9;
check( is_array( Schrack_EDoc_Invoices::pdf( 1, $nonce ) ), 'order admin can download PDF' );
$GLOBALS['edoc_orders'][1]['data']['customer_id'] = 0; $GLOBALS['edoc_admin'] = false; $GLOBALS['edoc_user'] = 7;
check( is_wp_error( Schrack_EDoc_Invoices::pdf( 1, $nonce ) ), 'guest order cannot be claimed by a logged-in customer' );
$GLOBALS['edoc_orders'][1]['data']['customer_id'] = 7;
$GLOBALS['edoc_orders'][1]['meta']['_edoc_invoice']['state'] = 'cancelled'; $before = count( $GLOBALS['edoc_http_calls'] );
check( is_wp_error( Schrack_EDoc_Invoices::pdf( 1, $nonce ) ) && count( $GLOBALS['edoc_http_calls'] ) === $before, 'cancelled invoice does not call ERP' );
foreach ( array( '/api/webshop/v1/health', '/api/webshop/v1/invoices/0/pdf', '/api/webshop/v1/invoices/42/pdf?secret=1', '/api/webshop/v1/invoices/../pdf' ) as $route ) {
	try { ( new Schrack_EDoc_Client() )->download( $route ); check( false, 'route must fail' ); } catch ( Schrack_EDoc_Exception $e ) { check( true, 'non-PDF or malformed route rejected' ); }
}
$GLOBALS['edoc_http_response']['body'] = str_repeat( 'x', Schrack_EDoc_Client::MAX_PDF_BYTES + 1 );
try { ( new Schrack_EDoc_Client() )->download( '/api/webshop/v1/invoices/42/pdf' ); check( false, 'oversized PDF must fail' ); } catch ( Schrack_EDoc_Exception $e ) { check( str_contains( $e->getMessage(), '5 MiB' ), 'oversized PDF rejected' ); }
foreach ( array( array( 404, 'application/pdf', '%PDF-missing' ), array( 302, 'application/pdf', '%PDF-redirect' ), array( 200, 'text/html', '<html>private error</html>' ) ) as [ $http, $type, $raw ] ) {
	$GLOBALS['edoc_http_response'] = array( 'status' => $http, 'body' => $raw, 'headers' => array( 'content-type' => $type ) );
	try { ( new Schrack_EDoc_Client() )->download( '/api/webshop/v1/invoices/42/pdf' ); check( false, 'invalid HTTP result must fail' ); } catch ( Schrack_EDoc_Exception $e ) { check( ! str_contains( $e->getMessage(), $raw ), 'invalid PDF response is rejected without leaking body' ); }
}
