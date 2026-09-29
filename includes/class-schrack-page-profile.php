<?php
/** Short-lived, administrator initiated anonymous PHP measurements. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_Page_Profile {
	private static bool $cold_selections = false;
	/** Only a valid one-use administrator ticket can request a cold selection test. */
	public static function cold_selections(): bool { return self::$cold_selections; }
	/** No profiling work on ordinary visits, including cache-warming requests. */
	public static function maybe_start(): void {
		$key = $_SERVER['HTTP_X_SCHRACK_PROFILE'] ?? '';
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) { return; }
		$id = hash( 'sha256', $key );
		$ticket = get_transient( 'schrack_profile_ticket_' . $id );
		if ( ! is_array( $ticket ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET'
			|| ! hash_equals( $ticket['uri'], (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ) { return; }
		delete_transient( 'schrack_profile_ticket_' . $id );
		self::$cold_selections = ! empty( $ticket['cold_selections'] );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Private administrator performance measurement' );
		add_action( 'send_headers', 'nocache_headers' );
		global $wpdb;
		$original = $wpdb->save_queries;
		$offset = count( $wpdb->queries ?? array() );
		if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); }
		$wpdb->save_queries = true;
		$start = (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) );
		$marks = array();
		$callbacks = array();
		$documents = array();
		$widgets = array();
		$widget_starts = array();
		add_action( 'elementor/frontend/widget/before_render', static function ( $widget ) use ( &$widget_starts ): void {
			$widget_starts[ spl_object_id( $widget ) ] = microtime( true );
		}, PHP_INT_MIN );
		add_action( 'elementor/frontend/widget/after_render', static function ( $widget ) use ( &$widget_starts, &$widgets ): void {
			$key = spl_object_id( $widget );
			if ( ! isset( $widget_starts[ $key ] ) ) { return; }
			$name = get_class( $widget );
			if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $name ) ) { $name = 'Widget'; }
			$widgets[ $name ] = ( $widgets[ $name ] ?? 0 ) + ( microtime( true ) - $widget_starts[ $key ] ) * 1000;
			unset( $widget_starts[ $key ] );
		}, PHP_INT_MAX );
		add_action( 'elementor/post/render', static function ( $id ) use ( &$documents ): void { $documents[ (int) $id ] = true; }, -100 );
		$mark = static function ( string $name ) use ( &$marks, $start ): void {
			global $wpdb;
			$marks[] = array( 'phase' => $name, 'ms' => round( ( microtime( true ) - $start ) * 1000, 1 ), 'queries' => (int) $wpdb->num_queries );
		};
		$mark( 'plugin_file' );
		foreach ( array( 'plugins_loaded', 'init', 'wp', 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'wp_footer' ) as $hook ) {
			add_action( $hook, static function () use ( $mark, $hook ): void { $mark( $hook ); }, PHP_INT_MAX );
		}
		add_action( 'wp_head', static function () use ( $mark ): void { $mark( 'wp_head_start' ); }, PHP_INT_MIN );
		// Wrap only this authorized measurement request; preserve callback IDs/priorities.
		add_action( 'wp', static function () use ( &$callbacks ): void {
			global $wp_filter;
			foreach ( array( 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'shutdown', 'elementor/post/render', 'elementor/css-file/post/enqueue', 'elementor/css-file/before_enqueue', 'elementor/css-file/after_enqueue', 'woocommerce_get_price_html', 'woocommerce_product_get_image' ) as $hook ) {
				if ( empty( $wp_filter[ $hook ]->callbacks ) ) { continue; }
				foreach ( $wp_filter[ $hook ]->callbacks as &$priority ) {
					foreach ( $priority as &$entry ) {
						$fn = $entry['function'];
						$name = is_string( $fn ) ? $fn : ( is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1] : 'Closure' );
						if ( ! preg_match( '/^[A-Za-z0-9_\\\\:]+$/D', $name ) ) { $name = 'Callback'; }
						$entry['function'] = static function ( ...$args ) use ( $fn, $name, $hook, &$callbacks ) {
							$start = microtime( true );
							try { return call_user_func_array( $fn, $args ); }
							finally { $label = $hook . ': ' . $name; $callbacks[ $label ] = ( $callbacks[ $label ] ?? 0 ) + ( microtime( true ) - $start ) * 1000; }
						};
					}
					unset( $entry );
				}
				unset( $priority );
			}
		}, PHP_INT_MAX );
		add_action( 'shutdown', static function () use ( $id, $mark, &$marks, &$callbacks, &$documents, &$widgets, $offset, $original ): void {
			global $wpdb;
			$mark( 'shutdown' );
			$queries = array_slice( $wpdb->queries ?? array(), $offset );
			$db_ms = 0.0;
			$groups = array();
			foreach ( $queries as $query ) {
				$ms = (float) ( $query[1] ?? 0 ) * 1000;
				$db_ms += $ms;
				// Store only a PHP caller name and aggregate durations; never SQL or arguments.
				$caller = 'WordPress';
				foreach ( explode( ', ', (string) ( $query[2] ?? '' ) ) as $part ) {
					if ( ! str_starts_with( $part, 'wpdb' ) && preg_match( '/^[A-Za-z0-9_\\\\]+(?:::|->)[A-Za-z0-9_]+$/D', $part ) ) {
						if ( 'WordPress' === $caller || str_starts_with( $part, 'Schrack_' ) ) { $caller = $part; }
					}
				}
				$groups[ $caller ] = ( $groups[ $caller ] ?? 0 ) + $ms;
			}
			arsort( $groups );
			arsort( $callbacks );
			arsort( $widgets );
			$wpdb->save_queries = $original;
			foreach ( $documents as $post_id => &$details ) {
				$css = get_post_meta( $post_id, '_elementor_css', true );
				$assets = get_post_meta( $post_id, '_elementor_page_assets', true );
				$details = array( 'css_status' => $css['status'] ?? '', 'dynamic_elements' => count( $css['dynamic_elements_ids'] ?? array() ), 'assets_saved' => is_array( $assets ), 'asset_groups' => is_array( $assets ) ? count( $assets ) : 0 );
			}
			unset( $details );
			set_transient( 'schrack_profile_result_' . $id, array(
				'phases' => $marks, 'measured_db_ms' => SAVEQUERIES ? round( $db_ms, 1 ) : null,
				'sql_timing_available' => (bool) SAVEQUERIES,
				'measured_queries' => count( $queries ), 'memory_mb' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
				'db_callers_ms' => array_map( static fn( $ms ) => round( $ms, 1 ), array_slice( $groups, 0, 8, true ) ),
				'callbacks_ms' => array_map( static fn( $ms ) => round( $ms, 1 ), array_slice( $callbacks, 0, 15, true ) ),
				'elementor_documents' => $documents,
				'widgets_ms' => array_map( static fn( $ms ) => round( $ms, 1 ), array_slice( $widgets, 0, 15, true ) ),
			), 300 );
		}, PHP_INT_MAX );
	}

	/** Caller must first authorize the administrator and validate the public URL. */
	public static function measure( string $url, bool $cold_selections = false ): array {
		$key = bin2hex( random_bytes( 32 ) );
		$id = hash( 'sha256', $key );
		$url = add_query_arg( 'schrack_perf_probe', wp_generate_uuid4(), $url );
		$parts = wp_parse_url( $url );
		set_transient( 'schrack_profile_ticket_' . $id, array( 'uri' => ( $parts['path'] ?? '/' ) . '?' . $parts['query'], 'cold_selections' => $cold_selections ), 60 );
		$started = microtime( true );
		$curl = null;
		$capture = static function ( $handle, $args, $request_url ) use ( &$curl, $url ): void {
			if ( $request_url === $url && $handle instanceof \CurlHandle ) { $curl = $handle; }
		};
		add_action( 'http_api_curl', $capture, 10, 3 );
		try {
			$response = wp_safe_remote_get( $url, array(
				'timeout' => 25, 'redirection' => 0, 'cookies' => array(), 'limit_response_size' => 2097152,
				'headers' => array( 'X-Schrack-Profile' => $key ),
			) );
		} finally { remove_action( 'http_api_curl', $capture, 10 ); }
		// PHP 8 retains cURL info while we hold the handle; other transports report null.
		$ttfb = $curl && function_exists( 'curl_getinfo' ) ? curl_getinfo( $curl, CURLINFO_STARTTRANSFER_TIME ) : 0;
		$result = get_transient( 'schrack_profile_result_' . $id );
		delete_transient( 'schrack_profile_ticket_' . $id );
		delete_transient( 'schrack_profile_result_' . $id );
		return array(
			'http_status' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
			'total_ms' => round( ( microtime( true ) - $started ) * 1000, 1 ),
			'ttfb_ms' => $ttfb > 0 ? round( $ttfb * 1000, 1 ) : null,
			'profile' => is_array( $result ) ? $result : null,
			'cold_selections' => $cold_selections,
			'note' => 'TTFB este timpul până la primul octet, măsurat de pe server prin cURL (null dacă nu este disponibil). Durata totală include descărcarea. SQL este măsurat după încărcarea modulului, cu un mic cost de instrumentare.',
		);
	}
}
