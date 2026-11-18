<?php
/**
 * DISPOSABLE WordPress/WooCommerce only; never run on production.
 * home AND siteurl: http://127.0.0.1:18521; environment local; WP-Cron disabled.
 * Keep the complete importer inactive, without supplier/eDoc settings or credentials.
 * Run: wp eval-file <plugin>/tests/media-order-email-wordpress.php hpos|legacy
 * Creates an imageless product and order. Native Woo emails render; only final mail
 * transport is intercepted, so no real messages leave the isolated installation.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( 'http://127.0.0.1:18521' !== get_option( 'home' ) || 'http://127.0.0.1:18521' !== get_option( 'siteurl' ) || 'local' !== wp_get_environment_type() || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! class_exists( 'WooCommerce' ) || is_plugin_active( 'schrack-woocommerce-sync/schrack-woocommerce-sync.php' ) || get_option( 'schrack_wc_sync_settings' ) || get_option( 'schrack_edoc_connection' ) ) { throw new RuntimeException( 'Isolated local media/email test required, without supplier settings.' ); }
$mode = $args[0] ?? '';
if ( ! in_array( $mode, array( 'hpos', 'legacy' ), true ) ) { throw new RuntimeException( 'Choose hpos or legacy.' ); }
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
update_option( 'woocommerce_custom_orders_table_enabled', 'hpos' === $mode ? 'yes' : 'no' );
define( 'SCHRACK_WC_SYNC_PATH', dirname( __DIR__ ) . '/' );
define( 'SCHRACK_WC_SYNC_URL', plugins_url( '/', __DIR__ ) );
define( 'SCHRACK_WC_SYNC_VERSION', 'media-email-regression' );
require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-media-maintenance.php';
$job = new Schrack_Media_Maintenance(); $job->init();
$GLOBALS['media_email_checks'] = 0;
function media_email_expect( bool $yes, string $label ): void { ++$GLOBALS['media_email_checks']; if ( ! $yes ) { throw new RuntimeException( 'FAILED: ' . $label ); } }
media_email_expect( false === wp_get_attachment_image_src( '', 'thumbnail' ), 'real WordPress hook accepts the empty ID' );
media_email_expect( false === wp_get_attachment_image_src( 0, 'thumbnail' ), 'real WordPress hook accepts zero' );
media_email_expect( false === wp_get_attachment_image_url( '' ), 'URL helper preserves missing-image false result' );
add_filter( 'woocommerce_defer_transactional_emails', '__return_false' );
add_filter( 'woocommerce_email_enabled_new_order', '__return_true' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_true' );
add_filter( 'woocommerce_email_recipient_new_order', static fn() => 'admin@example.invalid' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'test_network_disabled', 'No external HTTP in this test.' ), 100 );
$sent = array(); $empty_image_calls = 0;
add_filter( 'pre_wp_mail', static function ( $pre, $args ) use ( &$sent ) {
	$sent[] = $args;
	return true; // Templates and structured data already rendered; only transport is suppressed.
}, 100, 2 );
add_filter( 'wp_get_attachment_image_src', static function ( $image, $id ) use ( &$empty_image_calls ) { if ( '' === $id ) { ++$empty_image_calls; } return $image; }, 1, 2 );
$mailer = WC()->mailer();
$product = new WC_Product_Simple();
$product->set_name( 'TEST media — produs fără imagine' ); $product->set_regular_price( '25' );
$product->set_status( 'publish' ); $product->set_manage_stock( false ); $product->save();
media_email_expect( '' === $product->get_image_id(), 'fixture product has no attachment ID' );
$order = wc_create_order( array( 'status' => 'pending' ) );
$order->set_billing_first_name( 'TEST' ); $order->set_billing_last_name( 'Media Email' );
$order->set_billing_email( 'media-test@example.invalid' ); $order->add_product( $product, 1 );
$order->calculate_totals(); $order->save();
$sent = array(); $empty_image_calls = 0;
media_email_expect( $order->update_status( 'processing' ), 'processing transition returns success with native email hooks enabled' );
media_email_expect( 'processing' === wc_get_order( $order->get_id() )->get_status(), 'new status persists after email rendering' );
$recipients = array();
foreach ( $sent as $mail ) {
	$recipients = array_merge( $recipients, (array) $mail['to'] );
	media_email_expect( str_contains( $mail['message'], 'TEST media' ) && str_contains( $mail['message'], 'produs fără imagine' ), 'native email content contains the imageless product' );
}
media_email_expect( in_array( 'admin@example.invalid', $recipients, true ), 'new_order email renders and reaches intercepted transport' );
media_email_expect( in_array( 'media-test@example.invalid', $recipients, true ), 'customer_processing_order email renders and reaches intercepted transport' );
media_email_expect( count( $sent ) >= 2 && $empty_image_calls >= 2, 'both real email templates exercise the empty image-ID hook' );
echo 'OK: ' . $GLOBALS['media_email_checks'] . ' real media/email ' . $mode . ' assertions; rendered ' . count( $sent ) . ' emails, ' . $empty_image_calls . " empty-image hook calls; no mail delivered\n";
