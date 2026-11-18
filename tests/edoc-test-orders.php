<?php
/** Run: php tests/edoc-test-orders.php — no WordPress, database, HTTP or orders written. */
define( 'ABSPATH', __DIR__ . '/' );
class Schrack_EDoc_Exception extends RuntimeException {}
class WC_Order { public function __construct( public string $batch = '' ) {} public function get_meta( $key ) { return $this->batch; } }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function current_user_can( $cap ) { return $GLOBALS['allowed'] ?? false; }
function check_ajax_referer( ...$args ) { return $GLOBALS['nonce_ok'] ?? false; }
function wp_send_json_error( $data, $code = 400 ) { throw new JsonResult( $code, $data ); }
class JsonResult extends RuntimeException { public function __construct( public int $status, public mixed $data ) {} }
require __DIR__ . '/../includes/class-schrack-edoc-test-orders.php';
$checks = 0;
function check( bool $yes, string $label ) { global $checks; ++$checks; if ( ! $yes ) { throw new RuntimeException( 'FAILED: ' . $label ); } }
$now = new DateTimeImmutable( '2026-11-18T16:10:00+02:00' );
$products = array( array( 'product_id' => 1, 'unit_price' => '10.0000' ), array( 'product_id' => 2, 'unit_price' => '23.4500' ), array( 'product_id' => 3, 'unit_price' => '5.0000' ) );
for ( $i = 0; $i < 20; ++$i ) {
	$plan = Schrack_EDoc_Test_Orders::plan( $now, $products );
	check( array_keys( $plan['months'] ) === array( '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11' ), 'June through the current month only' );
	check( array_sum( $plan['months'] ) === count( $plan['rows'] ) && count( $plan['rows'] ) <= 90, 'monthly totals match bounded plan' );
	$counts = array_count_values( array_column( $plan['rows'], 'month' ) );
	check( $counts === $plan['months'], 'each month contains its advertised random count' );
	foreach ( $counts as $count ) { check( $count >= 6 && $count <= 15, 'monthly count is between 6 and 15' ); }
	$previous = 0;
	foreach ( $plan['rows'] as $index => $row ) {
		check( $row['created'] >= strtotime( '2026-06-01T00:00:00+02:00' ) && $row['created'] <= $now->getTimestamp() && $row['created'] >= $previous, 'random date is chronological and never in the future' );
		check( ( new DateTimeImmutable( '@' . $row['created'] ) )->setTimezone( $now->getTimezone() )->format( 'Y-m' ) === $row['month'], 'date belongs to its stated month' );
		check( $row['index'] === $index + 1 && str_starts_with( $row['address']['first_name'], 'TEST ' ) && str_contains( $row['address']['company'], 'FICTIVĂ' ) && str_ends_with( $row['email'], '@example.invalid' ), 'stable identity and unmistakably synthetic customer data' );
		check( count( $row['lines'] ) >= 1 && count( $row['lines'] ) <= 3 && count( array_unique( array_column( $row['lines'], 'product_id' ) ) ) === count( $row['lines'] ), 'one to three distinct real product references' );
		foreach ( $row['lines'] as $line ) { check( $line['quantity'] >= 1 && $line['quantity'] <= 8 && $line['unit_price'] === $products[ $line['product_id'] - 1 ]['unit_price'], 'quantity varies without altering frozen net unit prices' ); }
		$previous = $row['created'];
	}
}
foreach ( array( array( new DateTimeImmutable( '2026-05-01' ), $products ), array( $now, array() ) ) as [ $date, $pool ] ) {
	try { Schrack_EDoc_Test_Orders::plan( $date, $pool ); check( false, 'unsupported plan must fail' ); } catch ( Schrack_EDoc_Exception $e ) { check( true, 'no eligible months or products fails before writing anything' ); }
}
$job = new Schrack_EDoc_Test_Orders();
check( false === $job->email_enabled( true, new WC_Order( 'test-batch' ) ), 'all native transactional email disabled for marked test orders' );
check( true === $job->email_enabled( true, new WC_Order() ) && false === $job->email_enabled( false, new WC_Order() ), 'unrelated orders retain email behavior' );
check( '' === $job->email_recipient( 'test@example.invalid', new WC_Order( 'test-batch' ) ) && 'owner@example.invalid' === $job->email_recipient( 'owner@example.invalid', new WC_Order() ), 'manual invoice recipients suppressed only on marked test orders' );
foreach ( array( array( false, true ), array( true, false ) ) as [ $allowed, $nonce_ok ] ) {
	$GLOBALS['allowed'] = $allowed; $GLOBALS['nonce_ok'] = $nonce_ok;
	try { $job->ajax(); check( false, 'unauthorized call must fail' ); } catch ( JsonResult $result ) { check( 403 === $result->status, 'capability and nonce are both mandatory' ); }
}
echo 'OK: ' . $checks . " synthetic-order plan/authorization assertions\n";
