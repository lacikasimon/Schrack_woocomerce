<?php
/** Private invoice presentation and download proxy for admins and signed-in order owners. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_EDoc_Invoices {
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'metaboxes' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'customer_panel' ), 25 );
		add_action( 'admin_post_schrack_edoc_invoice', array( $this, 'download' ) );
		add_action( 'admin_post_nopriv_schrack_edoc_invoice', array( $this, 'download' ) );
		add_action( 'wp_ajax_schrack_edoc_invoice', array( $this, 'refresh' ) );
	}

	public static function can_access( WC_Order $order ): bool {
		return is_user_logged_in() && ( current_user_can( 'edit_shop_orders' ) || ( $order->get_user_id() > 0 && (int) $order->get_user_id() === get_current_user_id() ) );
	}

	private static function order( int $id ): ?WC_Order {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return null; }
		$order->get_data_store()->read( $order );
		return $order;
	}

	/** Shared by both transports; possession of a URL or nonce never grants access. */
	public static function authorize( int $id, string $nonce ) {
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'schrack_edoc_invoice_' . $id ) ) { return new WP_Error( 'forbidden', 'Nu aveți acces la această factură.', array( 'status' => 403 ) ); }
		$order = self::order( $id );
		if ( ! $order || ! self::can_access( $order ) ) { return new WP_Error( 'forbidden', 'Nu aveți acces la această factură.', array( 'status' => 403 ) ); }
		return $order;
	}

	/** Resolve metadata only on our server; invoice IDs are never accepted from the browser. */
	public static function pdf( int $id, string $nonce ) {
		$order = self::authorize( $id, $nonce );
		if ( is_wp_error( $order ) ) { return $order; }
		$invoice = $order->get_meta( '_edoc_invoice', true );
		if ( ! is_array( $invoice ) || 'issued' !== ( $invoice['state'] ?? '' ) || ! is_int( $invoice['erp_invoice_id'] ?? null ) || $invoice['erp_invoice_id'] < 1 ) { return new WP_Error( 'invoice_unavailable', 'Factura nu este disponibilă pentru descărcare.', array( 'status' => 404 ) ); }
		try { return ( new Schrack_EDoc_Client() )->download( '/api/webshop/v1/invoices/' . $invoice['erp_invoice_id'] . '/pdf' ); }
		catch ( Throwable $error ) { return new WP_Error( 'invoice_unavailable', 'Factura nu poate fi descărcată momentan. Reîncercați mai târziu.', array( 'status' => 502 ) ); }
	}

	public function download(): void {
		nocache_headers();
		$id = absint( $_GET['order_id'] ?? 0 );
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
		$result = self::pdf( $id, $nonce );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Factură eDoc', array( 'response' => $result->get_error_data()['status'] ) ); }
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $result['body'] ) );
		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified PDF bytes, never HTML.
		exit;
	}

	public function metaboxes(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) { return; }
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) { $screens[] = wc_get_page_screen_id( 'shop-order' ); }
		foreach ( array_unique( $screens ) as $screen ) { add_meta_box( 'schrack-edoc-invoice', 'Factură eDoc', array( $this, 'admin_panel' ), $screen, 'side' ); }
	}

	public function admin_panel( $object ): void {
		$order = $object instanceof WC_Order ? $object : self::order( (int) ( $object->ID ?? 0 ) );
		if ( $order instanceof WC_Order && current_user_can( 'edit_shop_orders' ) ) { $this->panel( $order, true ); }
	}

	public function customer_panel( WC_Order $order ): void {
		if ( is_admin() || ! is_user_logged_in() || $order->get_user_id() < 1 || (int) $order->get_user_id() !== get_current_user_id() || ( function_exists( 'is_account_page' ) && ! is_account_page() ) ) { return; }
		$this->panel( $order, false );
	}

	private function panel( WC_Order $order, bool $admin ): void {
		wp_enqueue_script( 'schrack-edoc-invoice', SCHRACK_WC_SYNC_URL . 'assets/js/edoc-invoice.js', array(), SCHRACK_WC_SYNC_VERSION, true );
		$invoice = (array) $order->get_meta( '_edoc_invoice', true );
		$visible = $admin || 'issued' === ( $invoice['state'] ?? '' );
		// Only this small read-only panel refreshes; the enclosing order form remains untouched.
		echo '<div class="schrack-edoc-invoice" data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-order="' . esc_attr( (string) $order->get_id() ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'schrack_edoc_invoice_' . $order->get_id() ) ) . '" data-admin="' . ( $admin ? '1' : '0' ) . '">';
		echo '<div data-invoice-content>' . self::content( $order, $admin ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped fragment.
		echo '<button type="button" class="button" data-invoice-refresh' . ( $visible ? '' : ' hidden' ) . '>Actualizează factura</button><p data-invoice-message role="status" aria-live="polite"></p></div>';
	}

	private static function content( WC_Order $order, bool $admin ): string {
		$invoice = $order->get_meta( '_edoc_invoice', true );
		if ( ! is_array( $invoice ) || ! in_array( $invoice['state'] ?? '', array( 'issued', 'cancelled' ), true ) ) { return $admin ? '<p>Nu există o factură eDoc asociată.</p>' : ''; }
		if ( ! $admin && 'issued' !== $invoice['state'] ) { return ''; }
		$html = $admin ? '' : '<h2>Factura</h2>';
		$html .= '<p><strong>' . esc_html( (string) $invoice['number'] ) . '</strong><br>' . esc_html( (string) $invoice['issue_date'] );
		if ( $admin ) { $html .= '<br>' . esc_html( (string) $invoice['total'] . ' ' . (string) $invoice['currency'] ) . '<br>' . esc_html( 'issued' === $invoice['state'] ? 'Emisă' : 'Anulată' ); }
		$html .= '</p>';
		if ( 'issued' === $invoice['state'] ) {
			$url = add_query_arg( array( 'action' => 'schrack_edoc_invoice', 'order_id' => $order->get_id(), '_wpnonce' => wp_create_nonce( 'schrack_edoc_invoice_' . $order->get_id() ) ), admin_url( 'admin-post.php' ) );
			$html .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $admin ? 'Descarcă PDF' : 'Descarcă factura (PDF)' ) . '</a></p>';
		}
		return $html;
	}

	public function refresh(): void {
		nocache_headers();
		$id = absint( $_GET['order_id'] ?? 0 );
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
		$order = self::authorize( $id, $nonce );
		if ( is_wp_error( $order ) ) { wp_send_json_error( array( 'message' => $order->get_error_message() ), 403 ); }
		$admin = '1' === ( $_GET['admin'] ?? '' ) && current_user_can( 'edit_shop_orders' );
		$html = self::content( $order, $admin );
		wp_send_json_success( array( 'html' => $html, 'visible' => $admin || '' !== $html ) );
	}
}
