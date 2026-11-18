<?php
/**
 * DISPOSABLE WordPress/WooCommerce only. No supplier plugin activation or credentials.
 * home AND siteurl: http://127.0.0.1:18521; WP_ENVIRONMENT_TYPE=local; WP-Cron disabled.
 * Run in a fresh database, once per mode:
 * wp eval-file wp-content/plugins/schrack-woocommerce-sync/tests/edoc-v11-wordpress.php hpos
 * wp eval-file wp-content/plugins/schrack-woocommerce-sync/tests/edoc-v11-wordpress.php legacy
 * Creates synthetic products, coupons, orders and bridge rows. Never run on production.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( 'http://127.0.0.1:18521' !== get_option( 'home' ) || 'http://127.0.0.1:18521' !== get_option( 'siteurl' ) || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! class_exists( 'WooCommerce' ) || is_plugin_active( 'schrack-woocommerce-sync/schrack-woocommerce-sync.php' ) ) { throw new RuntimeException( 'Disposable local eDoc test installation required, supplier plugin inactive.' ); }
if ( get_option( 'schrack_wc_sync_settings' ) ) { throw new RuntimeException( 'No supplier configuration allowed in the test database.' ); }
$mode = $args[0] ?? '';
if ( ! in_array( $mode, array( 'hpos', 'legacy' ), true ) ) { throw new RuntimeException( 'Choose hpos or legacy.' ); }
$old_connection = (array) get_option( 'schrack_edoc_connection', array() );
if ( $old_connection && 'synthetic-test-key' !== ( $old_connection['key_id'] ?? '' ) ) { throw new RuntimeException( 'No real eDoc credentials allowed.' ); }
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
update_option( 'woocommerce_custom_orders_table_enabled', 'hpos' === $mode ? 'yes' : 'no' );
update_option( 'woocommerce_currency', 'RON' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_default_country', 'RO' );
define( 'SCHRACK_WC_SYNC_VERSION', 'test-v1.1' );
define( 'SCHRACK_WC_SYNC_PATH', dirname( __DIR__ ) . '/' );
define( 'SCHRACK_WC_SYNC_URL', plugins_url( '/', __DIR__ ) );
foreach ( array( 'settings', 'edoc-client', 'edoc-orders', 'edoc-commands', 'edoc-invoices', 'edoc-importer', 'edoc-bridge' ) as $class ) { require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-' . $class . '.php'; }
$GLOBALS['edoc_v11_checks'] = 0;
function edoc_expect( bool $yes, string $label ): void { ++$GLOBALS['edoc_v11_checks']; if ( ! $yes ) { throw new RuntimeException( 'FAILED: ' . $label ); } }
function edoc_request( array $body, int $id ): WP_REST_Request { $r = new WP_REST_Request( 'POST' ); $r->set_url_params( array( 'id' => $id ) ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); return $r; }
function edoc_notes( int $id ): int { return count( wc_get_order_notes( array( 'order_id' => $id, 'limit' => 0 ) ) ); }
$mail_calls = 0;
add_filter( 'pre_wp_mail', static function () use ( &$mail_calls ) { ++$mail_calls; return false; } );
$http_calls = 0;
add_filter( 'pre_http_request', static function ( $pre, $request, $url ) use ( &$http_calls ) {
	++$http_calls;
	if ( 'https://erp.example.invalid/api/webshop/v1/invoices/42/pdf' === $url ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/pdf', 'content-disposition' => 'attachment; filename="test.pdf"' ), 'body' => "%PDF-1.4\nsynthetic", 'cookies' => array() ); }
	return new WP_Error( 'test_network_blocked', 'External HTTP is disabled in this test.' );
}, 100, 3 );
update_option( Schrack_EDoc_Client::OPTION, array( 'enabled' => true, 'url' => 'https://erp.example.invalid', 'key_id' => 'synthetic-test-key', 'secret' => 'synthetic-local-test-secret-00000000' ), false );

global $wpdb;
Schrack_EDoc_Bridge::install();
$commands = Schrack_EDoc_Bridge::table( 'commands' );
$legacy_id = wp_generate_uuid4();
$wpdb->insert( $commands, array( 'command_id' => $legacy_id, 'order_id' => 999999, 'kind' => 'status', 'request_hash' => hash( 'sha256', wp_json_encode( array( 999999, 1, 'pending' ) ) ), 'state' => 'done', 'status' => 'pending', 'base_version' => 1, 'response' => '{"ok":true,"legacy":"kept"}', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->query( "ALTER TABLE $commands DROP COLUMN kind" );
update_option( 'schrack_edoc_schema', '2' );
$bridge = new Schrack_EDoc_Bridge( new Schrack_Settings() );
$bridge->init();
edoc_expect( '3' === get_option( 'schrack_edoc_schema' ) && 'status' === $wpdb->get_var( $wpdb->prepare( "SELECT kind FROM $commands WHERE command_id=%s", $legacy_id ) ), 'real dbDelta upgrades existing journal rows' );
$legacy_response = $bridge->status_endpoint( edoc_request( array( 'command_id' => $legacy_id, 'expected_version' => 1, 'status' => 'pending' ), 999999 ) );
edoc_expect( array( 'ok' => true, 'legacy' => 'kept' ) === $legacy_response->get_data(), 'schema-2 status response replays without loading deleted order' );

$user_id = email_exists( 'owner@example.invalid' ) ?: wp_create_user( 'edoc-owner', wp_generate_password(), 'owner@example.invalid' );
$other_user = email_exists( 'other@example.invalid' ) ?: wp_create_user( 'edoc-other', wp_generate_password(), 'other@example.invalid' );
if ( ! get_option( 'edoc_v11_test_tax_rate' ) ) {
	update_option( 'edoc_v11_test_tax_rate', WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'RO', 'tax_rate' => '21.0000', 'tax_rate_name' => 'Synthetic TVA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1 ) ) );
}
$product = new WC_Product_Simple();
$product->set_name( 'Synthetic eDoc v1.1 item' ); $product->set_regular_price( '10' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 100 ); $product->save();
$coupon = new WC_Coupon(); $coupon->set_code( 'edoc-test-' . wp_generate_uuid4() ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( 10 ); $coupon->save();
$order = wc_create_order( array( 'customer_id' => $user_id, 'status' => 'pending' ) );
$order->set_billing_email( 'owner@example.invalid' ); $order->set_billing_country( 'RO' );
$item_id = $order->add_product( $product, 1 ); $order->apply_coupon( $coupon->get_code() );
$order->set_date_modified( time() - 7200 ); $order->save();
$id = $order->get_id();
$start = $bridge->capture( $id );
$notes = edoc_notes( $id ); $mail_before = $mail_calls;
$edit = array( 'command_id' => wp_generate_uuid4(), 'expected_version' => $start['version'], 'billing' => array( 'company' => 'Synthetic company', 'email' => '', 'vat_number' => 'RO123' ), 'shipping' => array( 'email' => 'shipping@example.invalid', 'city' => 'Cluj' ), 'line_items' => array( array( 'id' => $item_id, 'quantity' => 3, 'unit_price' => '45.5000' ) ) );
$response = $bridge->edit_endpoint( edoc_request( $edit, $id ) );
edoc_expect( $response instanceof WP_REST_Response && 200 === $response->get_status(), 'real edit endpoint succeeds' );
$result = $response->get_data();
edoc_expect( $result['version'] > $start['version'], 'edit advances snapshot version' );
edoc_expect( '' === $result['order']['billing']['email'] && 'RO123' === $result['order']['billing']['vat_number'] && 'shipping@example.invalid' === $result['order']['shipping']['email'], 'address clearing, VAT and shipping email persist through real CRUD' );
edoc_expect( 136.5 === (float) $result['order']['line_items'][0]['subtotal'] && abs( (float) $result['order']['line_items'][0]['total'] - 122.85 ) < 0.001, 'coupon recalculation uses new net subtotal' );
edoc_expect( abs( (float) $result['order']['total_tax'] - 25.8 ) < 0.001 && abs( (float) $result['order']['total'] - 148.65 ) < 0.001, 'WooCommerce recalculates 21 percent tax on discounted net lines' );
edoc_expect( 97 === (int) wc_get_product( $product->get_id() )->get_stock_quantity(), 'admin stock adjustment uses changed quantity' );
edoc_expect( edoc_notes( $id ) === $notes + 1 && $mail_before === $mail_calls, 'edit adds exactly one private note and no mail' );
$repeat = $bridge->edit_endpoint( edoc_request( $edit, $id ) );
edoc_expect( $repeat->get_data() === $result && edoc_notes( $id ) === $notes + 1 && 97 === (int) wc_get_product( $product->get_id() )->get_stock_quantity(), 'real journal replay returns original response without extra note or stock change' );
$edit['billing']['city'] = 'Changed';
edoc_expect( 'command_conflict' === $bridge->edit_endpoint( edoc_request( $edit, $id ) )->get_data()['code'], 'changed command ID payload conflicts' );
$edit['command_id'] = wp_generate_uuid4();
edoc_expect( 'version_conflict' === $bridge->edit_endpoint( edoc_request( $edit, $id ) )->get_data()['code'], 'old version conflicts' );
$edit['command_id'] = wp_generate_uuid4(); $edit['line_items'][0]['id'] = 999999;
$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $commands" );
edoc_expect( 'invalid_command' === $bridge->edit_endpoint( edoc_request( $edit, $id ) )->get_error_code() && $rows === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $commands" ), 'unknown line fails before journal insertion' );

// Use an older modified timestamp to prove invoice save cannot silently advance it.
$order = wc_get_order( $id ); $order->set_date_modified( time() - 3600 ); $order->save();
$snapshot = $bridge->capture( $id );
$orders_table = Schrack_EDoc_Bridge::table( 'orders' );
$wpdb->update( $orders_table, array( 'delivered_version' => $snapshot['version'] ), array( 'order_id' => $id ) );
$invoice = array( 'command_id' => wp_generate_uuid4(), 'invoice' => array( 'erp_invoice_id' => 42, 'number' => 'FCT0042', 'issue_date' => '2026-11-18', 'currency' => 'RON', 'total' => '1234.50', 'state' => 'issued' ) );
$notes = edoc_notes( $id );
$response = $bridge->invoice_endpoint( edoc_request( $invoice, $id ) );
edoc_expect( $response instanceof WP_REST_Response && 200 === $response->get_status(), 'invoice command succeeds' );
$result = $response->get_data();
edoc_expect( $snapshot === array_intersect_key( $result, $snapshot ), 'invoice preserves exact snapshot, including modified timestamp and cleared email' );
edoc_expect( edoc_notes( $id ) === $notes + 1, 'invoice private note added once' );
edoc_expect( $bridge->invoice_endpoint( edoc_request( $invoice, $id ) )->get_data() === $result && edoc_notes( $id ) === $notes + 1, 'invoice journal replay adds no note' );
$invoice['command_id'] = wp_generate_uuid4(); $invoice['invoice']['state'] = 'cancelled';
edoc_expect( $snapshot['version'] === $bridge->invoice_endpoint( edoc_request( $invoice, $id ) )->get_data()['version'], 'cancellation also preserves version' );
$pending = $wpdb->get_row( $wpdb->prepare( "SELECT version,delivered_version FROM $orders_table WHERE order_id=%d", $id ), ARRAY_A );
edoc_expect( $pending['version'] === $pending['delivered_version'], 'invoice commands leave no pending order delivery' );
wp_set_current_user( $user_id );
edoc_expect( is_wp_error( Schrack_EDoc_Invoices::pdf( $id, wp_create_nonce( 'schrack_edoc_invoice_' . $id ) ) ), 'cancelled PDF denied to owner' );
$invoice['command_id'] = wp_generate_uuid4(); $invoice['invoice']['state'] = 'issued'; $bridge->invoice_endpoint( edoc_request( $invoice, $id ) );
$pdf = Schrack_EDoc_Invoices::pdf( $id, wp_create_nonce( 'schrack_edoc_invoice_' . $id ) );
edoc_expect( is_array( $pdf ) && 'test.pdf' === $pdf['filename'], 'owner can download authenticated PDF' );
$before = $http_calls; wp_set_current_user( $other_user );
edoc_expect( is_wp_error( Schrack_EDoc_Invoices::pdf( $id, wp_create_nonce( 'schrack_edoc_invoice_' . $id ) ) ) && $http_calls === $before, 'other customer denied before HTTP' );
wp_set_current_user( 0 );
edoc_expect( is_wp_error( Schrack_EDoc_Invoices::pdf( $id, wp_create_nonce( 'schrack_edoc_invoice_' . $id ) ) ) && $http_calls === $before, 'guest denied before HTTP' );
wp_set_current_user( 1 );
edoc_expect( is_array( Schrack_EDoc_Invoices::pdf( $id, wp_create_nonce( 'schrack_edoc_invoice_' . $id ) ) ), 'shop admin can download PDF' );

$order = wc_get_order( $id ); $order->set_status( 'processing' ); $order->save();
$current = $bridge->capture( $id );
$processing = array( 'command_id' => wp_generate_uuid4(), 'expected_version' => $current['version'], 'line_items' => array( array( 'id' => $item_id, 'quantity' => 4, 'unit_price' => '10' ) ) );
edoc_expect( 'lines_read_only' === $bridge->edit_endpoint( edoc_request( $processing, $id ) )->get_data()['code'], 'processing lines cannot change' );
unset( $processing['line_items'] ); $processing['command_id'] = wp_generate_uuid4(); $processing['billing'] = array( 'city' => 'Oradea' );
edoc_expect( 200 === $bridge->edit_endpoint( edoc_request( $processing, $id ) )->get_status(), 'processing address can change' );

// Both order admin screens register the same metabox, with escaped customer presentation.
$ui = new Schrack_EDoc_Invoices(); $ui->metaboxes();
edoc_expect( isset( $GLOBALS['wp_meta_boxes']['shop_order']['side']['default']['schrack-edoc-invoice'] ) && isset( $GLOBALS['wp_meta_boxes'][wc_get_page_screen_id( 'shop-order' )]['side']['default']['schrack-edoc-invoice'] ), 'legacy and HPOS metaboxes registered' );
$content = new ReflectionMethod( $ui, 'content' );
$order = wc_get_order( $id ); $meta = $order->get_meta( '_edoc_invoice' ); $meta['number'] = '<script>test</script>'; $order->update_meta_data( '_edoc_invoice', $meta );
$html = $content->invoke( null, $order, true );
edoc_expect( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ) && str_contains( $html, 'Descarcă PDF' ), 'invoice metadata escaped in UI' );
$meta['state'] = 'cancelled'; $order->update_meta_data( '_edoc_invoice', $meta );
edoc_expect( '' === $content->invoke( null, $order, false ), 'cancelled invoice hidden from customer panel' );
edoc_expect( ! str_contains( $content->invoke( null, $order, true ), 'Descarcă PDF' ), 'cancelled admin invoice has no download link' );
$connection = Schrack_EDoc_Client::config(); $connection['enabled'] = false;
update_option( Schrack_EDoc_Client::OPTION, $connection, false );
echo 'OK: ' . $GLOBALS['edoc_v11_checks'] . ' real WooCommerce ' . $mode . " assertions\n";
