<?php
/**
 * Payment-return regressions using the original WooCommerce checkout controller
 * and templates, with in-memory WordPress/WooCommerce dependencies. No database.
 * Run: php tests/checkout-confirmation.php /path/to/woocommerce-source
 */

namespace Automattic\WooCommerce\Internal\Utilities {
	class Users {
		public static function should_user_verify_order_email( ...$args ): bool {
			return $GLOBALS['verify_email'];
		}
	}
}

namespace {
	$source = $argv[1] ?? '';
	if ( ! is_file( $source . '/includes/shortcodes/class-wc-shortcode-checkout.php' ) || ! is_file( $source . '/templates/checkout/thankyou.php' ) || ! is_file( $source . '/templates/checkout/order-received.php' ) ) {
		fwrite( STDERR, "Usage: php tests/checkout-confirmation.php /path/to/woocommerce-source\n" );
		exit( 1 );
	}

	define( 'ABSPATH', __DIR__ );
	define( 'MINUTE_IN_SECONDS', 60 );
	$filters = $events = array();
	$user_id = $checks = 0;
	$verify_email = $throw_on_shortcode = false;
	$wp = (object) array( 'query_vars' => array() );
	$wc = (object) array( 'cart' => new \stdClass(), 'session' => new \stdClass() );

	function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ): void { $GLOBALS['filters'][ $hook ][ $priority ][] = array( $callback, $accepted ); }
	function remove_filter( $hook, $callback, $priority = 10 ): void {
		foreach ( $GLOBALS['filters'][ $hook ][ $priority ] ?? array() as $index => $entry ) {
			if ( $entry[0] === $callback ) { unset( $GLOBALS['filters'][ $hook ][ $priority ][ $index ] ); }
		}
	}
	function apply_filters( $hook, $value, ...$args ): mixed {
		$priorities = $GLOBALS['filters'][ $hook ] ?? array();
		ksort( $priorities );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as [ $callback, $accepted ] ) { $value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted ) ); }
		}
		return $value;
	}
	function do_action( $hook, ...$args ): void { $GLOBALS['events'][] = $hook; }
	function __( $text, $domain = '' ): string { return apply_filters( 'gettext', $text, $text, $domain ); }
	function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $text ): string { return esc_html( $text ); }
	function esc_url( $text ): string { return esc_html( $text ); }
	function esc_url_raw( $text ): string { return (string) $text; }
	function esc_html__( $text, $domain = '' ): string { return esc_html( __( $text, $domain ) ); }
	function esc_html_e( $text, $domain = '' ): void { echo esc_html__( $text, $domain ); }
	function esc_attr_e( $text, $domain = '' ): void { echo esc_attr( __( $text, $domain ) ); }
	function wp_kses_post( $text ): string { return (string) $text; }
	function wp_unslash( $text ): string { return stripslashes( (string) $text ); }
	function sanitize_text_field( $text ): string { return strip_tags( (string) $text ); }
	function sanitize_html_class( $text ): string { return preg_replace( '/[^a-zA-Z0-9_-]/', '', $text ); }
	function sanitize_hex_color( $text ): string { return preg_match( '/^#[0-9a-f]{6}$/i', $text ) ? $text : ''; }
	function wp_parse_args( $args, $defaults ): array { return array_merge( $defaults, $args ); }
	function wp_enqueue_style( $handle ): void {}
	function wp_enqueue_script( $handle ): void {}
	function sanitize_email( $text ): string { return (string) $text; }
	function wc_clean( $text ): string { return sanitize_text_field( $text ); }
	function absint( $value ): int { return abs( (int) $value ); }
	function wp_parse_url( $url, $component ): mixed { return parse_url( $url, $component ); }
	function is_ssl(): bool { return true; }
	function remove_query_arg( $keys, $url ): string { return $url; }
	function get_query_var( $key ): mixed { return $GLOBALS['wp']->query_vars[ $key ] ?? ''; }
	function is_wc_endpoint_url( $endpoint ): bool { return isset( $GLOBALS['wp']->query_vars[ $endpoint ] ); }
	function get_current_user_id(): int { return $GLOBALS['user_id']; }
	function current_user_can( ...$args ): bool { return false; }
	function is_user_logged_in(): bool { return get_current_user_id() > 0; }
	function wp_verify_nonce( ...$args ): bool { return false; }
	function WC(): object { return $GLOBALS['wc']; }
	function wc_get_order( $id ): mixed { return (int) $id === 42 ? $GLOBALS['order'] : false; }
	function wc_empty_cart(): void { $GLOBALS['events'][] = 'empty_cart'; }
	function wc_format_datetime( $date ): string { return '19.11.2026'; }
	function wc_get_order_status_name( $status ): string { return $status; }
	function wc_print_notice( $message, $type = 'success', $data = array(), $return = false ): string {
		$html = '<div class="woocommerce-' . esc_attr( $type ) . '">' . $message . '</div>';
		if ( ! $return ) { echo $html; }
		return $html;
	}
	function woocommerce_login_form( $args ): void { echo '<form id="login"></form>'; }
	function shortcode_exists( $name ): bool { return 'woocommerce_checkout' === $name; }
	function do_shortcode( $text ): string {
		if ( $GLOBALS['throw_on_shortcode'] ) { throw new \RuntimeException( 'Simulated gateway failure' ); }
		ob_start();
		try { \WC_Shortcode_Checkout::output( array() ); return (string) ob_get_contents(); }
		finally { ob_end_clean(); }
	}
	function wc_get_template( $template, $args ): void {
		$GLOBALS['events'][] = $template;
		if ( in_array( $template, array( 'checkout/thankyou.php', 'checkout/order-received.php' ), true ) ) {
			$order = $args['order'];
			include $GLOBALS['source'] . '/templates/' . $template;
		} elseif ( 'checkout/form-verify-email.php' === $template ) {
			echo '<form id="verify-email"></form>';
		} elseif ( 'checkout/order-receipt.php' === $template ) {
			echo '<form id="gateway-payment"></form>';
		} else {
			throw new \RuntimeException( 'Unexpected template: ' . $template );
		}
	}

	class WooCommerce {}
	class WC_Order {
		public string $status = 'processing';
		public int $customer_id = 0;
		public bool $require_payment = false;
		public function get_id(): int { return 42; }
		public function get_order_key(): string { return 'test-order-key'; }
		public function get_status(): string { return $this->status; }
		public function has_status( $statuses ): bool { return in_array( $this->status, (array) $statuses, true ); }
		public function is_paid(): bool { return $this->has_status( array( 'processing', 'completed' ) ); }
		public function needs_payment(): bool { return $this->require_payment || $this->has_status( array( 'pending', 'failed' ) ); }
		public function get_user_id(): int { return $this->customer_id; }
		public function get_customer_id(): int { return $this->customer_id; }
		public function is_created_via( $context ): bool { return false; }
		public function get_date_created(): mixed { return null; }
		public function get_order_number(): string { return 'TEST-42'; }
		public function get_billing_email(): string { return 'buyer@example.test'; }
		public function get_formatted_order_total(): string { return '100 lei'; }
		public function get_payment_method_title(): string { return 'Card'; }
		public function get_payment_method(): string { return 'test_gateway'; }
		public function get_checkout_order_received_url(): string { return '/checkout/order-received/42/?key=test-order-key'; }
		public function get_checkout_payment_url(): string { return '/checkout/order-pay/42/?key=test-order-key'; }
	}

	require $source . '/includes/shortcodes/class-wc-shortcode-checkout.php';
	require __DIR__ . '/../includes/class-schrack-cart-checkout-renderer.php';
	$order = new WC_Order();
	$renderer = new Schrack_Cart_Checkout_Renderer();
	$render_content = new \ReflectionMethod( $renderer, 'render_order_received_content' );
	$settings = array( 'order_button_text' => 'Comanda', 'order_pay_button_text' => 'Plateste' );
	$_SERVER['HTTP_HOST'] = 'shop.example.test';
	add_filter( 'woocommerce_order_email_verification_required', static fn() => $GLOBALS['verify_email'] );

	function verify_confirmation( bool $condition, string $message ): void {
		++$GLOBALS['checks'];
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}
	function render_confirmation( string $endpoint, string $key = 'test-order-key' ): string {
		$GLOBALS['wp']->query_vars = array( 'pagename' => 'checkout', $endpoint => 42 );
		$_SERVER['REQUEST_URI'] = '/checkout/' . $endpoint . '/42/';
		$_GET = array( 'key' => $key );
		$GLOBALS['events'] = array();
		$before = $GLOBALS['wp']->query_vars;
		$result = $GLOBALS['render_content']->invoke( $GLOBALS['renderer'], $GLOBALS['settings'] );
		verify_confirmation( $before === $GLOBALS['wp']->query_vars, 'Rendering must restore all endpoint query variables.' );
		verify_confirmation( 'Original message' === apply_filters( 'woocommerce_thankyou_order_received_text', 'Original message', $GLOBALS['order'] ), 'Confirmation text filter must not leak into later rendering.' );
		return $result;
	}

	// Reproduce the original error with the unmodified native controller first.
	$wp->query_vars = array( 'order-pay' => 42 );
	$_GET = array( 'key' => 'test-order-key' );
	verify_confirmation( str_contains( do_shortcode( '[woocommerce_checkout]' ), 'cannot be paid for' ), 'The fixture must reproduce the native paid-order error.' );

	foreach ( array( 'processing', 'completed' ) as $status ) {
		$order->status = $status;
		foreach ( array( 'order-pay', 'order-received' ) as $endpoint ) {
			$html = render_confirmation( $endpoint );
			verify_confirmation( str_contains( $html, 'Plata a fost efectuată cu succes.' ) && ! str_contains( $html, 'cannot be paid for' ), $status . ': successful payments must show the Romanian confirmation.' );
			verify_confirmation( 1 === substr_count( $html, 'class="schrack-cart-checkout__order-summary"' ), 'The authorized order summary must appear exactly once.' );
			verify_confirmation( in_array( 'woocommerce_thankyou_test_gateway', $events, true ) && in_array( 'woocommerce_thankyou', $events, true ), 'Native gateway and order-detail hooks must run.' );
		}
		foreach ( array( 'cart_checkout', 'order_pay', 'order_received' ) as $mode ) {
			$wp->query_vars = array( 'order-pay' => 42 );
			$_SERVER['REQUEST_URI'] = '/checkout/order-pay/42/';
			$widget_html = $renderer->render( array( 'render_mode' => $mode, 'show_header' => 'no', 'show_continue_shopping' => 'no' ), 'test' );
			verify_confirmation( str_contains( $widget_html, 'is-order-received' ) && str_contains( $widget_html, 'Plata a fost efectuată cu succes.' ), $mode . ': every widget must show confirmation for a paid payment return.' );
		}
	}
	foreach ( array( 'pending', 'failed', 'on-hold', 'cancelled', 'refunded' ) as $status ) {
		$order->status = $status;
		$html = render_confirmation( 'order-pay' );
		verify_confirmation( ! str_contains( $html, 'Plata a fost efectuată cu succes.' ) && ! in_array( 'checkout/thankyou.php', $events, true ), $status . ': unpaid orders must retain their native payment behavior.' );
	}
	$order->status = 'failed';
	verify_confirmation( str_contains( render_confirmation( 'order-received' ), 'woocommerce-thankyou-order-failed' ), 'A failed confirmation must retain the native failure message.' );
	$order->status = 'processing';
	$order->require_payment = true;
	verify_confirmation( str_contains( render_confirmation( 'order-pay' ), 'gateway-payment' ), 'A custom payment requirement must not be replaced by success.' );
	$order->require_payment = false;
	foreach ( array( 'order-pay', 'order-received' ) as $endpoint ) {
		foreach ( array( 'wrong-key', '' ) as $key ) {
			$html = render_confirmation( $endpoint, $key );
			verify_confirmation( ! str_contains( $html, 'buyer@example.test' ) && ! str_contains( $html, 'Plata a fost efectuată cu succes.' ), 'Invalid or missing keys must not reveal order data or payment success.' );
		}
		$order->customer_id = 7;
		$html = render_confirmation( $endpoint );
		verify_confirmation( str_contains( $html, 'id="login"' ) && ! str_contains( $html, 'buyer@example.test' ) && ! str_contains( $html, 'Plata a fost efectuată cu succes.' ), 'Registered orders must retain WooCommerce login verification.' );
		$order->customer_id = 0;
		$verify_email = true;
		$html = render_confirmation( $endpoint );
		verify_confirmation( str_contains( $html, 'id="verify-email"' ) && ! str_contains( $html, 'buyer@example.test' ) && ! str_contains( $html, 'Plata a fost efectuată cu succes.' ), 'Guest orders must retain WooCommerce email verification.' );
		$verify_email = false;
	}

	$throw_on_shortcode = true;
	$wp->query_vars = array( 'order-pay' => 42 );
	$_GET = array( 'key' => 'test-order-key' );
	try {
		$render_content->invoke( $renderer, $settings );
		throw new \RuntimeException( 'Expected the simulated gateway failure.' );
	} catch ( \RuntimeException $error ) {
		verify_confirmation( 'Simulated gateway failure' === $error->getMessage(), 'The original failure must be preserved.' );
	}
	verify_confirmation( array( 'order-pay' => 42 ) === $wp->query_vars, 'Endpoint variables must be restored after an exception.' );
	verify_confirmation( 'Original message' === apply_filters( 'woocommerce_thankyou_order_received_text', 'Original message', $order ), 'The confirmation filter must be removed after an exception.' );
	echo "Checkout confirmation: {$checks} checks passed.\n";
}
