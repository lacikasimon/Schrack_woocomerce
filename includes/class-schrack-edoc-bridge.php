<?php
/** Durable eDoc order mirror, command journal and scheduler. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_EDoc_Bridge {
	public const HOOK = 'schrack_edoc_worker';
	public const GROUP = 'schrack-edoc';
	private static array $held_locks = array();
	private static array $save_locks = array();
	private static array $rpc_orders = array();
	private Schrack_Settings $settings;
	public function __construct( Schrack_Settings $settings ) { $this->settings = $settings; }
	public static function table( string $suffix ): string { global $wpdb; return $wpdb->prefix . 'schrack_edoc_' . $suffix; }

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$orders = self::table( 'orders' ); $commands = self::table( 'commands' ); $nonces = self::table( 'nonces' );
		dbDelta( "CREATE TABLE $orders (
			order_id bigint unsigned NOT NULL,
			version bigint unsigned NOT NULL DEFAULT 0,
			snapshot_hash char(64) NOT NULL DEFAULT '',
			snapshot longtext NOT NULL,
			delivered_version bigint unsigned NOT NULL DEFAULT 0,
			capture_required tinyint unsigned NOT NULL DEFAULT 0,
			attempts int unsigned NOT NULL DEFAULT 0,
			next_attempt bigint unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (order_id),
			KEY delivery (next_attempt,attempts)
		) $collate;" );
		dbDelta( "CREATE TABLE $commands (
			command_id char(36) NOT NULL,
			order_id bigint unsigned NOT NULL,
			request_hash char(64) NOT NULL,
			state varchar(20) NOT NULL,
			status varchar(32) NOT NULL,
			base_version bigint unsigned NOT NULL,
			http_status int unsigned NOT NULL DEFAULT 200,
			response longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (command_id),
			KEY order_commands (order_id)
		) $collate;" );
		dbDelta( "CREATE TABLE $nonces (
			nonce_hash char(64) NOT NULL,
			expires_at bigint unsigned NOT NULL,
			PRIMARY KEY  (nonce_hash),
			KEY expiry (expires_at)
		) $collate;" );
		update_option( 'schrack_edoc_schema', '2', false );
	}

	public function init(): void {
		if ( '2' !== get_option( 'schrack_edoc_schema' ) ) { self::install(); }
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'init', array( $this, 'schedule' ), 20 );
		add_action( self::HOOK, array( $this, 'work' ) );
		add_filter( 'cron_schedules', static function ( $s ) { $s['schrack_edoc_minute'] = array( 'interval' => 60, 'display' => 'eDoc: fiecare minut' ); return $s; } );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'before_order_save' ) );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'saved_order' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'capture_event' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'capture_event' ) );
		add_action( 'woocommerce_before_order_item_object_save', static function ( $item ) { if ( Schrack_EDoc_Client::enabled() ) { Schrack_EDoc_Orders::remember_line( $item ); } } );
		add_action( 'woocommerce_before_product_object_save', array( 'Schrack_EDoc_Importer', 'guard_publication' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_schrack_edoc_settings', array( $this, 'admin_save' ) );
		add_action( 'admin_post_schrack_edoc_action', array( $this, 'admin_action' ) );
	}

	/** MySQL connection locks serialize workers/RPC; process death releases the lock. */
	public function locked( string $scope, callable $callback ) {
		global $wpdb;
		$name = 'edoc_' . substr( hash( 'sha256', $wpdb->prefix . $scope ), 0, 50 );
		if ( isset( self::$held_locks[ $name ] ) ) { return $callback(); }
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $name ) ) ) { throw new Schrack_EDoc_Exception( 'Sincronizarea este ocupată. Reîncercați.' ); }
		self::$held_locks[ $name ] = true;
		try { return $callback(); } finally { unset( self::$held_locks[ $name ] ); $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}

	public function routes(): void {
		foreach ( array( '/health' => 'GET', '/orders/(?P<id>\d+)' => 'GET', '/orders/(?P<id>\d+)/status' => 'POST' ) as $route => $method ) {
			register_rest_route( 'schrack-sync/v1', '/erp' . $route, array( 'methods' => $method, 'permission_callback' => array( $this, 'authenticate' ), 'callback' => 'POST' === $method ? array( $this, 'status_endpoint' ) : ( '/health' === $route ? array( $this, 'health_endpoint' ) : array( $this, 'order_endpoint' ) ) ) );
		}
	}

	public function authenticate( WP_REST_Request $request ) {
		global $wpdb;
		$c = Schrack_EDoc_Client::config();
		if ( ! is_ssl() && ! Schrack_EDoc_Client::local_allowed() ) { return new WP_Error( 'https_required', 'HTTPS obligatoriu.', array( 'status' => 403 ) ); }
		$query = $request->get_query_params(); unset( $query['rest_route'] );
		if ( $query || strlen( $request->get_body() ) > Schrack_EDoc_Client::MAX_BYTES ) { return new WP_Error( 'invalid_request', 'Cerere invalidă.', array( 'status' => 400 ) ); }
		$key = $request->get_header( 'X-EDoc-Key' ); $ts = $request->get_header( 'X-EDoc-Timestamp' ); $nonce = $request->get_header( 'X-EDoc-Nonce' ); $sig = $request->get_header( 'X-EDoc-Signature' );
		if ( '' === $c['secret'] || '' === $c['key_id'] || ! hash_equals( (string) $c['key_id'], $key ) || ! preg_match( '/^\d{10}$/D', $ts ) || abs( time() - (int) $ts ) > 300 || ! self::uuid( $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sig ) ) { return new WP_Error( 'unauthorized', 'Autentificare eDoc invalidă.', array( 'status' => 401 ) ); }
		$expected = hash_hmac( 'sha256', Schrack_EDoc_Client::canonical( $request->get_method(), $request->get_route(), $query, $key, $ts, $nonce, $request->get_body() ), $c['secret'] );
		if ( ! hash_equals( $expected, $sig ) ) { return new WP_Error( 'unauthorized', 'Autentificare eDoc invalidă.', array( 'status' => 401 ) ); }
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table( 'nonces' ) . ' (nonce_hash,expires_at) VALUES (%s,%d)', hash( 'sha256', $key . ':' . $nonce ), time() + 600 ) );
		if ( 1 !== $inserted ) { return new WP_Error( 'replayed_request', 'Cerere deja utilizată.', array( 'status' => 409 ) ); }
		if ( '/schrack-sync/v1/erp/health' !== $request->get_route() && ! Schrack_EDoc_Client::enabled() ) { return new WP_Error( 'disabled', 'Integrarea eDoc este dezactivată.', array( 'status' => 403 ) ); }
		return true;
	}

	private static function uuid( mixed $id ): bool { return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $id ); }
	public function health_endpoint(): WP_REST_Response { return new WP_REST_Response( array( 'ok' => true, 'protocol_version' => 1, 'currency' => get_woocommerce_currency(), 'enabled' => Schrack_EDoc_Client::enabled() ) ); }

	public function order_endpoint( WP_REST_Request $request ) {
		try { return new WP_REST_Response( array_merge( array( 'ok' => true ), $this->capture( (int) $request['id'] ) ) ); }
		catch ( OutOfBoundsException $e ) { return new WP_Error( 'not_found', 'Comanda nu există.', array( 'status' => 404 ) ); }
		catch ( Throwable $e ) { return new WP_Error( 'order_busy', 'Comanda nu poate fi citită acum.', array( 'status' => 503 ) ); }
	}

	/** A write-ahead journal makes timeouts/retries safe without repeating Woo notifications. */
	public function status_endpoint( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), array( 'command_id', 'expected_version', 'status' ) ) || ! self::uuid( $body['command_id'] ?? null ) || ! is_int( $body['expected_version'] ?? null ) || $body['expected_version'] < 1 || ! in_array( $body['status'] ?? '', array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed' ), true ) ) { return new WP_Error( 'invalid_command', 'Comandă de status invalidă.', array( 'status' => 400 ) ); }
		$id = (int) $request['id'];
		try { return $this->locked( 'order_' . $id, function () use ( $body, $id ) {
			global $wpdb;
			$table = self::table( 'commands' );
			$hash = hash( 'sha256', wp_json_encode( array( $id, $body['expected_version'], $body['status'] ) ) );
			$journal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE command_id=%s", $body['command_id'] ), ARRAY_A );
			if ( $journal && ! hash_equals( $journal['request_hash'], $hash ) ) { return new WP_REST_Response( array( 'error' => 'Identificatorul comenzii a fost reutilizat.', 'code' => 'command_conflict' ), 409 ); }
			if ( $journal && 'done' === $journal['state'] ) { return new WP_REST_Response( json_decode( $journal['response'], true ), (int) $journal['http_status'] ); }
			$current = $this->capture( $id );
			if ( $journal ) {
				// A process stopped after journaling. Never execute its side effects a second time.
				if ( $current['order']['status'] === $body['status'] ) { return $this->finish_command( $body['command_id'], array_merge( array( 'ok' => true, 'command_id' => $body['command_id'] ), $current ), 200 ); }
				return $this->finish_command( $body['command_id'], array_merge( array( 'error' => 'Rezultatul comenzii întrerupte necesită verificare.', 'code' => 'version_conflict' ), $current ), 409 );
			}
			$inserted = $wpdb->insert( $table, array( 'command_id' => $body['command_id'], 'order_id' => $id, 'request_hash' => $hash, 'state' => 'applying', 'status' => $body['status'], 'base_version' => $body['expected_version'], 'response' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			if ( false === $inserted ) { return new WP_REST_Response( array( 'error' => 'Comanda este deja în lucru.', 'code' => 'command_conflict' ), 409 ); }
			if ( ! in_array( $current['order']['status'], array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed' ), true ) ) { return $this->finish_command( $body['command_id'], array_merge( array( 'error' => 'Acest status se gestionează numai în WooCommerce.', 'code' => 'status_read_only' ), $current ), 409 ); }
			if ( $current['version'] !== $body['expected_version'] ) { return $this->finish_command( $body['command_id'], array_merge( array( 'error' => 'Comanda s-a modificat în magazin.', 'code' => 'version_conflict' ), $current ), 409 ); }
			self::$rpc_orders[ $id ] = true;
			try {
				$order = new WC_Order( $id );
				if ( $order->get_status() !== $body['status'] && ! $order->update_status( $body['status'], 'Status actualizat din eDoc ERP.', true ) ) { throw new Schrack_EDoc_Exception( 'WooCommerce nu a confirmat schimbarea statusului.' ); }
			} finally { unset( self::$rpc_orders[ $id ] ); }
			return $this->finish_command( $body['command_id'], array_merge( array( 'ok' => true, 'command_id' => $body['command_id'] ), $this->capture( $id ) ), 200 );
		} ); }
		catch ( OutOfBoundsException $e ) { return new WP_Error( 'not_found', 'Comanda nu există.', array( 'status' => 404 ) ); }
		catch ( Throwable $e ) { return new WP_Error( 'status_pending', 'Rezultatul nu este confirmat. Reluați cu același command_id.', array( 'status' => 503 ) ); }
	}

	private function finish_command( string $id, array $response, int $status ): WP_REST_Response {
		global $wpdb;
		$changed = $wpdb->update( self::table( 'commands' ), array( 'state' => 'done', 'response' => wp_json_encode( $response ), 'http_status' => $status ), array( 'command_id' => $id ) );
		if ( false === $changed ) { throw new Schrack_EDoc_Exception( 'Journal unavailable.' ); }
		return new WP_REST_Response( $response, $status );
	}

	/** All native order writes share the RPC lock; no order-postmeta SQL is used. */
	public function before_order_save( $order ): void {
		if ( ! Schrack_EDoc_Client::enabled() || ! $order instanceof WC_Order || ! $order->get_id() || 'shop_order' !== $order->get_type() ) { return; }
		global $wpdb;
		$name = 'edoc_' . substr( hash( 'sha256', $wpdb->prefix . 'order_' . $order->get_id() ), 0, 50 );
		if ( isset( self::$held_locks[ $name ] ) ) { return; }
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) { throw new Schrack_EDoc_Exception( 'Comanda este sincronizată cu eDoc. Reîncercați salvarea.' ); }
		self::$held_locks[ $name ] = true;
		self::$save_locks[ spl_object_id( $order ) ] = $name;
	}

	public function saved_order( $order ): void {
		try { if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) { $this->capture_event( $order->get_id() ); } }
		finally {
			$object_id = spl_object_id( $order );
			if ( isset( self::$save_locks[ $object_id ] ) ) {
				global $wpdb; $name = self::$save_locks[ $object_id ];
				unset( self::$save_locks[ $object_id ], self::$held_locks[ $name ] );
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}
	public function capture_event( $id ): void {
		$id = (int) $id;
		if ( ! Schrack_EDoc_Client::enabled() || isset( self::$rpc_orders[ $id ] ) ) { return; }
		try { $this->capture( $id ); $this->queue(); }
		catch ( Throwable $e ) {
			try { $this->capture_failed( $id, $e ); } catch ( Throwable $queue_error ) { update_option( 'schrack_edoc_capture_error', 'Coada eDoc necesită verificare. Reluați importul istoricului după remediere.', false ); }
		}
	}

	/** Snapshot/hash/version and pending state are written in a single durable row. */
	public function capture( int $id ): array {
		return $this->locked( 'order_' . $id, function () use ( $id ) {
			global $wpdb;
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { throw new OutOfBoundsException( 'Order not found.' ); }
			// Explicit CRUD reload avoids snapshots of an older object passed by a save hook.
			$order->get_data_store()->read( $order );
			$snapshot = Schrack_EDoc_Orders::snapshot( $order );
			$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) || strlen( $json ) > Schrack_EDoc_Client::MAX_BYTES - 200 ) { throw new Schrack_EDoc_Exception( 'Order too large.' ); }
			$hash = hash( 'sha256', $json ); $table = self::table( 'orders' );
			$stored = $wpdb->get_row( $wpdb->prepare( "SELECT version,snapshot_hash,capture_required FROM $table WHERE order_id=%d", $id ), ARRAY_A );
			$version = $stored ? (int) $stored['version'] : 0;
			if ( ! $stored || ! hash_equals( $stored['snapshot_hash'], $hash ) ) {
				++$version;
				$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (order_id,version,snapshot_hash,snapshot,updated_at) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE version=VALUES(version),snapshot_hash=VALUES(snapshot_hash),snapshot=VALUES(snapshot),capture_required=0,attempts=0,next_attempt=0,last_error='',updated_at=VALUES(updated_at)", $id, $version, $hash, $json, gmdate( 'Y-m-d H:i:s' ) ) );
				if ( false === $ok ) { throw new Schrack_EDoc_Exception( 'Order queue unavailable.' ); }
			}
			if ( $stored && ! empty( $stored['capture_required'] ) ) { $wpdb->update( $table, array( 'capture_required' => 0, 'attempts' => 0, 'next_attempt' => 0, 'last_error' => '' ), array( 'order_id' => $id ) ); }
			return array( 'version' => $version, 'order' => $snapshot );
		} );
	}

	private function capture_failed( int $id, Throwable $error ): void {
		$this->locked( 'order_' . $id, function () use ( $id, $error ) {
			global $wpdb; $table = self::table( 'orders' );
			$attempt = min( 10, 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM $table WHERE order_id=%d", $id ) ) );
			$next = time() + min( 3600, 30 * ( 2 ** min( $attempt, 7 ) ) );
			$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (order_id,version,snapshot_hash,snapshot,capture_required,attempts,next_attempt,last_error,updated_at) VALUES (%d,0,'','{}',1,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE capture_required=1,attempts=VALUES(attempts),next_attempt=VALUES(next_attempt),last_error=VALUES(last_error),updated_at=VALUES(updated_at)", $id, $attempt, $next, Schrack_EDoc_Client::safe_error( $error ), gmdate( 'Y-m-d H:i:s' ) ) );
			if ( false === $ok ) { throw new Schrack_EDoc_Exception( 'Coada comenzilor eDoc nu poate fi salvată.' ); }
		} );
	}

	private function retry_captures(): void {
		global $wpdb; $table = self::table( 'orders' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM $table WHERE capture_required=1 AND attempts<10 AND next_attempt<=%d ORDER BY next_attempt,order_id LIMIT 10", time() ) );
		foreach ( $ids as $id ) {
			try { $this->capture( (int) $id ); } catch ( Throwable $error ) { $this->capture_failed( (int) $id, $error ); }
		}
	}

	public function queue( int $delay = 1 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, array( 'once' ), self::GROUP ) ) { as_schedule_single_action( time() + $delay, self::HOOK, array( 'once' ), self::GROUP, true ); }
		} elseif ( ! wp_next_scheduled( self::HOOK, array( 'once' ) ) ) { wp_schedule_single_event( time() + $delay, self::HOOK, array( 'once' ) ); }
	}

	public function schedule(): void {
		if ( ! Schrack_EDoc_Client::enabled() ) { return; }
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) { as_schedule_recurring_action( time() + 60, 60, self::HOOK, array(), self::GROUP, true ); }
		} elseif ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 60, 'schrack_edoc_minute', self::HOOK ); }
	}

	public static function clear_schedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( self::HOOK, null, self::GROUP ); }
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function work(): void {
		if ( ! Schrack_EDoc_Client::enabled() || ! function_exists( 'wc_get_orders' ) ) { return; }
		try { $this->locked( 'worker', function () {
			global $wpdb;
			$started = microtime( true );
			delete_option( 'schrack_edoc_worker_error' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'nonces' ) . ' WHERE expires_at < %d LIMIT 5000', time() ) );
			$this->retry_captures();
			$this->scan_orders();
			$table = self::table( 'orders' );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM $table WHERE capture_required=0 AND version > delivered_version AND attempts < 10 AND next_attempt <= %d ORDER BY next_attempt,order_id LIMIT 10", time() ) );
			foreach ( $ids as $id ) { if ( microtime( true ) - $started > 20 ) { break; } $this->deliver( (int) $id ); }
			if ( microtime( true ) - $started < 20 && time() >= (int) get_option( 'schrack_edoc_catalog_next', 0 ) ) {
				try {
					$result = ( new Schrack_EDoc_Importer( $this->settings ) )->run_batch();
					update_option( 'schrack_edoc_catalog_next', time() + ( ! empty( $result['has_more'] ) ? 1 : 300 ), false );
				} catch ( Throwable $e ) {
					$this->settings->update_status( 'edoc_catalog', array( 'errors' => 1, 'last_error' => Schrack_EDoc_Client::safe_error( $e ) ) );
					update_option( 'schrack_edoc_catalog_next', time() + 300, false );
				}
			}
		} ); } catch ( Throwable $e ) { update_option( 'schrack_edoc_worker_error', 'Sincronizarea eDoc va fi reluată la următoarea execuție.', false ); }
	}

	/** Network delivery does not hold the order lock or delay a native checkout save. */
	public function deliver( int $id ): void {
		global $wpdb; $table = self::table( 'orders' );
		$row = $this->locked( 'order_' . $id, static function () use ( $wpdb, $table, $id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_id=%d", $id ), ARRAY_A );
		} );
		if ( ! $row || ! empty( $row['capture_required'] ) || (int) $row['version'] <= (int) $row['delivered_version'] ) { return; }
		try {
			$version = (int) $row['version'];
			$connection = Schrack_EDoc_Client::config( true );
			$result = ( new Schrack_EDoc_Client( $connection ) )->request( 'POST', '/api/webshop/v1/orders', array(), array( 'event_id' => $id . ':' . $version, 'version' => $version, 'order' => json_decode( $row['snapshot'], true ) ) );
			if ( ! in_array( $result['result'] ?? '', array( 'created', 'updated', 'unchanged', 'stale' ), true ) || (int) ( $result['version'] ?? 0 ) < $version ) { throw new Schrack_EDoc_Exception( 'Confirmarea eDoc nu corespunde versiunii trimise.' ); }
			$this->locked( 'connection', static function () use ( $wpdb, $table, $version, $id, $connection ) {
				if ( ! hash_equals( Schrack_EDoc_Client::identity( $connection ), Schrack_EDoc_Client::identity( Schrack_EDoc_Client::config( true ) ) ) ) { throw new Schrack_EDoc_Exception( 'Conexiunea a fost modificată; livrarea va fi reluată.' ); }
				// A newer save stays pending; an older acknowledgement cannot erase it.
				$wpdb->query( $wpdb->prepare( "UPDATE $table SET delivered_version=GREATEST(delivered_version,%d),attempts=IF(version=%d,0,attempts),last_error=IF(version=%d,'',last_error),next_attempt=IF(version=%d,0,next_attempt) WHERE order_id=%d", $version, $version, $version, $version, $id ) );
			} );
			update_option( 'schrack_edoc_last_delivery', gmdate( 'Y-m-d H:i:s' ), false );
		} catch ( Throwable $e ) {
			$attempts = (int) $row['attempts'] + 1;
			$wpdb->update( $table, array( 'attempts' => $attempts, 'next_attempt' => time() + min( 3600, 30 * ( 2 ** min( $attempts, 7 ) ) ), 'last_error' => Schrack_EDoc_Client::safe_error( $e ) ), array( 'order_id' => $id, 'version' => $row['version'] ) );
		}
	}

	/** Fixed time windows plus an ID cursor remain safe if older rows leave the window. */
	public function scan_orders(): void {
		$state = (array) get_option( 'schrack_edoc_scan', array() );
		if ( empty( $state ) ) { $state = array( 'mode' => 'history', 'cursor' => 0, 'until' => time() - 1, 'since' => 0, 'page' => 1 ); }
		if ( 'idle' === $state['mode'] ) {
			if ( time() < (int) $state['until'] + 900 ) { return; }
			$state = array( 'mode' => 'modified', 'cursor' => 0, 'since' => max( 0, (int) $state['until'] - 300 ), 'until' => time() - 1, 'page' => 1 );
		}
		$cursor = max( 0, (int) ( $state['cursor'] ?? 0 ) );
		$args = array( 'type' => 'shop_order', 'limit' => 25, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' );
		$args[ 'history' === $state['mode'] ? 'date_created' : 'date_modified' ] = 'history' === $state['mode'] ? '<=' . (int) $state['until'] : (int) $state['since'] . '...' . (int) $state['until'];
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$args['field_query'] = array( array( 'field' => 'id', 'value' => $cursor, 'compare' => '>' ) );
			$ids = wc_get_orders( $args );
		} else {
			// Scope the read-only ID predicate to this one WC query, then remove both filters.
			$args['schrack_edoc_after_id'] = $cursor;
			$query_filter = static function ( $query, $vars ) { if ( isset( $vars['schrack_edoc_after_id'] ) ) { $query['schrack_edoc_after_id'] = (int) $vars['schrack_edoc_after_id']; $query['suppress_filters'] = false; } return $query; };
			$where_filter = static function ( $where, $query ) {
				if ( ! array_key_exists( 'schrack_edoc_after_id', $query->query_vars ) ) { return $where; }
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", (int) $query->get( 'schrack_edoc_after_id' ) );
			};
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $query_filter, 10, 2 );
			add_filter( 'posts_where', $where_filter, 10, 2 );
			try { $ids = wc_get_orders( $args ); }
			finally { remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $query_filter, 10 ); remove_filter( 'posts_where', $where_filter, 10 ); }
		}
		foreach ( $ids as $id ) {
			try { $this->capture( (int) $id ); }
			catch ( Throwable $error ) { $this->capture_failed( (int) $id, $error ); }
			$state['cursor'] = (int) $id;
		}
		if ( count( $ids ) < 25 ) { $state['mode'] = 'idle'; } else { $state['page'] = (int) ( $state['page'] ?? 1 ) + 1; }
		update_option( 'schrack_edoc_scan', $state, false );
	}

	public function admin_menu(): void { add_submenu_page( 'woocommerce', 'Integrare eDoc ERP', 'eDoc ERP', 'manage_woocommerce', 'schrack-edoc', array( $this, 'admin_page' ) ); }
	public function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Acces interzis.' ); }
		global $wpdb;
		$config = Schrack_EDoc_Client::config();
		$pending = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'orders' ) . ' WHERE version>delivered_version OR capture_required=1' );
		$failed = $wpdb->get_results( 'SELECT order_id,attempts,last_error FROM ' . self::table( 'orders' ) . ' WHERE attempts>0 ORDER BY attempts DESC LIMIT 20', ARRAY_A );
		$status = $this->settings->get_status()['edoc_catalog'] ?? array();
		$scan = (array) get_option( 'schrack_edoc_scan', array() );
		$notice = get_transient( 'schrack_edoc_notice_' . get_current_user_id() );
		delete_transient( 'schrack_edoc_notice_' . get_current_user_id() );
		include SCHRACK_WC_SYNC_PATH . 'templates/admin-edoc.php';
	}

	public function admin_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Acces interzis.' ); }
		check_admin_referer( 'schrack_edoc_settings' );
		try { $this->locked( 'connection', function () {
			$old = Schrack_EDoc_Client::config( true ); $c = $old;
			$c['enabled'] = isset( $_POST['edoc_enabled'] );
			$c['url'] = trim( (string) wp_unslash( $_POST['edoc_url'] ?? '' ) );
			$c['key_id'] = sanitize_text_field( wp_unslash( $_POST['edoc_key_id'] ?? '' ) );
			$secret = trim( (string) wp_unslash( $_POST['edoc_secret'] ?? '' ) );
			if ( '' !== $secret ) { $c['secret'] = $secret; }
			if ( '' !== $c['url'] && ! Schrack_EDoc_Client::valid_url( $c['url'] ) ) { throw new Schrack_EDoc_Exception( 'Introduceți originea HTTPS eDoc, fără query, fragment sau credențiale.' ); }
			if ( $c['enabled'] && ( '' === $c['url'] || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/D', $c['key_id'] ) || strlen( $c['secret'] ) < 32 || strlen( $c['secret'] ) > 512 || 'RON' !== get_woocommerce_currency() ) ) { throw new Schrack_EDoc_Exception( 'Activarea necesită URL, identificator, secret de minimum 32 caractere și moneda RON.' ); }
			$map = array();
			foreach ( preg_split( '/\r?\n/', (string) wp_unslash( $_POST['edoc_tax_map'] ?? '' ) ) as $line ) {
				if ( '' === trim( $line ) ) { continue; }
				$parts = explode( '=', $line, 2 );
				if ( count( $parts ) !== 2 || ! is_numeric( trim( $parts[0] ) ) || (float) $parts[0] < 0 || (float) $parts[0] > 100 ) { throw new Schrack_EDoc_Exception( 'Maparea TVA trebuie să conțină câte o linie cotă=slug-clasă.' ); }
				$key = rtrim( rtrim( number_format( (float) $parts[0], 4, '.', '' ), '0' ), '.' );
				$value = trim( $parts[1] );
				if ( ! in_array( $value, array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ), true ) ) { throw new Schrack_EDoc_Exception( 'Clasa fiscală nu există în WooCommerce.' ); }
				$map[ $key ] = $value;
			}
			$c['tax_map'] = $map;
			if ( $old['url'] !== $c['url'] ) { $c['entity_id'] = 0; }
			update_option( Schrack_EDoc_Client::OPTION, $c, false );
			if ( $c['enabled'] && ( ! $old['enabled'] || $c['url'] !== $old['url'] || $c['key_id'] !== $old['key_id'] ) ) { $this->reset_history(); }
			update_option( 'schrack_edoc_catalog_next', 0, false );
			$this->notice( 'Configurarea eDoc a fost salvată.' );
			$this->schedule(); $this->queue();
		} ); } catch ( Throwable $e ) { $this->notice( Schrack_EDoc_Client::safe_error( $e ), true ); }
		wp_safe_redirect( admin_url( 'admin.php?page=schrack-edoc' ) ); exit;
	}

	public function admin_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Acces interzis.' ); }
		check_admin_referer( 'schrack_edoc_action' );
		try {
			$action = sanitize_key( $_POST['edoc_action'] ?? '' );
			if ( 'test' === $action ) {
				$tested = Schrack_EDoc_Client::config();
				$data = ( new Schrack_EDoc_Client() )->request( 'GET', '/api/webshop/v1/health' );
				if ( 1 !== ( $data['protocol_version'] ?? null ) || 'RON' !== ( $data['currency'] ?? '' ) || (int) ( $data['entity_id'] ?? 0 ) < 1 ) { throw new Schrack_EDoc_Exception( 'Protocolul sau moneda ERP nu este compatibilă.' ); }
				$c = Schrack_EDoc_Client::config( true );
				foreach ( array( 'url', 'key_id', 'secret' ) as $field ) { if ( $c[ $field ] !== $tested[ $field ] ) { throw new Schrack_EDoc_Exception( 'Conexiunea a fost modificată. Repetați testul.' ); } }
				if ( $c['entity_id'] && (int) $c['entity_id'] !== (int) $data['entity_id'] ) { throw new Schrack_EDoc_Exception( 'Cheia aparține altei entități ERP.' ); }
				$c['entity_id'] = (int) $data['entity_id']; update_option( Schrack_EDoc_Client::OPTION, $c, false );
				$this->notice( 'Conexiune validă. Entitate ERP: ' . $c['entity_id'] . ( empty( $data['enabled'] ) ? ' (dezactivată în ERP).' : '.' ) );
			} else {
				if ( ! Schrack_EDoc_Client::enabled() ) { throw new Schrack_EDoc_Exception( 'Activați integrarea înainte de sincronizare.' ); }
				if ( 'history' === $action ) { $this->locked( 'connection', function () { $this->reset_history(); } ); }
				elseif ( 'catalog' === $action ) { update_option( 'schrack_edoc_catalog_next', 0, false ); }
				elseif ( 'retry' === $action ) { global $wpdb; $wpdb->query( 'UPDATE ' . self::table( 'orders' ) . " SET attempts=0,next_attempt=0,last_error='' WHERE version>delivered_version OR capture_required=1" ); }
				else { throw new Schrack_EDoc_Exception( 'Acțiune invalidă.' ); }
				$this->queue(); $this->notice( 'Sincronizarea eDoc a fost pusă în coadă.' );
			}
		} catch ( Throwable $e ) { $this->notice( Schrack_EDoc_Client::safe_error( $e ), true ); }
		wp_safe_redirect( admin_url( 'admin.php?page=schrack-edoc' ) ); exit;
	}

	private function reset_history(): void {
		global $wpdb;
		delete_option( 'schrack_edoc_scan' );
		delete_option( 'schrack_edoc_capture_error' );
		$wpdb->query( 'UPDATE ' . self::table( 'orders' ) . " SET delivered_version=0,attempts=0,next_attempt=0,last_error=''" );
	}
	private function notice( string $message, bool $error = false ): void { set_transient( 'schrack_edoc_notice_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 60 ); }
}
