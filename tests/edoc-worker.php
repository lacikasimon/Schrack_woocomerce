<?php
/**
 * Run: php tests/edoc-worker.php
 * Exercises the production worker with a virtual clock and simulated database/HTTP.
 * Only its namespace is changed so timing tests do not sleep or touch a live store.
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	function wc_get_orders() { return array(); } // Production's dependency availability check.
}
namespace EDocWorkerRegression {
	use Throwable;

	final class Fixture {
		public static float $clock = 10000;
		public static float $order_seconds = 0;
		public static float $catalog_seconds = 0;
		public static bool $catalog_fails = false;
		public static array $options = array();
		public static array $events = array();
		public static array $statuses = array();

		public static function reset( string $priority ): void {
			self::$clock = 10000;
			self::$order_seconds = 0;
			self::$catalog_seconds = 0;
			self::$catalog_fails = false;
			self::$events = array();
			self::$statuses = array();
			self::$options = array( 'schrack_edoc_priority' => $priority, 'schrack_edoc_catalog_next' => 0, 'schrack_edoc_scan' => array( 'mode' => 'idle', 'until' => 20000 ) );
		}
	}
	function microtime( bool $as_float = false ) { return Fixture::$clock; }
	function time(): int { return (int) Fixture::$clock; }
	function get_option( $key, $fallback = false ) { return Fixture::$options[ $key ] ?? $fallback; }
	function update_option( $key, $value, $autoload = null ): void { Fixture::$options[ $key ] = $value; }
	function delete_option( $key ): void { unset( Fixture::$options[ $key ] ); }

	final class Database {
		public string $prefix = 'test_';
		public function prepare( $query, ...$args ) { return $query; }
		public function get_var( $query ) { return '1'; } // MySQL advisory locks.
		public function query( $query ) { return 1; }
		public function update( ...$args ) { return 1; }
		public function get_col( $query ): array { return str_contains( $query, 'capture_required=0' ) ? range( 1, 10 ) : array(); }
		public function get_row( $query, $mode ): array { return array( 'capture_required' => 0, 'version' => 1, 'delivered_version' => 0, 'attempts' => 0, 'snapshot' => '{}' ); }
	}
	class Schrack_Settings {
		public function update_status( $key, $value ): void { Fixture::$statuses[ $key ] = $value; }
	}
	class Schrack_EDoc_Client {
		public function __construct( $config = null ) {}
		public static function enabled(): bool { return true; }
		public static function config( $fresh = false ): array { return array(); }
		public static function identity( $config ): string { return 'same-connection'; }
		public static function safe_error( Throwable $error ): string { return 'Controlled fixture error'; }
		public function request( $method, $route, $query, $payload ): array {
			Fixture::$events[] = array( 'order', Fixture::$clock, get_option( 'schrack_edoc_priority' ) );
			Fixture::$clock += Fixture::$order_seconds;
			return array( 'ok' => true, 'result' => 'updated', 'version' => 1 );
		}
	}
	class Schrack_EDoc_Importer {
		public function __construct( $settings ) {}
		public function run_batch(): array {
			Fixture::$events[] = array( 'catalog', Fixture::$clock, get_option( 'schrack_edoc_priority' ) );
			Fixture::$clock += Fixture::$catalog_seconds;
			if ( Fixture::$catalog_fails ) { throw new \RuntimeException( 'Simulated catalog failure' ); }
			return array( 'has_more' => true );
		}
	}

	$source = file_get_contents( __DIR__ . '/../includes/class-schrack-edoc-bridge.php' );
	// Execute the actual worker, not a rewritten scheduler or a mirror of its implementation.
	eval( 'namespace EDocWorkerRegression; use \\Throwable; ' . substr( $source, strlen( '<?php' ) ) );
	$GLOBALS['wpdb'] = new Database();
	$checks = 0;
	function check( bool $condition, string $message ): void {
		global $checks; ++$checks;
		if ( ! $condition ) { throw new \RuntimeException( 'FAILED: ' . $message ); }
	}
	function cycle(): array {
		$before = count( Fixture::$events );
		( new Schrack_EDoc_Bridge( new Schrack_Settings() ) )->work();
		check( ! get_option( 'schrack_edoc_worker_error' ), 'worker finishes without hidden error' );
		return array_slice( Fixture::$events, $before );
	}

	// Two slow POSTs consume the run budget; the still-due catalog wins the next run.
	Fixture::reset( 'orders' );
	Fixture::$order_seconds = 10.5;
	$first = cycle();
	check( array_column( $first, 0 ) === array( 'order', 'order' ), 'slow orders stop at the existing shared budget' );
	check( 0 === get_option( 'schrack_edoc_catalog_next' ), 'skipped catalog remains due' );
	Fixture::$clock += 60;
	$second = cycle();
	check( 'catalog' === $second[0][0], 'catalog advances on the next invocation despite sustained slow orders' );
	check( 'catalog' === $first[0][2], 'next catalog turn is durable before the first slow POST' );
	check( 'orders' === $second[0][2], 'next orders turn is durable before catalog work' );
	check( count( array_filter( $second, static fn( $event ) => 'order' === $event[0] ) ) === 2, 'remaining time still serves orders after catalog' );

	// A slow catalog page must in turn yield to orders even while more pages are due.
	Fixture::reset( 'catalog' );
	Fixture::$catalog_seconds = 21;
	Fixture::$order_seconds = 10.5;
	$first = cycle();
	check( array_column( $first, 0 ) === array( 'catalog' ), 'an expensive catalog page does not start over-budget order work' );
	check( 'orders' === $first[0][2], 'orders priority is saved before a potentially interrupted catalog page' );
	Fixture::$clock += 60;
	$second = cycle();
	check( array_column( $second, 0 ) === array( 'order', 'order' ), 'orders advance in the next invocation despite expensive due catalog pages' );
	check( 'catalog' === $second[0][2], 'catalog regains the following turn' );

	// No due catalog should reduce the normal ten-order batch or alter its next priority.
	Fixture::reset( 'catalog' );
	Fixture::$options['schrack_edoc_catalog_next'] = 20000;
	$events = cycle();
	check( count( $events ) === 10 && array_unique( array_column( $events, 0 ) ) === array( 'order' ), 'not-due catalog leaves normal order capacity' );
	check( 'catalog' === get_option( 'schrack_edoc_priority' ), 'priority does not alternate unnecessarily' );

	// Catalog errors retain the existing five-minute backoff and leave time to orders.
	Fixture::reset( 'catalog' );
	Fixture::$catalog_fails = true;
	$events = cycle();
	check( 'catalog' === $events[0][0] && count( $events ) === 11, 'recoverable catalog failure still allows order delivery' );
	check( 10300 === get_option( 'schrack_edoc_catalog_next' ), 'catalog retry backoff is unchanged' );
	check( 1 === Fixture::$statuses['edoc_catalog']['errors'], 'catalog failure remains visible' );
	echo 'OK: ' . $checks . " worker scheduling assertions\n";
}
