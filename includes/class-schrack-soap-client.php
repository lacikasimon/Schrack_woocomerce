<?php
/**
 * Schrack SOAP client wrapper.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Rate_Limit_Exception extends RuntimeException {}

class Schrack_Soap_Client {
	/**
	 * Settings service.
	 *
	 * @var Schrack_Settings
	 */
	private Schrack_Settings $settings;

	/**
	 * Logger service.
	 *
	 * @var Schrack_Logger
	 */
	private Schrack_Logger $logger;

	/**
	 * Native SOAP client.
	 *
	 * @var SoapClient|null
	 */
	private ?SoapClient $client = null;

	/**
	 * WSDL URL that was successfully loaded.
	 *
	 * @var string
	 */
	private string $loaded_wsdl_url = '';

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Tests the WSDL connection by listing functions.
	 *
	 * @return array<int,string>
	 */
	public function get_functions(): array {
		$functions = $this->get_client()->__getFunctions();

		return is_array( $functions ) ? $functions : array();
	}

	/**
	 * Lists WSDL types.
	 *
	 * @return array<int,string>
	 */
	public function get_types(): array {
		$types = $this->get_client()->__getTypes();

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Returns the WSDL URL used by the native SOAP client.
	 */
	public function get_loaded_wsdl_url(): string {
		return $this->loaded_wsdl_url;
	}

	/**
	 * Downloads catalog content.
	 *
	 * @param string $format CSV, XML, or DATANORM.
	 * @return mixed
	 */
	public function get_catalog_as( string $format = 'CSV' ): mixed {
		$format = strtoupper( sanitize_key( $format ) );
		$payload = array_merge(
			$this->auth_payload(),
			array(
				'ResultType' => 'download',
			)
		);

		if ( 'CSV' === $format ) {
			$payload['Delimiter'] = ';';
		}

		return $this->call_catalog_methods( $this->catalog_methods_for_format( $format ), $payload, $format );
	}

	/**
	 * Fetches the current purchase price for one SKU.
	 *
	 * @param string $sku Schrack item number.
	 * @return mixed
	 */
	public function get_item_price( string $sku ): mixed {
		return $this->get_item_prices( array( $sku ) );
	}

	/**
	 * Fetches current purchase prices for multiple SKUs in one SOAP message.
	 *
	 * @param array<int,string> $skus Schrack item numbers.
	 * @return mixed
	 */
	public function get_item_prices( array $skus ): mixed {
		$items = array();

		foreach ( $this->normalize_skus( $skus ) as $sku ) {
			$items[] = array(
				'ID'       => $sku,
				'Quantity' => 1,
			);
		}

		if ( empty( $items ) ) {
			throw new InvalidArgumentException( 'At least one Schrack SKU is required for price lookup.' );
		}

		$payload = array_merge(
			$this->auth_payload(),
			array(
				'CustomerNumber' => (string) $this->settings->get( 'customer_number', '' ),
				'Items'          => array(
					'Item' => $items,
				),
			)
		);

		return $this->call( 'GetItemPriceV31', $payload );
	}

	/**
	 * Fetches stock quantities for one SKU.
	 *
	 * @param string $sku Schrack item number.
	 * @return mixed
	 */
	public function get_stock_item_quantities( string $sku ): mixed {
		return $this->get_stock_item_quantities_bulk( array( $sku ) );
	}

	/**
	 * Fetches stock quantities for multiple SKUs in one SOAP message.
	 *
	 * @param array<int,string> $skus Schrack item numbers.
	 * @return mixed
	 */
	public function get_stock_item_quantities_bulk( array $skus ): mixed {
		$item_ids = $this->normalize_skus( $skus );

		if ( empty( $item_ids ) ) {
			throw new InvalidArgumentException( 'At least one Schrack SKU is required for stock lookup.' );
		}

		$payload = array_merge(
			$this->auth_payload(),
			array(
				'ItemIDs' => array(
					'ItemID' => $item_ids,
				),
			)
		);

		return $this->call( 'GetStockItemQuantitiesV40', $payload );
	}

	/**
	 * Executes a SOAP call with retry and secret-safe logging.
	 *
	 * @param string              $method SOAP method.
	 * @param array<string,mixed> $payload Request payload.
	 * @return mixed
	 *
	 * @throws RuntimeException When the method is forbidden.
	 * @throws SoapFault When SOAP fails after retries.
	 */
	public function call( string $method, array $payload, ?int $retries_override = null ): mixed {
		$this->guard_forbidden_method( $method );

		$payload = apply_filters( 'schrack_wc_sync_soap_payload_' . $method, $payload, $method );
		$retries = null === $retries_override ? (int) $this->settings->get( 'soap_retries', 2 ) : $retries_override;
		$retries = max( 0, $retries );
		$attempt = 0;
		$last_exception = null;

		while ( $attempt <= $retries ) {
			++$attempt;

			try {
				$client = $this->get_client();

				$this->logger->debug(
					'soap',
					'Calling Schrack SOAP method.',
					null,
					array(
						'method'            => $method,
						'attempt'           => $attempt,
						'soap_endpoint_url' => (string) $this->settings->get( 'soap_endpoint_url' ),
						'loaded_wsdl_url'   => $this->loaded_wsdl_url,
						'payload'           => $this->redact_payload( $payload ),
					)
				);

				$result = $client->__soapCall( $method, array( $payload ) );

				$sleep = max( 0, (int) $this->settings->get( 'rate_limit_sleep', 0 ) );
				if ( $sleep > 0 ) {
					sleep( $sleep );
				}

				return $result;
			} catch ( SoapFault $exception ) {
				$last_exception = $exception;
				$error_message  = $this->safe_error_message( $exception->getMessage() );

				$this->logger->warning(
					'soap',
					'Schrack SOAP call failed.',
					null,
					array(
						'method'            => $method,
						'attempt'           => $attempt,
						'soap_endpoint_url' => (string) $this->settings->get( 'soap_endpoint_url' ),
						'loaded_wsdl_url'   => $this->loaded_wsdl_url,
						'error'             => $error_message,
					)
				);

				if ( $this->is_rate_limit_message( $error_message ) ) {
					throw new Schrack_Rate_Limit_Exception( $error_message, 0, $exception );
				}

				if ( $attempt <= $retries ) {
					sleep( min( 3, $attempt ) );
				}
			}
		}

		throw $last_exception;
	}

	/**
	 * Calls the first working catalog method for the requested format.
	 *
	 * @param array<int,string>    $methods Ordered SOAP method fallbacks.
	 * @param array<string,mixed>  $payload Request payload.
	 * @throws Throwable When all catalog method variants fail.
	 */
	private function call_catalog_methods( array $methods, array $payload, string $format ): mixed {
		$last_exception = null;
		$method_count   = count( $methods );
		$index          = 0;

		foreach ( $methods as $method ) {
			++$index;
			$retries = 1 === $index ? null : min( 1, max( 0, (int) $this->settings->get( 'soap_retries', 2 ) ) );

			try {
				return $this->call( $method, $payload, $retries );
			} catch ( Schrack_Rate_Limit_Exception $exception ) {
				throw $exception;
			} catch ( Throwable $exception ) {
				$last_exception = $exception;

				if ( $index >= $method_count ) {
					break;
				}

				$this->logger->warning(
					'soap',
					'Schrack catalog SOAP method failed; trying another catalog method.',
					null,
					array(
						'format'       => $format,
						'method'       => $method,
						'next_method'  => $methods[ $index ] ?? '',
						'endpoint_url' => (string) $this->settings->get( 'soap_endpoint_url' ),
						'error'        => $this->safe_error_message( $exception->getMessage() ),
					)
				);
			}
		}

		if ( $last_exception instanceof Throwable ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: catalog format, 2: SOAP methods, 3: last error. */
					__( 'Schrack %1$s catalog SOAP call failed after trying methods: %2$s. Last error: %3$s', 'schrack-woocommerce-sync' ),
					$format,
					implode( ', ', $methods ),
					$this->safe_error_message( $last_exception->getMessage() )
				),
				0,
				$last_exception
			);
		}

		throw new RuntimeException( 'No Schrack catalog SOAP method is configured.' );
	}

	/**
	 * Returns ordered catalog method fallbacks for a format.
	 *
	 * @return array<int,string>
	 */
	private function catalog_methods_for_format( string $format ): array {
		if ( 'XML' === $format ) {
			return array( 'GetCatalogAsXMLV32', 'GetCatalogAsXMLV31', 'GetCatalogAsXMLV30' );
		}

		return array( 'GetCatalogAsCsvV34', 'GetCatalogAsCsvV33', 'GetCatalogAsCsvV32', 'GetCatalogAsCsvV31', 'GetCatalogAsCsvV30' );
	}

	/**
	 * Builds the native SOAP client.
	 *
	 * @throws RuntimeException When SOAP extension is missing.
	 */
	private function get_client(): SoapClient {
		if ( ! extension_loaded( 'soap' ) ) {
			throw new RuntimeException( 'PHP SOAP extension is not available.' );
		}

		if ( $this->client instanceof SoapClient ) {
			return $this->client;
		}

		$wsdl     = (string) $this->settings->get( 'wsdl_url' );
		$endpoint = (string) $this->settings->get( 'soap_endpoint_url' );

		$options = array(
			'exceptions'         => true,
			'trace'              => 'yes' === $this->settings->get( 'debug_enabled', 'no' ),
			'cache_wsdl'         => WSDL_CACHE_DISK,
			'connection_timeout' => 30,
			'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
		);

		if ( '' !== $endpoint ) {
			$options['location'] = $endpoint;
		}

		$this->client = $this->create_client_with_fallback( $wsdl, $options );

		return $this->client;
	}

	/**
	 * Creates the native SOAP client and falls back when the TEST WSDL is down.
	 *
	 * The service endpoint remains controlled by the SoapClient location option.
	 *
	 * @param string              $wsdl Primary WSDL URL.
	 * @param array<string,mixed> $options SoapClient options.
	 * @throws Throwable When the primary and fallback WSDL fail.
	 */
	private function create_client_with_fallback( string $wsdl, array $options ): SoapClient {
		try {
			$client                = new SoapClient( $wsdl, $options );
			$this->loaded_wsdl_url = $wsdl;

			return $client;
		} catch ( Throwable $exception ) {
			$fallback_wsdl = $this->fallback_wsdl_url( $wsdl );

			if ( '' === $fallback_wsdl ) {
				throw $exception;
			}

			$this->logger->warning(
				'soap',
				'Primary Schrack WSDL failed; trying fallback WSDL.',
				null,
				array(
					'primary_wsdl'  => $wsdl,
					'fallback_wsdl' => $fallback_wsdl,
					'error'         => $this->safe_error_message( $exception->getMessage() ),
				)
			);

			try {
				$client                = new SoapClient( $fallback_wsdl, $options );
				$this->loaded_wsdl_url = $fallback_wsdl;

				return $client;
			} catch ( Throwable $fallback_exception ) {
				$this->logger->error(
					'soap',
					'Fallback Schrack WSDL failed.',
					null,
					array(
						'primary_wsdl'  => $wsdl,
						'fallback_wsdl' => $fallback_wsdl,
						'primary_error'  => $this->safe_error_message( $exception->getMessage() ),
						'fallback_error' => $this->safe_error_message( $fallback_exception->getMessage() ),
					)
				);

				throw new RuntimeException(
					sprintf(
						/* translators: 1: primary WSDL error, 2: fallback WSDL error. */
						__( 'Could not load Schrack WSDL. Primary error: %1$s Fallback error: %2$s', 'schrack-woocommerce-sync' ),
						$this->safe_error_message( $exception->getMessage() ),
						$this->safe_error_message( $fallback_exception->getMessage() )
					),
					0,
					$fallback_exception
				);
			}
		}
	}

	/**
	 * Returns a fallback WSDL URL for known Schrack TEST WSDL outages.
	 */
	private function fallback_wsdl_url( string $wsdl ): string {
		if (
			'test' === $this->settings->get( 'environment', 'test' ) &&
			Schrack_Settings::DEFAULT_TEST_WSDL === $wsdl
		) {
			return Schrack_Settings::DEFAULT_LIVE_WSDL;
		}

		return '';
	}

	/**
	 * Authentication fields used by all allowed Schrack requests.
	 *
	 * @return array<string,mixed>
	 */
	private function auth_payload(): array {
		return array(
			'Provider'       => array(
				'Code'    => (string) $this->settings->get( 'provider_code', '' ),
				'Version' => SCHRACK_WC_SYNC_VERSION,
			),
			'Authentication' => array(
				'User'     => (string) $this->settings->get( 'webshop_username', '' ),
				'Password' => (string) $this->settings->get( 'webshop_password', '' ),
			),
		);
	}

	/**
	 * Normalizes and deduplicates Schrack SKU lists before SOAP calls.
	 *
	 * @param array<int,string> $skus Raw SKUs.
	 * @return array<int,string>
	 */
	private function normalize_skus( array $skus ): array {
		$normalized = array();

		foreach ( $skus as $sku ) {
			if ( ! is_scalar( $sku ) ) {
				continue;
			}

			$sku = sanitize_text_field( trim( (string) $sku ) );

			if ( '' === $sku ) {
				continue;
			}

			$normalized[ $sku ] = $sku;
		}

		return array_values( $normalized );
	}

	/**
	 * Detects Schrack-side throttling messages.
	 */
	private function is_rate_limit_message( string $message ): bool {
		$message = strtolower( $message );

		return str_contains( $message, 'to many messages' )
			|| str_contains( $message, 'too many messages' )
			|| str_contains( $message, 'messages in period' )
			|| str_contains( $message, 'rate limit' )
			|| str_contains( $message, 'throttl' );
	}

	/**
	 * Blocks order sending or order mutation methods.
	 */
	private function guard_forbidden_method( string $method ): void {
		$normalized = strtolower( $method );

		if ( str_contains( $normalized, 'order' ) ) {
			$this->logger->error( 'soap', 'Blocked forbidden Schrack SOAP order method.', null, array( 'method' => $method ) );
			throw new RuntimeException( 'Order-related SOAP methods are forbidden in Schrack WooCommerce Sync.' );
		}
	}

	/**
	 * Redacts credentials from payloads before filters/logging.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>
	 */
	private function redact_payload( array $payload ): array {
		foreach ( $payload as $key => $value ) {
			$key_string = strtolower( (string) $key );

			if (
				str_contains( $key_string, 'password' ) ||
				str_contains( $key_string, 'provider' ) ||
				str_contains( $key_string, 'auth' )
			) {
				$payload[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$payload[ $key ] = $this->redact_payload( $value );
			}
		}

		return $payload;
	}

	/**
	 * Redacts secret-looking strings in error messages.
	 */
	private function safe_error_message( string $message ): string {
		$message = preg_replace( '/(password|providerCode|provider_code|provider)\s*[:=]\s*[^,\s}]+/i', '$1=[redacted]', $message );

		return null === $message ? 'SOAP error.' : $message;
	}
}
