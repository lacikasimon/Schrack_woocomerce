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
		$raw    = $this->client->get_item_price( $sku );
		$prices = $this->extract_prices_by_sku( $raw, array( $sku ) );
		$sku    = $this->normalize_sku( $sku );
		$price  = '' !== $sku ? ( $prices[ $sku ] ?? null ) : null;

		if ( null === $price ) {
			$price = $this->extract_price( $raw );
		}

		if ( null === $price ) {
			$this->logger->warning( 'price', 'Schrack price response did not contain a parsable price.', $sku );
		}

		return array(
			'purchase_price' => $price,
			'raw'            => $raw,
		);
	}

	/**
	 * Fetches purchase prices for multiple SKUs.
	 *
	 * @param array<int,string> $skus Schrack item numbers.
	 * @return array{prices:array<string,float|null>,raw:mixed}
	 */
	public function fetch_prices( array $skus ): array {
		$skus = $this->normalize_skus( $skus );
		$raw  = $this->client->get_item_prices( $skus );

		return array(
			'prices' => $this->extract_prices_by_sku( $raw, $skus ),
			'raw'    => $raw,
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
		$stopped   = false;
		$product_skus = array();

		foreach ( $product_ids as $product_id ) {
			if ( $this->settings->is_stop_requested() ) {
				$stopped = true;
				break;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				++$processed;
				continue;
			}

			$sku = $this->product_schrack_sku( $product );

			if ( '' === $sku ) {
				++$processed;
				$this->logger->warning( 'price', 'Skipped price sync because product has no SKU.', null, array( 'product_id' => $product_id ) );
				continue;
			}

			$product_skus[ $product_id ] = $sku;
		}

		$request_size = max( 1, min( $limit, (int) $this->settings->get( 'price_request_size', 10 ) ) );

		foreach ( array_chunk( $product_skus, $request_size, true ) as $sku_by_product_id ) {
			if ( $this->settings->is_stop_requested() ) {
				$stopped = true;
				break;
			}

			try {
				$result = $this->fetch_prices( array_values( $sku_by_product_id ) );
				$prices = $result['prices'];

				foreach ( $sku_by_product_id as $product_id => $sku ) {
					$price = $prices[ $sku ] ?? null;

					if ( null === $price ) {
						$this->logger->warning( 'price', 'Schrack price response did not contain a price for SKU.', $sku, array( 'product_id' => $product_id ) );

						if ( 'yes' === $this->settings->get( 'skip_price_when_missing', 'yes' ) ) {
							++$processed;
						} else {
							++$errors;
						}

						continue;
					}

					$this->mapper->update_price( (int) $product_id, (float) $price );
					++$processed;
				}
			} catch ( Schrack_Rate_Limit_Exception $exception ) {
				throw $exception;
			} catch ( Throwable $exception ) {
				if ( Schrack_Soap_Client::is_rate_limit_message( $exception->getMessage() ) ) {
					throw new Schrack_Rate_Limit_Exception( $exception->getMessage(), 0, $exception );
				}

				$errors += count( $sku_by_product_id );
				$this->logger->error(
					'price',
					'Failed to sync Schrack price chunk.',
					null,
					array(
						'sku_count' => count( $sku_by_product_id ),
						'error'     => $exception->getMessage(),
					)
				);
			}

			if ( $this->settings->is_stop_requested() ) {
				$stopped = true;
				break;
			}
		}

		$batch_count     = $stopped ? min( count( $product_ids ), $processed + $errors ) : count( $product_ids );
		$next_cursor     = $batch['batch_start'] + $batch_count;
		$completed_cycle = ! $stopped && ( 0 === $batch['total_products'] || $next_cursor >= $batch['total_products'] );

		if ( $completed_cycle ) {
			$next_cursor = 0;
		}

		$result = array(
			'processed'       => $processed,
			'errors'          => $errors,
			'cursor'          => $next_cursor,
			'total_products'  => $batch['total_products'],
			'batch_start'     => $batch['batch_start'],
			'batch_count'     => $batch_count,
			'batch_limit'     => $limit,
			'completed_cycle' => $completed_cycle ? 'yes' : 'no',
		);

		if ( $stopped ) {
			$result['stopped'] = 'yes';
		}

		$this->settings->update_status( 'price', $result );

		return $result;
	}

	/**
	 * Extracts prices from a multi-item SOAP response and keeps them keyed by SKU.
	 *
	 * @param mixed             $response SOAP response.
	 * @param array<int,string> $skus Requested SKUs.
	 * @return array<string,float|null>
	 */
	private function extract_prices_by_sku( mixed $response, array $skus ): array {
		$skus   = $this->normalize_skus( $skus );
		$prices = array_fill_keys( $skus, null );

		$this->collect_price_items( $response, array_fill_keys( $skus, true ), $prices );

		if ( 1 === count( $skus ) && null === $prices[ $skus[0] ] ) {
			$prices[ $skus[0] ] = $this->extract_price( $response );
		}

		return $prices;
	}

	/**
	 * Recursively finds item nodes containing an item number and a price.
	 *
	 * @param mixed                    $node Node.
	 * @param array<string,bool>       $sku_lookup Requested SKU lookup.
	 * @param array<string,float|null> $prices Price map.
	 */
	private function collect_price_items( mixed $node, array $sku_lookup, array &$prices ): void {
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}

		if ( ! is_array( $node ) ) {
			return;
		}

		$sku = $this->sku_from_node( $node );

		if ( '' !== $sku && isset( $sku_lookup[ $sku ] ) ) {
			$price = $this->extract_item_price( $node );

			if ( null !== $price ) {
				$prices[ $sku ] = $price;
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$this->collect_price_items( $value, $sku_lookup, $prices );
			}
		}
	}

	/**
	 * Extracts the preferred item price from one item response node.
	 *
	 * @param array<string,mixed> $node Item node.
	 */
	private function extract_item_price( array $node ): ?float {
		foreach ( $node as $key => $value ) {
			if ( 'prices' === strtolower( (string) $key ) ) {
				$price = $this->extract_price( $value );

				if ( null !== $price ) {
					return $price;
				}
			}
		}

		return $this->extract_price( $node );
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
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
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

		return $this->normalize_sku( $sku );
	}

	/**
	 * Extracts a SKU-like value from a SOAP item node.
	 *
	 * @param array<string,mixed> $node Item node.
	 */
	private function sku_from_node( array $node ): string {
		$keys = array( 'itemid', 'item_id', 'id', 'itemno', 'item_no', 'itemnumber', 'item_number' );

		foreach ( $node as $key => $value ) {
			if ( is_scalar( $value ) && in_array( strtolower( (string) $key ), $keys, true ) ) {
				return $this->normalize_sku( $value );
			}
		}

		return '';
	}

	/**
	 * Normalizes SKU lists for response mapping.
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<int,string>
	 */
	private function normalize_skus( array $skus ): array {
		$normalized = array();

		foreach ( $skus as $sku ) {
			$sku = $this->normalize_sku( $sku );

			if ( '' !== $sku ) {
				$normalized[ $sku ] = $sku;
			}
		}

		return array_values( $normalized );
	}

	/**
	 * Normalizes one SKU.
	 */
	private function normalize_sku( mixed $sku ): string {
		if ( ! is_scalar( $sku ) ) {
			return '';
		}

		return sanitize_text_field( trim( (string) $sku ) );
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
