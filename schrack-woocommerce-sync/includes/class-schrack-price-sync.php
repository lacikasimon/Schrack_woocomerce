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

		$sku = $this->product_schrack_sku( $product );

		if ( '' === $sku ) {
			$this->logger->warning( 'price', 'Skipped price sync because product has no SKU.', null, array( 'product_id' => $product_id ) );
			return null;
		}

		$result = $this->fetch_price( $sku );

		if ( null === $result['purchase_price'] ) {
			if ( 'yes' === $this->settings->get( 'skip_price_when_missing', 'yes' ) ) {
				return null;
			}

			throw new RuntimeException( 'Schrack price response did not contain a parsable price.' );
		}

		return $this->mapper->update_price( $product_id, (float) $result['purchase_price'] );
	}

	/**
	 * Syncs a batch of Schrack products.
	 *
	 * @return array<string,mixed>
	 */
	public function sync_batch( int $limit ): array {
		$limit       = max( 1, $limit );
		$batch       = $this->get_schrack_product_batch( $limit, $this->batch_cursor() );
		$product_ids = $batch['product_ids'];

		if ( empty( $product_ids ) && $batch['batch_start'] > 0 ) {
			$batch       = $this->get_schrack_product_batch( $limit, 0 );
			$product_ids = $batch['product_ids'];
		}

		$processed = 0;
		$errors    = 0;

		foreach ( $product_ids as $product_id ) {
			try {
				$this->sync_product( $product_id );
				++$processed;
			} catch ( Throwable $exception ) {
				++$errors;
				$this->logger->error( 'price', 'Failed to sync Schrack price.', null, array( 'product_id' => $product_id, 'error' => $exception->getMessage() ) );
			}
		}

		$batch_count     = count( $product_ids );
		$next_cursor     = $batch['batch_start'] + $batch_count;
		$completed_cycle = 0 === $batch['total_products'] || $next_cursor >= $batch['total_products'];

		if ( $completed_cycle ) {
			$next_cursor = 0;
		}

		$this->settings->update_status(
			'price',
			array(
				'processed'       => $processed,
				'errors'          => $errors,
				'cursor'          => $next_cursor,
				'total_products'  => $batch['total_products'],
				'batch_start'     => $batch['batch_start'],
				'batch_count'     => $batch_count,
				'batch_limit'     => $limit,
				'completed_cycle' => $completed_cycle ? 'yes' : 'no',
			)
		);

		return array(
			'processed'       => $processed,
			'errors'          => $errors,
			'cursor'          => $next_cursor,
			'total_products'  => $batch['total_products'],
			'batch_start'     => $batch['batch_start'],
			'batch_count'     => $batch_count,
			'batch_limit'     => $limit,
			'completed_cycle' => $completed_cycle ? 'yes' : 'no',
		);
	}

	/**
	 * Extracts a decimal price from unknown SOAP response shapes.
	 */
	public function extract_price( mixed $response ): ?float {
		if ( is_numeric( $response ) ) {
			return $this->normalize_price( (string) $response );
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
				return $this->normalize_price( (string) $value );
			}

			$nested = $this->extract_price( $value );

			if ( null !== $nested ) {
				return $nested;
			}
		}

		return null;
	}

	/**
	 * Returns the stored cursor for price batch work.
	 */
	private function batch_cursor(): int {
		$status = $this->settings->get_status();
		$row    = isset( $status['price'] ) && is_array( $status['price'] ) ? $status['price'] : array();

		return absint( $row['cursor'] ?? 0 );
	}

	/**
	 * Returns a stable batch of Schrack product IDs.
	 *
	 * @return array{product_ids:array<int,int>,total_products:int,batch_start:int}
	 */
	private function get_schrack_product_batch( int $limit, int $offset ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => max( 1, $limit ),
				'offset'         => max( 0, $offset ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_schrack_item_number',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array(
			'product_ids'    => array_map( 'absint', $query->posts ),
			'total_products' => (int) $query->found_posts,
			'batch_start'    => max( 0, $offset ),
		);
	}

	/**
	 * Returns the Schrack item number for a product, preferring the stored import meta.
	 */
	private function product_schrack_sku( WC_Product $product ): string {
		$meta_sku = $product->get_meta( '_schrack_item_number', true );
		$sku      = is_scalar( $meta_sku ) && '' !== trim( (string) $meta_sku ) ? (string) $meta_sku : $product->get_sku();

		return sanitize_text_field( $sku );
	}

	/**
	 * Checks a decimal-looking string.
	 */
	private function is_decimal_like( string $value ): bool {
		return (bool) preg_match( '/^-?\d+(?:[,.]\d+)?$/', trim( $value ) );
	}

	/**
	 * Parses Schrack decimal strings and rejects negative WooCommerce prices.
	 */
	private function normalize_price( string $value ): ?float {
		$price = (float) str_replace( ',', '.', trim( $value ) );

		return $price >= 0 ? $price : null;
	}
}
