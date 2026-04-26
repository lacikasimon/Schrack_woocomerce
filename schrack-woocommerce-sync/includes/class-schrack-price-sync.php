<?php
/**
 * Price synchronization.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Price_Sync {
	/**
	 * Settings.
	 *
	 * @var Schrack_Settings
	 */
	private Schrack_Settings $settings;

	/**
	 * Logger.
	 *
	 * @var Schrack_Logger
	 */
	private Schrack_Logger $logger;

	/**
	 * SOAP client.
	 *
	 * @var Schrack_Soap_Client
	 */
	private Schrack_Soap_Client $client;

	/**
	 * Product mapper.
	 *
	 * @var Schrack_Product_Mapper
	 */
	private Schrack_Product_Mapper $mapper;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, ?Schrack_Soap_Client $client = null, ?Schrack_Product_Mapper $mapper = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->client   = $client ?: new Schrack_Soap_Client( $settings, $logger );
		$this->mapper   = $mapper ?: new Schrack_Product_Mapper( $settings, $logger );
	}

	/**
	 * Fetches a purchase price for a SKU.
	 *
	 * @return array{purchase_price:float|null,raw:mixed}
	 */
	public function fetch_price( string $sku ): array {
		$raw   = $this->client->get_item_price( $sku );
		$price = $this->extract_price( $raw );

		if ( null === $price ) {
			$this->logger->warning( 'price', 'Schrack price response did not contain a parsable price.', $sku );
		}

		return array(
			'purchase_price' => $price,
			'raw'            => $raw,
		);
	}

	/**
	 * Syncs one WooCommerce product price.
	 */
	public function sync_product( int $product_id ): ?float {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$sku = $product->get_sku();

		if ( '' === $sku ) {
			$this->logger->warning( 'price', 'Skipped price sync because product has no SKU.', null, array( 'product_id' => $product_id ) );
			return null;
		}

		$result = $this->fetch_price( $sku );

		if ( null === $result['purchase_price'] ) {
			if ( 'yes' === $this->settings->get( 'skip_price_when_missing', 'yes' ) ) {
				return null;
			}

			return null;
		}

		return $this->mapper->update_price( $product_id, (float) $result['purchase_price'] );
	}

	/**
	 * Syncs a batch of Schrack products.
	 *
	 * @return array<string,int>
	 */
	public function sync_batch( int $limit ): array {
		$product_ids = $this->get_schrack_product_ids( $limit );
		$processed   = 0;
		$errors      = 0;

		foreach ( $product_ids as $product_id ) {
			try {
				$this->sync_product( $product_id );
				++$processed;
			} catch ( Throwable $exception ) {
				++$errors;
				$this->logger->error( 'price', 'Failed to sync Schrack price.', null, array( 'product_id' => $product_id, 'error' => $exception->getMessage() ) );
			}
		}

		$this->settings->update_status(
			'price',
			array(
				'processed' => $processed,
				'errors'    => $errors,
			)
		);

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Extracts a decimal price from unknown SOAP response shapes.
	 */
	public function extract_price( mixed $response ): ?float {
		if ( is_numeric( $response ) ) {
			return (float) $response;
		}

		if ( is_object( $response ) ) {
			$response = get_object_vars( $response );
		}

		if ( ! is_array( $response ) ) {
			return null;
		}

		foreach ( $response as $key => $value ) {
			$key_string = strtolower( (string) $key );

			if (
				is_scalar( $value ) &&
				preg_match( '/(price|net|amount|value)/', $key_string ) &&
				$this->is_decimal_like( (string) $value )
			) {
				return (float) str_replace( ',', '.', (string) $value );
			}

			$nested = $this->extract_price( $value );

			if ( null !== $nested ) {
				return $nested;
			}
		}

		return null;
	}

	/**
	 * Returns Schrack product IDs for batch work.
	 *
	 * @return array<int,int>
	 */
	private function get_schrack_product_ids( int $limit ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => max( 1, $limit ),
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_schrack_item_number',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Checks a decimal-looking string.
	 */
	private function is_decimal_like( string $value ): bool {
		return (bool) preg_match( '/^-?\d+(?:[,.]\d+)?$/', trim( $value ) );
	}
}
