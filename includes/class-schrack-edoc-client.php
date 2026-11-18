<?php
/** eDoc bridge protocol v1. No SOAP order methods are involved. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Schrack_EDoc_Exception extends RuntimeException {}

final class Schrack_EDoc_Client {
	public const MAX_BYTES = 2097152;
	public const MAX_PDF_BYTES = 5242880;
	public const OPTION = 'schrack_edoc_connection';
	private ?array $connection;
	public function __construct( ?array $connection = null ) { $this->connection = $connection; }
	public static function identity( array $config ): string { return hash( 'sha256', implode( "\n", array( $config['url'], $config['key_id'], $config['secret'] ) ) ); }

	public static function safe_error( Throwable $error ): string { return $error instanceof Schrack_EDoc_Exception ? $error->getMessage() : 'Sincronizarea eDoc a eșuat. Reîncercați sau verificați configurarea.'; }

	public static function config( bool $fresh = false ): array {
		if ( $fresh && function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); }
		return wp_parse_args( (array) get_option( self::OPTION, array() ), array( 'enabled' => false, 'url' => '', 'key_id' => '', 'secret' => '', 'tax_map' => array(), 'entity_id' => 0 ) );
	}

	public static function enabled(): bool {
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.2', '<' ) ) { return false; }
		$c = self::config();
		return ! empty( $c['enabled'] ) && '' !== $c['key_id'] && '' !== $c['secret'];
	}

	public static function local_allowed(): bool {
		return defined( 'EDOC_ALLOW_INSECURE_LOCAL' ) && true === EDOC_ALLOW_INSECURE_LOCAL && function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/** Validate the configured origin, including WordPress installed in a subdirectory. */
	public static function valid_url( string $url ): bool {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['fragment'] ) || isset( $p['query'] ) ) { return false; }
		if ( 'https' === ( $p['scheme'] ?? '' ) ) { return true; }
		if ( 'http' !== ( $p['scheme'] ?? '' ) || ! self::local_allowed() ) { return false; }
		$host = strtolower( $p['host'] );
		return 'localhost' === $host || '127.0.0.1' === $host || '[::1]' === $host || ! str_contains( $host, '.' ) || str_ends_with( $host, '.local' );
	}

	public static function canonical( string $method, string $route, array $query, string $key, string $timestamp, string $nonce, string $body ): string {
		foreach ( $query as $value ) { if ( ! is_scalar( $value ) ) { throw new InvalidArgumentException( 'Invalid query.' ); } }
		ksort( $query, SORT_STRING );
		return implode( "\n", array( strtoupper( $method ), $route, http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), $key, $timestamp, $nonce, hash( 'sha256', $body ) ) );
	}

	/** Bounded requests with no redirect forwarding or credential/body logging. */
	public function request( string $method, string $route, array $query = array(), ?array $payload = null ): array {
		$c = $this->connection ?? self::config();
		if ( ! self::valid_url( (string) $c['url'] ) || '' === $c['key_id'] || '' === $c['secret'] ) { throw new Schrack_EDoc_Exception( 'Conexiunea eDoc nu este configurată corect.' ); }
		if ( ! str_starts_with( $route, '/api/webshop/v1/' ) ) { throw new InvalidArgumentException( 'Invalid eDoc route.' ); }
		$body = null === $payload ? '' : wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BYTES ) { throw new Schrack_EDoc_Exception( 'Mesajul depășește limita de 2 MiB.' ); }
		$timestamp = (string) time();
		$nonce = wp_generate_uuid4();
		$signature = hash_hmac( 'sha256', self::canonical( $method, $route, $query, $c['key_id'], $timestamp, $nonce, $body ), $c['secret'] );
		$url = rtrim( $c['url'], '/' ) . $route;
		if ( $query ) { $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ); }
		$args = array( 'method' => $method, 'body' => $body, 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => self::MAX_BYTES + 1, 'headers' => array( 'Content-Type' => 'application/json', 'X-EDoc-Key' => $c['key_id'], 'X-EDoc-Timestamp' => $timestamp, 'X-EDoc-Nonce' => $nonce, 'X-EDoc-Signature' => $signature ) );
		$result = self::local_allowed() ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $result ) ) { throw new Schrack_EDoc_Exception( 'Conexiunea eDoc a eșuat; sincronizarea va fi reluată.' ); }
		$status = wp_remote_retrieve_response_code( $result );
		$raw = wp_remote_retrieve_body( $result );
		if ( strlen( $raw ) > self::MAX_BYTES ) { throw new Schrack_EDoc_Exception( 'Răspunsul eDoc depășește limita de 2 MiB.' ); }
		$data = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['ok'] ) ) { throw new Schrack_EDoc_Exception( 'eDoc a respins sincronizarea (HTTP ' . (int) $status . ').' ); }
		return $data;
	}

	/**
	 * Authenticated, bounded PDF transfer. Never forward credentials through redirects.
	 * @return array{body:string,filename:string}
	 */
	public function download( string $route ): array {
		if ( ! preg_match( '#^/api/webshop/v1/invoices/[1-9][0-9]*/pdf$#D', $route ) ) { throw new Schrack_EDoc_Exception( 'Adresa facturii eDoc nu este validă.' ); }
		$c = $this->connection ?? self::config();
		if ( ! self::valid_url( (string) $c['url'] ) || '' === $c['key_id'] || '' === $c['secret'] ) { throw new Schrack_EDoc_Exception( 'Conexiunea eDoc nu este configurată corect.' ); }
		$timestamp = (string) time(); $nonce = wp_generate_uuid4();
		$signature = hash_hmac( 'sha256', self::canonical( 'GET', $route, array(), $c['key_id'], $timestamp, $nonce, '' ), $c['secret'] );
		$args = array( 'method' => 'GET', 'body' => '', 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => self::MAX_PDF_BYTES + 1, 'headers' => array( 'Accept' => 'application/pdf', 'X-EDoc-Key' => $c['key_id'], 'X-EDoc-Timestamp' => $timestamp, 'X-EDoc-Nonce' => $nonce, 'X-EDoc-Signature' => $signature ) );
		$url = rtrim( $c['url'], '/' ) . $route;
		$result = self::local_allowed() ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $result ) ) { throw new Schrack_EDoc_Exception( 'Factura eDoc nu poate fi descărcată momentan. Reîncercați mai târziu.' ); }
		$raw = wp_remote_retrieve_body( $result );
		if ( strlen( $raw ) > self::MAX_PDF_BYTES ) { throw new Schrack_EDoc_Exception( 'Factura depășește limita de 5 MiB.' ); }
		$type = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $result, 'content-type' ) )[0] ) );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $result ) || 'application/pdf' !== $type || ! str_starts_with( $raw, '%PDF-' ) ) { throw new Schrack_EDoc_Exception( 'Factura eDoc nu este disponibilă momentan. Reîncercați mai târziu.' ); }
		$disposition = (string) wp_remote_retrieve_header( $result, 'content-disposition' );
		$filename = 'factura-edoc.pdf';
		if ( preg_match( '/filename\*=UTF-8\'\'([^;\r\n]+)/i', $disposition, $match ) ) { $filename = rawurldecode( $match[1] ); }
		elseif ( preg_match( '/filename="([^"\r\n]+)"|filename=([^;\s\r\n]+)/i', $disposition, $match ) ) { $filename = $match[1] ?: $match[2]; }
		$filename = sanitize_file_name( basename( str_replace( '\\', '/', $filename ) ) );
		// Restrict the response header to ASCII and a PDF extension, even on non-ASCII ERP names.
		$filename = preg_replace( '/[^A-Za-z0-9._-]/', '_', $filename );
		if ( '' === $filename || ! preg_match( '/\.pdf$/iD', $filename ) ) { $filename = 'factura-edoc.pdf'; }
		if ( strlen( $filename ) > 180 ) { $filename = substr( $filename, 0, 176 ) . '.pdf'; }
		return array( 'body' => $raw, 'filename' => $filename );
	}
}
