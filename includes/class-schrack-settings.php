<?php
/**
 * Settings storage and sanitization.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Settings {
	public const OPTION_NAME          = 'schrack_wc_sync_settings';
	public const STATUS_OPTION_NAME   = 'schrack_wc_sync_status';
	public const MARKUPS_OPTION_NAME  = 'schrack_wc_sync_category_markups';
	public const STOP_TRANSIENT_NAME  = 'schrack_wc_sync_stop_requested';
	public const SOAP_PAUSE_TRANSIENT_NAME = 'schrack_wc_sync_soap_paused';
	public const DEFAULT_TEST_WSDL    = 'https://ws-test.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice?wsdl';
	public const DEFAULT_TEST_URL     = 'https://ws-test.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice';
	public const DEFAULT_LIVE_WSDL    = 'https://ws.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice?wsdl';
	public const DEFAULT_LIVE_URL     = 'https://ws.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice';
	public const DEFAULT_DATANORM_URL = 'https://www.schrack.cz/eshop/datanorm/';

	/**
	 * Installs default options.
	 */
	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults(), '', false );
		}

		if ( false === get_option( self::STATUS_OPTION_NAME, false ) ) {
			add_option( self::STATUS_OPTION_NAME, array(), '', false );
		}

		if ( false === get_option( self::MARKUPS_OPTION_NAME, false ) ) {
			add_option( self::MARKUPS_OPTION_NAME, array(), '', false );
		}
	}

	/**
	 * Returns default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'environment'            => 'test',
			'soap_endpoint_url'      => self::DEFAULT_TEST_URL,
			'wsdl_url'               => self::DEFAULT_TEST_WSDL,
			'datanorm_url'           => self::DEFAULT_DATANORM_URL,
			'customer_number'        => '',
			'webshop_username'       => '',
			'webshop_password'       => '',
			'provider_code'          => '',
			'default_markup'         => 20,
			'catalog_batch_size'     => 500,
			'catalog_batches_per_run'=> 3,
			'sync_batch_size'        => 100,
			'sync_batches_per_run'   => 1,
			'price_request_size'     => 100,
			'stock_request_size'     => 100,
			'rate_limit_sleep'       => 0,
			'soap_rate_limit_cooldown'=> 600,
			'soap_retries'           => 2,
			'price_sync_frequency'   => 'daily',
			'stock_sync_frequency'   => 'hourly',
			'catalog_sync_frequency' => 'daily',
			'import_mode'            => 'catalog_price_stock',
			'publish_status'         => 'draft',
			'image_import_enabled'   => 'yes',
			'image_batch_size'       => 50,
			'image_parallel_workers' => 2,
			'image_parallel_followup_delay' => 10,
			'image_download_timeout' => 15,
			'image_retry_cooldown'   => HOUR_IN_SECONDS,
			'stock_handling_enabled' => 'yes',
			'delete_missing_products'=> 'no',
			'stock_source'           => 'all',
			'support_widget_enabled' => 'no',
			'log_level'              => 'info',
			'debug_enabled'          => 'no',
			'skip_price_when_missing'=> 'yes',
		);
	}

	/**
	 * Returns all settings with defaults merged.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$options = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, self::defaults() );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default fallback.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$options = $this->all();

		return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
	}

	/**
	 * Updates settings from admin input.
	 *
	 * @param array<string,mixed> $input Unsanitized input.
	 */
	public function update( array $input ): void {
		$current = $this->all();
		$clean   = $this->sanitize( $input, $current );

		update_option( self::OPTION_NAME, $clean, false );
	}

	/**
	 * Sanitizes settings.
	 *
	 * @param array<string,mixed> $input Unsanitized input.
	 * @param array<string,mixed> $current Current settings.
	 * @return array<string,mixed>
	 */
	public function sanitize( array $input, array $current = array() ): array {
		$current = wp_parse_args( $current, self::defaults() );

		$environment = isset( $input['environment'] ) && 'live' === sanitize_key( wp_unslash( (string) $input['environment'] ) ) ? 'live' : 'test';

		$endpoint = isset( $input['soap_endpoint_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $input['soap_endpoint_url'] ) ) ) : '';
		$wsdl     = isset( $input['wsdl_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $input['wsdl_url'] ) ) ) : '';

		if ( '' === $endpoint ) {
			$endpoint = 'live' === $environment ? self::DEFAULT_LIVE_URL : self::DEFAULT_TEST_URL;
		}

		if ( '' === $wsdl ) {
			$wsdl = 'live' === $environment ? self::DEFAULT_LIVE_WSDL : self::DEFAULT_TEST_WSDL;
		}

		if ( 'live' === $environment && self::DEFAULT_TEST_URL === $endpoint ) {
			$endpoint = self::DEFAULT_LIVE_URL;
		}

		if ( 'test' === $environment && self::DEFAULT_LIVE_URL === $endpoint ) {
			$endpoint = self::DEFAULT_TEST_URL;
		}

		if ( 'live' === $environment && self::DEFAULT_TEST_WSDL === $wsdl ) {
			$wsdl = self::DEFAULT_LIVE_WSDL;
		}

		if ( 'test' === $environment && self::DEFAULT_LIVE_WSDL === $wsdl ) {
			$wsdl = self::DEFAULT_TEST_WSDL;
		}

		$password = isset( $input['webshop_password'] ) ? wp_unslash( (string) $input['webshop_password'] ) : '';
		$provider = isset( $input['provider_code'] ) ? wp_unslash( (string) $input['provider_code'] ) : '';

		return array(
			'environment'             => $environment,
			'soap_endpoint_url'       => $endpoint,
			'wsdl_url'                => $wsdl,
			'datanorm_url'            => isset( $input['datanorm_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $input['datanorm_url'] ) ) ) : self::DEFAULT_DATANORM_URL,
			'customer_number'         => isset( $input['customer_number'] ) ? sanitize_text_field( wp_unslash( (string) $input['customer_number'] ) ) : '',
			'webshop_username'        => isset( $input['webshop_username'] ) ? sanitize_text_field( wp_unslash( (string) $input['webshop_username'] ) ) : '',
			'webshop_password'        => '' !== $password ? sanitize_text_field( $password ) : (string) $current['webshop_password'],
			'provider_code'           => '' !== $provider ? sanitize_text_field( $provider ) : (string) $current['provider_code'],
			'default_markup'          => $this->sanitize_float( $input['default_markup'] ?? 20, 0, 500 ),
			'catalog_batch_size'      => max( 1, min( 1000, absint( $input['catalog_batch_size'] ?? ( $current['catalog_batch_size'] ?? 500 ) ) ) ),
			'catalog_batches_per_run' => max( 1, min( 5, absint( $input['catalog_batches_per_run'] ?? ( $current['catalog_batches_per_run'] ?? 1 ) ) ) ),
			'sync_batch_size'         => max( 1, min( 500, absint( $input['sync_batch_size'] ?? ( $current['sync_batch_size'] ?? 100 ) ) ) ),
			'sync_batches_per_run'    => max( 1, min( 5, absint( $input['sync_batches_per_run'] ?? ( $current['sync_batches_per_run'] ?? 1 ) ) ) ),
			'price_request_size'      => max( 1, min( 100, absint( $input['price_request_size'] ?? ( $current['price_request_size'] ?? 100 ) ) ) ),
			'stock_request_size'      => max( 1, min( 100, absint( $input['stock_request_size'] ?? ( $current['stock_request_size'] ?? 100 ) ) ) ),
			'rate_limit_sleep'        => max( 0, min( 30, absint( $input['rate_limit_sleep'] ?? 0 ) ) ),
			'soap_rate_limit_cooldown'=> max( 300, min( 3600, absint( $input['soap_rate_limit_cooldown'] ?? ( $current['soap_rate_limit_cooldown'] ?? 600 ) ) ) ),
			'soap_retries'            => max( 0, min( 5, absint( $input['soap_retries'] ?? 2 ) ) ),
			'price_sync_frequency'    => $this->sanitize_frequency( $input['price_sync_frequency'] ?? 'daily', array( 'hourly', 'six_hours', 'daily' ), 'daily' ),
			'stock_sync_frequency'    => $this->sanitize_frequency( $input['stock_sync_frequency'] ?? 'hourly', array( 'thirty_minutes', 'hourly' ), 'hourly' ),
			'catalog_sync_frequency'  => $this->sanitize_frequency( $input['catalog_sync_frequency'] ?? 'daily', array( 'daily', 'weekly' ), 'daily' ),
			'import_mode'             => $this->sanitize_choice( $input['import_mode'] ?? 'catalog_price_stock', array( 'catalog_only', 'catalog_price', 'catalog_price_stock' ), 'catalog_price_stock' ),
			'publish_status'          => $this->sanitize_choice( $input['publish_status'] ?? 'draft', array( 'draft', 'publish' ), 'draft' ),
			'image_import_enabled'    => isset( $input['image_import_enabled'] ) ? 'yes' : 'no',
			'image_batch_size'        => max( 1, min( 250, absint( $input['image_batch_size'] ?? ( $current['image_batch_size'] ?? 50 ) ) ) ),
			'image_parallel_workers'  => max( 1, min( 8, absint( $input['image_parallel_workers'] ?? ( $current['image_parallel_workers'] ?? 2 ) ) ) ),
			'image_parallel_followup_delay' => max( 5, min( 300, absint( $input['image_parallel_followup_delay'] ?? ( $current['image_parallel_followup_delay'] ?? 10 ) ) ) ),
			'image_download_timeout'  => max( 5, min( 60, absint( $input['image_download_timeout'] ?? ( $current['image_download_timeout'] ?? 15 ) ) ) ),
			'image_retry_cooldown'    => max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, absint( $input['image_retry_cooldown'] ?? ( $current['image_retry_cooldown'] ?? HOUR_IN_SECONDS ) ) ) ),
			'stock_handling_enabled'  => isset( $input['stock_handling_enabled'] ) ? 'yes' : 'no',
			'delete_missing_products' => isset( $input['delete_missing_products'] ) ? 'yes' : 'no',
			'stock_source'            => $this->sanitize_choice( $input['stock_source'] ?? 'all', array( 'central', 'store', 'all' ), 'all' ),
			'support_widget_enabled'  => isset( $input['support_widget_enabled'] ) ? 'yes' : 'no',
			'log_level'               => $this->sanitize_choice( $input['log_level'] ?? 'info', array( 'debug', 'info', 'warning', 'error' ), 'info' ),
			'debug_enabled'           => isset( $input['debug_enabled'] ) ? 'yes' : 'no',
			'skip_price_when_missing' => isset( $input['skip_price_when_missing'] ) ? 'yes' : 'no',
		);
	}

	/**
	 * Updates a status bucket.
	 *
	 * @param string              $key Status key.
	 * @param array<string,mixed> $data Status data.
	 */
	public function update_status( string $key, array $data ): void {
		$status = get_option( self::STATUS_OPTION_NAME, array() );

		if ( ! is_array( $status ) ) {
			$status = array();
		}

		$status[ $key ] = array_merge(
			array(
				'last_run'  => current_time( 'mysql' ),
				'processed' => 0,
				'errors'    => 0,
			),
			$data
		);

		update_option( self::STATUS_OPTION_NAME, $status, false );
	}

	/**
	 * Returns all status data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status(): array {
		$status = get_option( self::STATUS_OPTION_NAME, array() );

		return is_array( $status ) ? $status : array();
	}

	/**
	 * Requests all running/queued syncs to stop at their next safe checkpoint.
	 *
	 * @return array<string,mixed>
	 */
	public function request_stop( int $ttl = 5 * MINUTE_IN_SECONDS ): array {
		$ttl     = max( MINUTE_IN_SECONDS, $ttl );
		$expires = time() + $ttl;
		$request = array(
			'requested_at'        => current_time( 'mysql' ),
			'requested_timestamp' => time(),
			'expires_at'          => $expires,
		);

		set_transient( self::STOP_TRANSIENT_NAME, $request, $ttl );

		return $request;
	}

	/**
	 * Clears a previously requested sync stop.
	 */
	public function clear_stop_request(): void {
		delete_transient( self::STOP_TRANSIENT_NAME );
	}

	/**
	 * Returns the active stop request, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function stop_request(): ?array {
		$request = get_transient( self::STOP_TRANSIENT_NAME );

		return is_array( $request ) ? $request : null;
	}

	/**
	 * Returns whether sync work should stop.
	 */
	public function is_stop_requested(): bool {
		return null !== $this->stop_request();
	}

	/**
	 * Pauses all Schrack SOAP calls after a server-side throttling response.
	 *
	 * @return array<string,mixed>
	 */
	public function pause_soap( int $ttl, string $method, string $error ): array {
		$ttl   = max( MINUTE_IN_SECONDS, $ttl );
		$until = time() + $ttl;
		$pause = array(
			'method'       => sanitize_text_field( $method ),
			'error'        => sanitize_text_field( $error ),
			'paused_at'    => current_time( 'mysql' ),
			'paused_until' => $until,
			'ttl'          => $ttl,
		);

		set_transient( self::SOAP_PAUSE_TRANSIENT_NAME, $pause, $ttl );

		return $pause;
	}

	/**
	 * Clears the Schrack SOAP pause.
	 */
	public function clear_soap_pause(): void {
		delete_transient( self::SOAP_PAUSE_TRANSIENT_NAME );
	}

	/**
	 * Returns the active Schrack SOAP pause, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function soap_pause(): ?array {
		$pause = get_transient( self::SOAP_PAUSE_TRANSIENT_NAME );

		if ( ! is_array( $pause ) ) {
			return null;
		}

		if ( absint( $pause['paused_until'] ?? 0 ) <= time() ) {
			$this->clear_soap_pause();
			return null;
		}

		return $pause;
	}

	/**
	 * Returns Action Scheduler interval seconds.
	 */
	public function frequency_to_seconds( string $frequency ): int {
		return match ( $frequency ) {
			'thirty_minutes' => 30 * MINUTE_IN_SECONDS,
			'hourly'         => HOUR_IN_SECONDS,
			'six_hours'      => 6 * HOUR_IN_SECONDS,
			'weekly'         => WEEK_IN_SECONDS,
			default          => DAY_IN_SECONDS,
		};
	}

	/**
	 * Sanitizes a float in range.
	 */
	private function sanitize_float( mixed $value, float $min, float $max ): float {
		$value = is_string( $value ) ? str_replace( ',', '.', $value ) : $value;
		$float = (float) $value;

		return max( $min, min( $max, $float ) );
	}

	/**
	 * Sanitizes an enum choice.
	 *
	 * @param mixed        $value Value.
	 * @param array<int,string> $allowed Allowed values.
	 * @param string       $default Default.
	 */
	private function sanitize_choice( mixed $value, array $allowed, string $default ): string {
		$value = sanitize_key( (string) $value );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Sanitizes a frequency choice.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $allowed Allowed values.
	 * @param string            $default Default.
	 */
	private function sanitize_frequency( mixed $value, array $allowed, string $default ): string {
		return $this->sanitize_choice( $value, $allowed, $default );
	}
}
