<?php
/**
 * Schrack SOAP client wrapper.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		$method = 'XML' === $format ? 'GetCatalogAsXMLV32' : 'GetCatalogAsCsvV33';
		$payload = array_merge(
			$this->auth_payload(),
			array(
				'ResultType' => 'download',
			)
		);

		if ( 'CSV' === $format ) {
			$payload['Delimiter'] = ';';
		}

		return $this->call( $method, $payload );
	}

	/**
	 * Fetches the current purchase price for one SKU.
	 *
	 * @param string $sku Schrack item number.
	 * @return mixed
	 */
	public function get_item_price( string $sku ): mixed {
		$payload = array_merge(
			$this->auth_payload(),
			array(
				'CustomerNumber' => (string) $this->settings->get( 'customer_number', '' ),
				'Items'          => array(
					'Item' => array(
						array(
							'ID'       => sanitize_text_field( $sku ),
							'Quantity' => 1,
						),
					),
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
		$payload = array_merge(
			$this->auth_payload(),
			array(
				'ItemIDs' => array(
					'ItemID' => array( sanitize_text_field( $sku ) ),
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
	public function call( string $method, array $payload ): mixed {
		$this->guard_forbidden_method( $method );

		$payload = apply_filters( 'schrack_wc_sync_soap_payload_' . $method, $payload, $method );
		$retries = max( 0, (int) $this->settings->get( 'soap_retries', 2 ) );
		$attempt = 0;
		$last_exception = null;

		while ( $attempt <= $retries ) {
			++$attempt;

			try {
				$this->logger->debug(
					'soap',
					'Calling Schrack SOAP method.',
					null,
					array(
						'method'  => $method,
						'attempt' => $attempt,
						'payload' => $this->redact_payload( $payload ),
					)
				);

				$result = $this->get_client()->__soapCall( $method, array( $payload ) );

				$sleep = max( 0, (int) $this->settings->get( 'rate_limit_sleep', 0 ) );
				if ( $sleep > 0 ) {
					sleep( $sleep );
				}

				return $result;
			} catch ( SoapFault $exception ) {
				$last_exception = $exception;

				$this->logger->warning(
					'soap',
					'Schrack SOAP call failed.',
					null,
					array(
						'method'  => $method,
						'attempt' => $attempt,
						'error'   => $this->safe_error_message( $exception->getMessage() ),
					)
				);

				if ( $attempt <= $retries ) {
					sleep( min( 3, $attempt ) );
				}
			}
		}

		throw $last_exception;
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
