<?php
/**
 * Plugin logger.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Logger {
	/**
	 * Settings service.
	 *
	 * @var Schrack_Settings|null
	 */
	private ?Schrack_Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( ?Schrack_Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Creates the log table.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(20) NOT NULL,
			operation varchar(40) NOT NULL,
			sku varchar(100) DEFAULT NULL,
			message text NOT NULL,
			context longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY operation (operation),
			KEY sku (sku),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Returns log table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'schrack_sync_logs';
	}

	/**
	 * Logs an info message.
	 *
	 * @param string              $operation Operation.
	 * @param string              $message Message.
	 * @param string|null         $sku SKU.
	 * @param array<string,mixed> $context Context.
	 */
	public function info( string $operation, string $message, ?string $sku = null, array $context = array() ): void {
		$this->log( 'info', $operation, $message, $sku, $context );
	}

	/**
	 * Logs a warning message.
	 *
	 * @param string              $operation Operation.
	 * @param string              $message Message.
	 * @param string|null         $sku SKU.
	 * @param array<string,mixed> $context Context.
	 */
	public function warning( string $operation, string $message, ?string $sku = null, array $context = array() ): void {
		$this->log( 'warning', $operation, $message, $sku, $context );
	}

	/**
	 * Logs an error message.
	 *
	 * @param string              $operation Operation.
	 * @param string              $message Message.
	 * @param string|null         $sku SKU.
	 * @param array<string,mixed> $context Context.
	 */
	public function error( string $operation, string $message, ?string $sku = null, array $context = array() ): void {
		$this->log( 'error', $operation, $message, $sku, $context );
	}

	/**
	 * Logs a debug message.
	 *
	 * @param string              $operation Operation.
	 * @param string              $message Message.
	 * @param string|null         $sku SKU.
	 * @param array<string,mixed> $context Context.
	 */
	public function debug( string $operation, string $message, ?string $sku = null, array $context = array() ): void {
		$this->log( 'debug', $operation, $message, $sku, $context );
	}

	/**
	 * Writes a log row.
	 *
	 * @param string              $level Log level.
	 * @param string              $operation Operation.
	 * @param string              $message Message.
	 * @param string|null         $sku SKU.
	 * @param array<string,mixed> $context Context.
	 */
	public function log( string $level, string $operation, string $message, ?string $sku = null, array $context = array() ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		global $wpdb;

		$context = $this->redact( $context );

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql' ),
				'level'      => sanitize_key( $level ),
				'operation'  => sanitize_key( $operation ),
				'sku'        => null !== $sku ? sanitize_text_field( $sku ) : null,
				'message'    => sanitize_textarea_field( $this->redact_string( $message ) ),
				'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Gets log rows.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return array<int,object>
	 */
	public function get_logs( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'level'     => '',
				'operation' => '',
				'sku'       => '',
				'limit'     => 100,
				'offset'    => 0,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = sanitize_key( (string) $args['level'] );
		}

		if ( '' !== $args['operation'] ) {
			$where[]  = 'operation = %s';
			$params[] = sanitize_key( (string) $args['operation'] );
		}

		if ( '' !== $args['sku'] ) {
			$where[]  = 'sku = %s';
			$params[] = sanitize_text_field( (string) $args['sku'] );
		}

		$limit  = max( 1, min( 500, absint( $args['limit'] ) ) );
		$offset = max( 0, absint( $args['offset'] ) );

		$sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Deletes all logs.
	 */
	public function delete_all(): void {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}

	/**
	 * Decides whether a level should be written.
	 */
	private function should_log( string $level ): bool {
		$levels = array(
			'debug'   => 0,
			'info'    => 1,
			'warning' => 2,
			'error'   => 3,
		);

		$current = $this->settings instanceof Schrack_Settings ? (string) $this->settings->get( 'log_level', 'info' ) : 'info';

		return ( $levels[ $level ] ?? 1 ) >= ( $levels[ $current ] ?? 1 );
	}

	/**
	 * Redacts sensitive values from arrays.
	 *
	 * @param mixed $value Value to redact.
	 * @return mixed
	 */
	private function redact( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$clean = array();

			foreach ( $value as $key => $item ) {
				$key_string = strtolower( (string) $key );

				if (
					str_contains( $key_string, 'password' ) ||
					str_contains( $key_string, 'provider' ) ||
					str_contains( $key_string, 'credential' ) ||
					str_contains( $key_string, 'auth' )
				) {
					$clean[ $key ] = '[redacted]';
					continue;
				}

				$clean[ $key ] = $this->redact( $item );
			}

			return $clean;
		}

		if ( is_string( $value ) ) {
			return $this->redact_string( $value );
		}

		return $value;
	}

	/**
	 * Redacts obvious secret fragments from strings.
	 */
	private function redact_string( string $value ): string {
		$value = preg_replace( '/(password|providerCode|provider_code|provider)\s*[:=]\s*[^,\s}]+/i', '$1=[redacted]', $value );

		return null === $value ? '' : $value;
	}
}
