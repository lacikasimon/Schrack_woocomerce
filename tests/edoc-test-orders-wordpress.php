<?php
/**
 * DISPOSABLE local WordPress/WooCommerce only; no supplier plugin/credentials.
 * home AND siteurl http://127.0.0.1:18521, local environment, WP-Cron disabled.
 * Run once per fresh test database: wp eval-file <plugin>/tests/edoc-test-orders-wordpress.php hpos|legacy
 * Creates only synthetic products, a test plan and test orders. Never run on production.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( 'http://127.0.0.1:18521' !== get_option( 'home' ) || 'http://127.0.0.1:18521' !== get_option( 'siteurl' ) || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! class_exists( 'WooCommerce' ) || is_plugin_active( 'schrack-woocommerce-sync/schrack-woocommerce-sync.php' ) || get_option( 'schrack_wc_sync_settings' ) || get_option( 'schrack_edoc_connection' ) || get_option( 'schrack_edoc_test_orders' ) ) { throw new RuntimeException( 'Fresh, isolated test database required; no supplier settings or test batch may exist.' ); }
$mode = $args[0] ?? '';
if ( ! in_array( $mode, array( 'hpos', 'legacy' ), true ) ) { throw new RuntimeException( 'Choose hpos or legacy.' ); }
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
update_option( 'woocommerce_custom_orders_table_enabled', 'hpos' === $mode ? 'yes' : 'no' );
update_option( 'woocommerce_currency', 'RON' ); update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' ); update_option( 'woocommerce_default_country', 'RO' ); update_option( 'timezone_string', 'Europe/Bucharest' );
define( 'SCHRACK_WC_SYNC_PATH', dirname( __DIR__ ) . '/' );
foreach ( array( 'settings', 'edoc-client', 'edoc-orders', 'edoc-bridge', 'edoc-test-orders' ) as $class ) { require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-' . $class . '.php'; }
$GLOBALS['edoc_test_checks'] = 0;
function expect_test_order( bool $yes, string $label ): void { ++$GLOBALS['edoc_test_checks']; if ( ! $yes ) { throw new RuntimeException( 'FAILED: ' . $label ); } }
$mail_calls = 0; $stock_calls = 0; $http_calls = 0;
add_action( 'phpmailer_init', static function () use ( &$mail_calls ) { ++$mail_calls; } );
add_action( 'woocommerce_product_set_stock', static function () use ( &$stock_calls ) { ++$stock_calls; } );
add_filter( 'pre_http_request', static function () use ( &$http_calls ) { ++$http_calls; return new WP_Error( 'no_network', 'No external HTTP in test.' ); }, 100 );
Schrack_EDoc_Bridge::install();
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'RO', 'tax_rate' => '21.0000', 'tax_rate_name' => 'Synthetic TVA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1 ) );
$pool_ids = array();
foreach ( array( '12.10', '30.25', '60.50' ) as $price ) {
	$p = new WC_Product_Simple(); $p->set_name( 'TEST product ' . $price ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $p->set_manage_stock( true ); $p->set_stock_quantity( 100 ); $pool_ids[] = $p->save();
}
$baseline_stock_events = $stock_calls;
$job = new Schrack_EDoc_Test_Orders(); $job->init();
$state = $job->prepare();
expect_test_order( 0 === $state['cursor'] && count( $state['rows'] ) >= 36 && count( $state['rows'] ) <= 90, 'preparation creates a bounded frozen plan, not orders' );
expect_test_order( $state === $job->prepare(), 'second prepare reuses the exact original plan' );
foreach ( $state['rows'] as $row ) {
	foreach ( $row['lines'] as $line ) { expect_test_order( in_array( $line['unit_price'], array( '10.0000', '25.0000', '50.0000' ), true ), 'gross catalog prices normalized to net exactly once with Woo tax API' ); }
}
$state = $job->step( $state['batch'], 0 ); $first_id = $state['orders'][0]['id'];
$first = wc_get_order( $first_id ); $notes = count( wc_get_order_notes( array( 'order_id' => $first_id, 'limit' => 0 ) ) );
expect_test_order( $state === $job->step( $state['batch'], 0 ), 'duplicate step returns state without another order' );
expect_test_order( $notes === count( wc_get_order_notes( array( 'order_id' => $first_id, 'limit' => 0 ) ) ), 'duplicate step creates no extra note' );

// Simulate losing the state update after Woo has persisted the first order.
$rewound = get_option( Schrack_EDoc_Test_Orders::OPTION ); $rewound['cursor'] = 0; $rewound['orders'] = array(); $rewound['status'] = 'ready'; update_option( Schrack_EDoc_Test_Orders::OPTION, $rewound, false );
$state = $job->step( $state['batch'], 0 );
expect_test_order( $first_id === $state['orders'][0]['id'] && $notes === count( wc_get_order_notes( array( 'order_id' => $first_id, 'limit' => 0 ) ) ), 'persisted batch/index markers recover lost state without duplicates on this storage engine' );
try { $job->step( 'wrong-batch', $state['cursor'] ); expect_test_order( false, 'wrong batch must fail' ); } catch ( Schrack_EDoc_Exception $e ) { expect_test_order( true, 'wrong batch rejected' ); }
try { $job->step( $state['batch'], 999 ); expect_test_order( false, 'future cursor must fail' ); } catch ( Schrack_EDoc_Exception $e ) { expect_test_order( true, 'future cursor rejected' ); }
while ( 'complete' !== $state['status'] ) { $state = $job->step( $state['batch'], $state['cursor'] ); }
expect_test_order( count( $state['rows'] ) === count( array_unique( array_column( $state['orders'], 'id' ) ) ), 'full batch contains exactly one order per plan row' );
expect_test_order( $state === $job->prepare() && $state === $job->step( $state['batch'], $state['cursor'] ), 'completed batch cannot accidentally be generated again' );
$mail_before = $mail_calls;
foreach ( $state['orders'] as $index => $record ) {
	$order = wc_get_order( $record['id'] ); $row = $state['rows'][ $index ];
	expect_test_order( 'pending' === $order->get_status() && ! $order->get_date_paid() && 0 === (int) $order->get_user_id(), 'test order is an unpaid guest order with no payment action' );
	expect_test_order( $order->get_date_created()->getTimestamp() === $row['created'] && str_ends_with( $order->get_billing_email(), '@example.invalid' ) && str_starts_with( $order->get_billing_first_name(), 'TEST ' ), 'backdated synthetic addresses persist' );
	expect_test_order( (float) $order->get_total() > 0 && (float) $order->get_total_tax() > 0 && count( $order->get_items() ) === count( $row['lines'] ), 'real Woo totals, tax and product lines exist' );
}
foreach ( $pool_ids as $id ) { expect_test_order( 100 === (int) wc_get_product( $id )->get_stock_quantity(), 'product stock unchanged' ); }
expect_test_order( $baseline_stock_events === $stock_calls && 0 === $mail_calls && 0 === $http_calls, 'no stock changes, outgoing mail or HTTP during generation' );
WC()->mailer()->emails['WC_Email_Customer_Invoice']->trigger( $first_id );
expect_test_order( $mail_before === $mail_calls, 'marked test orders remain protected against later native Woo mail triggers' );
$view = $job->view();
expect_test_order( $view['total'] === $view['cursor'] && 0 === $view['confirmed'], 'complete view distinguishes creation from ERP acknowledgement' );
global $wpdb;
$table = Schrack_EDoc_Bridge::table( 'orders' );
$wpdb->insert( $table, array( 'order_id' => $first_id, 'version' => 2, 'delivered_version' => 2, 'snapshot_hash' => str_repeat( '0', 64 ), 'snapshot' => '{}', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$view = $job->view();
expect_test_order( 1 === $view['confirmed'] && true === $view['orders'][0]['confirmed'], 'view only counts confirmed current ERP versions' );
echo 'OK: ' . $GLOBALS['edoc_test_checks'] . ' real synthetic-order ' . $mode . ' assertions; created ' . $view['total'] . " synthetic orders\n";
