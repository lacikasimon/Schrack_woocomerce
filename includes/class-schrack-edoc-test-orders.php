<?php
/** Explicit, administrator-only synthetic orders for exercising the eDoc bridge. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_EDoc_Test_Orders {
	public const OPTION = 'schrack_edoc_test_orders';
	public const META_BATCH = '_schrack_edoc_test_batch';
	public const META_INDEX = '_schrack_edoc_test_index';
	private const META_READY = '_schrack_edoc_test_ready';

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'wp_ajax_schrack_edoc_test_orders', array( $this, 'ajax' ) );
		foreach ( array( 'new_order', 'cancelled_order', 'failed_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'customer_invoice', 'customer_note', 'customer_cancelled_order', 'customer_failed_order' ) as $email ) {
			add_filter( 'woocommerce_email_enabled_' . $email, array( $this, 'email_enabled' ), 100, 2 );
			add_filter( 'woocommerce_email_recipient_' . $email, array( $this, 'email_recipient' ), 100, 2 );
		}
	}

	public function email_enabled( $enabled, $order = null ): bool {
		return $order instanceof WC_Order && $order->get_meta( self::META_BATCH ) ? false : (bool) $enabled;
	}

	/** Manually triggered customer invoices can bypass Woo's email-enabled setting. */
	public function email_recipient( $recipient, $order = null ) {
		return $order instanceof WC_Order && $order->get_meta( self::META_BATCH ) ? '' : $recipient;
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', 'Comenzi de test eDoc', 'Comenzi de test eDoc', 'manage_woocommerce', 'schrack-edoc-test-orders', array( $this, 'page' ) );
	}

	public function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Acces interzis.' ); }
		wp_enqueue_script( 'schrack-edoc-test-orders', SCHRACK_WC_SYNC_URL . 'assets/admin-edoc-test-orders.js', array(), SCHRACK_WC_SYNC_VERSION, true );
		wp_localize_script( 'schrack-edoc-test-orders', 'schrackEDocTestOrders', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'schrack_edoc_test_orders' ) ) );
		?>
		<div class="wrap" id="schrack-edoc-test-orders">
			<h1>Comenzi de test eDoc</h1>
			<p>Lot unic: 6–15 comenzi pe lună, din iunie anul curent până astăzi, cu date și cantități aleatorii. Clienții și adresele sunt fictive și marcate TEST.</p>
			<p>Comenzile folosesc produse existente și rămân <strong>Plată în așteptare</strong>. Nu se încasează bani, nu se scade stocul și nu se trimit e-mailuri. Integrarea eDoc activă le transmite în ERP.</p>
			<p><button class="button" id="edoc-test-prepare" type="button">Pregătește lotul</button> <button class="button button-primary" id="edoc-test-run" type="button" hidden>Creează comenzile de test</button></p>
			<p id="edoc-test-message" role="status" aria-live="polite"></p>
			<p id="edoc-test-progress"></p>
			<table class="widefat striped" style="max-width:720px"><thead><tr><th>Lună</th><th>Planificate</th><th>Create</th><th>Confirmate în ERP</th></tr></thead><tbody id="edoc-test-months"></tbody></table>
			<h2>Comenzi create</h2>
			<table class="widefat striped"><thead><tr><th>Comandă</th><th>Data</th><th>Client TEST</th><th>Total</th><th>ERP</th></tr></thead><tbody id="edoc-test-results"></tbody></table>
		</div>
		<?php
	}

	/** Build a frozen plan once; retries never draw a new date, price or customer. */
	public static function plan( DateTimeImmutable $now, array $products ): array {
		$start = $now->setDate( (int) $now->format( 'Y' ), 6, 1 )->setTime( 0, 0 );
		if ( $start > $now || ! $products ) { throw new Schrack_EDoc_Exception( 'Sunt necesare luna iunie sau o lună ulterioară și produse simple publicate, disponibile, cu preț pozitiv.' ); }
		$batch = wp_generate_uuid4(); $rows = array(); $counts = array();
		$first_names = array( 'Alex', 'Diana', 'Mihai', 'Elena', 'Radu', 'Irina', 'Vlad', 'Ana', 'Matei', 'Ioana' );
		$last_names = array( 'Demo', 'Exemplu', 'Probă', 'Simulare', 'Testare' );
		$places = array( array( 'București', 'B' ), array( 'Cluj-Napoca', 'CJ' ), array( 'Satu Mare', 'SM' ), array( 'Brașov', 'BV' ), array( 'Timișoara', 'TM' ), array( 'Oradea', 'BH' ) );
		for ( $month = $start; $month <= $now; $month = $month->modify( '+1 month' ) ) {
			$key = $month->format( 'Y-m' ); $count = random_int( 6, 15 );
			$end = min( $now->getTimestamp(), $month->modify( '+1 month' )->getTimestamp() - 1 );
			$dates = array();
			for ( $i = 0; $i < $count; ++$i ) { $dates[] = random_int( $month->getTimestamp(), $end ); }
			sort( $dates ); $counts[ $key ] = $count;
			foreach ( $dates as $timestamp ) {
				$index = count( $rows ) + 1; $place = $places[ array_rand( $places ) ];
				$address = array( 'first_name' => 'TEST ' . $first_names[ array_rand( $first_names ) ], 'last_name' => $last_names[ array_rand( $last_names ) ] . ' ' . sprintf( '%03d', $index ), 'company' => 'FIRMĂ FICTIVĂ TEST — NU SE LIVREAZĂ', 'address_1' => 'Strada Testelor nr. ' . random_int( 1, 99 ) . ' — ADRESĂ FICTIVĂ', 'address_2' => 'LOT TEST ' . substr( $batch, 0, 8 ), 'city' => $place[0], 'state' => $place[1], 'postcode' => '000000', 'country' => 'RO' );
				$lines = array();
				foreach ( (array) array_rand( $products, min( count( $products ), random_int( 1, 3 ) ) ) as $p ) { $lines[] = array( 'product_id' => $products[ $p ]['product_id'], 'quantity' => random_int( 1, 8 ), 'unit_price' => $products[ $p ]['unit_price'] ); }
				$rows[] = array( 'index' => $index, 'month' => $key, 'created' => $timestamp, 'address' => $address, 'email' => 'test-' . substr( $batch, 0, 8 ) . '-' . $index . '@example.invalid', 'lines' => $lines );
			}
		}
		return array( 'batch' => $batch, 'status' => 'ready', 'cursor' => 0, 'until' => $now->format( 'Y-m-d H:i:s' ), 'months' => $counts, 'rows' => $rows, 'orders' => array(), 'error' => '' );
	}

	private static function state(): array {
		wp_cache_delete( self::OPTION, 'options' );
		return (array) get_option( self::OPTION, array() );
	}

	private static function persist( array $state ): void {
		if ( ! update_option( self::OPTION, $state, false ) && self::state() !== $state ) { throw new Schrack_EDoc_Exception( 'Progresul lotului nu poate fi salvat. Verificați starea înainte de reluare.' ); }
	}

	/** Preparing an existing batch is a no-op, including after it has finished. */
	public function prepare(): array {
		return ( new Schrack_EDoc_Bridge( new Schrack_Settings() ) )->locked( 'test_orders', static function () {
			$state = self::state();
			if ( $state ) { return $state; }
			$products = array();
			foreach ( wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'stock_status' => 'instock', 'limit' => 60, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $product ) {
				if ( '' === $product->get_price() || ! $product->is_purchasable() ) { continue; }
				$net = (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1 ) );
				if ( ! is_finite( $net ) || $net <= 0 ) { continue; }
				$products[] = array( 'product_id' => $product->get_id(), 'unit_price' => wc_format_decimal( $net, 4 ) );
			}
			$state = self::plan( current_datetime(), $products ); self::persist( $state );
			return $state;
		} );
	}

	/** One order per request; batch/index metadata also recovers a lost HTTP response. */
	public function step( string $batch, int $cursor ): array {
		return ( new Schrack_EDoc_Bridge( new Schrack_Settings() ) )->locked( 'test_orders', function () use ( $batch, $cursor ) {
			$state = self::state();
			if ( ! $state || ! hash_equals( $state['batch'], $batch ) || $cursor > $state['cursor'] ) { throw new Schrack_EDoc_Exception( 'Lotul sau poziția nu corespunde. Actualizați starea.' ); }
			if ( $cursor < $state['cursor'] || 'complete' === $state['status'] ) { return $state; }
			try {
				$row = $state['rows'][ $cursor ];
				$order = $this->create_order( $state['batch'], $row );
				$state['orders'][] = array( 'id' => $order->get_id(), 'month' => $row['month'], 'date' => wp_date( 'Y-m-d H:i', $row['created'] ), 'name' => $row['address']['first_name'] . ' ' . $row['address']['last_name'], 'total' => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) . ' ' . $order->get_currency(), 'url' => $order->get_edit_order_url() );
				++$state['cursor'];
				$state['status'] = count( $state['rows'] ) === $state['cursor'] ? 'complete' : 'running';
				$state['error'] = ''; self::persist( $state );
			} catch ( Throwable $error ) {
				$state['error'] = Schrack_EDoc_Client::safe_error( $error ); self::persist( $state ); throw $error;
			}
			return $state;
		} );
	}

	private function create_order( string $batch, array $row ): WC_Order {
		$meta = array( array( 'key' => self::META_BATCH, 'value' => $batch ), array( 'key' => self::META_INDEX, 'value' => (string) $row['index'] ) );
		$args = array( 'type' => 'shop_order', 'limit' => 1 );
		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		// Legacy order queries require an explicit, scoped translation to WP_Query arguments.
		$legacy = static function ( $query, $vars ) {
			if ( isset( $vars['schrack_test_lookup'] ) ) { $query['meta_query'] = array_merge( $query['meta_query'] ?? array(), $vars['schrack_test_lookup'] ); }
			return $query;
		};
		$args[ $hpos ? 'meta_query' : 'schrack_test_lookup' ] = $meta;
		if ( ! $hpos ) { add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy, 10, 2 ); }
		try { $existing = wc_get_orders( $args ); } finally { if ( ! $hpos ) { remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy, 10 ); } }
		if ( $existing ) {
			$order = reset( $existing );
			if ( 'yes' !== $order->get_meta( self::META_READY ) ) { throw new Schrack_EDoc_Exception( 'Comanda TEST #' . $order->get_id() . ' este incompletă și necesită verificare; nu va fi duplicată.' ); }
			return $order;
		}
		$products = array();
		foreach ( $row['lines'] as $line ) {
			$product = wc_get_product( $line['product_id'] );
			if ( ! $product || ! $product->is_type( 'simple' ) ) { throw new Schrack_EDoc_Exception( 'Un produs din lot nu mai există. Nicio comandă nouă nu a fost creată pentru această poziție.' ); }
			$products[] = $product;
		}
		$order = new class extends WC_Order {
			protected function handle_exception( $error, $message = 'Error' ) { throw new Schrack_EDoc_Exception( 'Comanda TEST nu a putut fi salvată integral. Verificați starea lotului.' ); }
		};
		$order->set_created_via( 'schrack_edoc_test' ); $order->set_customer_id( 0 ); $order->set_status( 'pending' );
		$order->set_currency( get_woocommerce_currency() ); $order->set_prices_include_tax( wc_prices_include_tax() ); $order->set_date_created( $row['created'] );
		$order->set_address( $row['address'] + array( 'email' => $row['email'], 'phone' => '0000000000' ), 'billing' );
		$order->set_address( $row['address'], 'shipping' );
		$order->set_customer_note( 'COMANDĂ TEST eDoc — FĂRĂ PLATĂ, FĂRĂ LIVRARE. Date complet fictive.' );
		$order->update_meta_data( self::META_BATCH, $batch ); $order->update_meta_data( self::META_INDEX, (string) $row['index'] );
		$order->update_meta_data( '_billing_vat_number', 'TEST-' . sprintf( '%06d', $row['index'] ) );
		foreach ( $row['lines'] as $i => $line ) {
			$item = new WC_Order_Item_Product(); $item->set_product( $products[ $i ] ); $item->set_quantity( $line['quantity'] );
			$subtotal = wc_format_decimal( $line['quantity'] * (float) $line['unit_price'], wc_get_price_decimals() );
			$item->set_subtotal( $subtotal ); $item->set_total( $subtotal ); $order->add_item( $item );
		}
		// Scope the mail veto to this request; native Woo mail is also suppressed by metadata.
		$no_mail = static fn() => false; add_filter( 'pre_wp_mail', $no_mail, PHP_INT_MAX );
		try {
			$order->calculate_totals( true );
			$order->add_order_note( 'LOT TEST eDoc ' . $batch . ' · poziția ' . $row['index'] . '. Date fictive, fără plată și fără livrare.', false );
			$order->update_meta_data( self::META_READY, 'yes' ); $order->save();
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh || 'yes' !== $fresh->get_meta( self::META_READY ) || count( $fresh->get_items( 'line_item' ) ) !== count( $row['lines'] ) ) { throw new Schrack_EDoc_Exception( 'Salvarea comenzii TEST necesită verificare înainte de continuare.' ); }
		} finally { remove_filter( 'pre_wp_mail', $no_mail, PHP_INT_MAX ); }
		return $order;
	}

	/** Include ERP acknowledgements so the operator can verify the whole batch. */
	public function view( ?array $state = null ): array {
		$state = $state ?? self::state();
		if ( ! $state ) { return array( 'status' => 'idle', 'months' => array(), 'orders' => array(), 'cursor' => 0, 'total' => 0 ); }
		$months = array(); $ids = array_column( $state['orders'], 'id' ); $confirmed = array();
		if ( $ids ) {
			global $wpdb; $table = Schrack_EDoc_Bridge::table( 'orders' );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$confirmed = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM $table WHERE capture_required=0 AND version>0 AND delivered_version>=version AND order_id IN ($placeholders)", ...$ids ) ) );
		}
		foreach ( $state['months'] as $key => $count ) { $months[ $key ] = array( 'month' => $key, 'planned' => $count, 'created' => 0, 'confirmed' => 0 ); }
		$orders = $state['orders'];
		foreach ( $orders as &$order ) { $order['confirmed'] = in_array( $order['id'], $confirmed, true ); ++$months[ $order['month'] ]['created']; if ( $order['confirmed'] ) { ++$months[ $order['month'] ]['confirmed']; } }
		unset( $order );
		return array( 'batch' => $state['batch'], 'status' => $state['status'], 'cursor' => $state['cursor'], 'total' => count( $state['rows'] ), 'until' => $state['until'], 'months' => array_values( $months ), 'orders' => $orders, 'error' => $state['error'], 'confirmed' => count( $confirmed ), 'edoc_enabled' => Schrack_EDoc_Client::enabled() );
	}

	public function ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'schrack_edoc_test_orders', 'nonce', false ) ) { wp_send_json_error( 'Acces interzis.', 403 ); }
		$command = sanitize_key( $_REQUEST['command'] ?? 'status' );
		if ( 'status' !== $command && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_send_json_error( 'Operațiunea necesită POST.', 405 ); }
		try {
			if ( 'status' === $command ) { $state = null; }
			elseif ( 'prepare' === $command ) { $state = $this->prepare(); }
			elseif ( 'step' === $command ) { $state = $this->step( sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) ), absint( $_POST['cursor'] ?? 0 ) ); }
			else { throw new Schrack_EDoc_Exception( 'Operațiune necunoscută.' ); }
			wp_send_json_success( $this->view( $state ) );
		} catch ( Throwable $error ) { wp_send_json_error( Schrack_EDoc_Client::safe_error( $error ), 400 ); }
	}
}
