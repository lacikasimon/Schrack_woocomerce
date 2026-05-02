<?php
/**
 * Stock synchronization.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Stock_Sync {
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
	 * Fetches mapped stock data for a SKU.
	 *
	 * @return array{total_stock:float,warehouses:array<int,array<string,mixed>>,raw:mixed}
	 */
	public function fetch_stock( string $sku ): array {
		$raw           = $this->client->get_stock_item_quantities( $sku );
		$sku           = $this->normalize_sku( $sku );
		$mapped_by_sku = $this->map_stock_response_by_sku( $raw, array( $sku ) );
		$mapped        = '' !== $sku && isset( $mapped_by_sku[ $sku ] ) ? $mapped_by_sku[ $sku ] : $this->map_stock_response( $raw );
		$mapped['raw'] = $raw;

		return $mapped;
	}

	/**
	 * Fetches mapped stock data for multiple SKUs.
	 *
	 * @param array<int,string> $skus Schrack item numbers.
	 * @return array{stocks:array<string,array{total_stock:float,warehouses:array<int,array<string,mixed>>}>,raw:mixed}
	 */
	public function fetch_stocks( array $skus ): array {
		$skus = $this->normalize_skus( $skus );
		$raw  = $this->client->get_stock_item_quantities_bulk( $skus );

		return array(
			'stocks' => $this->map_stock_response_by_sku( $raw, $skus ),
			'raw'    => $raw,
		);
	}

	/**
	 * Syncs one product.
	 */
	public function sync_product( int $product_id ): ?float {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$sku = $this->product_schrack_sku( $product );

		if ( '' === $sku ) {
			$this->logger->warning( 'stock', 'Skipped stock sync because product has no SKU.', null, array( 'product_id' => $product_id ) );
			return null;
		}

		$stock = $this->fetch_stock( $sku );

		return $this->mapper->update_stock( $product_id, $stock );
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
		$product_skus = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				++$processed;
				continue;
			}

			$sku = $this->product_schrack_sku( $product );

			if ( '' === $sku ) {
				++$processed;
				$this->logger->warning( 'stock', 'Skipped stock sync because product has no SKU.', null, array( 'product_id' => $product_id ) );
				continue;
			}

			$product_skus[ $product_id ] = $sku;
		}

		$request_size = max( 1, min( $limit, (int) $this->settings->get( 'stock_request_size', 25 ) ) );

		foreach ( array_chunk( $product_skus, $request_size, true ) as $sku_by_product_id ) {
			try {
				$result = $this->fetch_stocks( array_values( $sku_by_product_id ) );
				$stocks = $result['stocks'];

				foreach ( $sku_by_product_id as $product_id => $sku ) {
					if ( ! isset( $stocks[ $sku ] ) ) {
						++$errors;
						$this->logger->warning( 'stock', 'Schrack stock response did not contain a mappable stock row for SKU.', $sku, array( 'product_id' => $product_id ) );
						continue;
					}

					$this->mapper->update_stock( (int) $product_id, $stocks[ $sku ] );
					++$processed;
				}
			} catch ( Schrack_Rate_Limit_Exception $exception ) {
				throw $exception;
			} catch ( Throwable $exception ) {
				$errors += count( $sku_by_product_id );
				$this->logger->error(
					'stock',
					'Failed to sync Schrack stock chunk.',
					null,
					array(
						'sku_count' => count( $sku_by_product_id ),
						'error'     => $exception->getMessage(),
					)
				);
			}
		}

		$batch_count     = count( $product_ids );
		$next_cursor     = $batch['batch_start'] + $batch_count;
		$completed_cycle = 0 === $batch['total_products'] || $next_cursor >= $batch['total_products'];

		if ( $completed_cycle ) {
			$next_cursor = 0;
		}

		$this->settings->update_status(
			'stock',
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
	 * Maps a multi-item stock response by item number.
	 *
	 * @param mixed             $response SOAP response.
	 * @param array<int,string> $skus Requested SKUs.
	 * @return array<string,array{total_stock:float,warehouses:array<int,array<string,mixed>>}>
	 */
	public function map_stock_response_by_sku( mixed $response, array $skus ): array {
		$skus   = $this->normalize_skus( $skus );
		$stocks = array();

		$this->collect_stock_items( $response, array_fill_keys( $skus, true ), $stocks );

		if ( 1 === count( $skus ) && ! isset( $stocks[ $skus[0] ] ) ) {
			$stocks[ $skus[0] ] = $this->map_stock_response( $response );
		}

		return $stocks;
	}

	/**
	 * Maps unknown Schrack stock response shapes to a flexible warehouse list.
	 *
	 * @return array{total_stock:float,warehouses:array<int,array<string,mixed>>}
	 */
	public function map_stock_response( mixed $response ): array {
		$warehouses = array();
		$this->collect_quantities( $response, $warehouses, array() );

		$source = (string) $this->settings->get( 'stock_source', 'all' );
		$filtered = array_filter(
			$warehouses,
			static function ( array $warehouse ) use ( $source ): bool {
				if ( 'all' === $source ) {
					return true;
				}

				return $source === $warehouse['source'];
			}
		);

		if ( empty( $filtered ) && 'all' !== $source ) {
			$filtered = array_filter(
				$warehouses,
				static function ( array $warehouse ): bool {
					return 'all' === $warehouse['source'];
				}
			);
		}

		$total = 0.0;

		foreach ( $filtered as $warehouse ) {
			$total += max( 0, (float) $warehouse['quantity'] );
		}

		return array(
			'total_stock' => $total,
			'warehouses'  => array_values( $filtered ),
		);
	}

	/**
	 * Recursively finds item nodes containing an item number and stock quantities.
	 *
	 * @param mixed                                                                 $node Node.
	 * @param array<string,bool>                                                    $sku_lookup Requested SKU lookup.
	 * @param array<string,array{total_stock:float,warehouses:array<int,array<string,mixed>>}> $stocks Stock map.
	 */
	private function collect_stock_items( mixed $node, array $sku_lookup, array &$stocks ): void {
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}

		if ( ! is_array( $node ) ) {
			return;
		}

		$sku = $this->sku_from_node( $node );

		if ( '' !== $sku && isset( $sku_lookup[ $sku ] ) ) {
			$stocks[ $sku ] = $this->map_stock_response( $node );
		}

		foreach ( $node as $key => $value ) {
			$key_sku = $this->normalize_sku( $key );

			if ( '' !== $key_sku && isset( $sku_lookup[ $key_sku ] ) ) {
				$stocks[ $key_sku ] = $this->map_stock_response( $value );
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				$this->collect_stock_items( $value, $sku_lookup, $stocks );
			}
		}
	}

	/**
	 * Recursively collects quantity-like fields.
	 *
	 * @param mixed                    $node Node.
	 * @param array<int,array<string,mixed>> $warehouses Warehouses.
	 * @param array<string,string>     $context Context.
	 */
	private function collect_quantities( mixed $node, array &$warehouses, array $context ): void {
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}

		if ( is_numeric( $node ) && empty( $context ) ) {
			$warehouses[] = array(
				'quantity' => max( 0, (float) $node ),
				'source'   => 'all',
				'label'    => '',
			);
			return;
		}

		if ( ! is_array( $node ) ) {
			return;
		}

		$local_context = $context;

		foreach ( $node as $key => $value ) {
			$key_string = strtolower( (string) $key );

			if ( is_scalar( $value ) && preg_match( '/(warehouse|store|location|branch|depot|stockid|stock_id|name|type|country)/', $key_string ) ) {
				$local_context[ $key_string ] = sanitize_text_field( (string) $value );
			}
		}

		foreach ( $node as $key => $value ) {
			$key_string = strtolower( (string) $key );

			if (
				is_scalar( $value ) &&
				preg_match( '/(availablequantity|available_qty|stockquantity|stock_qty|quantity|qty|available)/', $key_string ) &&
				$this->is_decimal_like( (string) $value )
			) {
				$label = implode( ' ', $local_context );
				if ( '' === $label ) {
					$label = $key_string;
				}
				$warehouses[] = array(
					'quantity' => max( 0, (float) str_replace( ',', '.', (string) $value ) ),
					'source'   => $this->classify_quantity_source( $key_string, $label ),
					'label'    => $label,
				);
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				$this->collect_quantities( $value, $warehouses, $local_context );
			}
		}
	}

	/**
	 * Classifies stock source from quantity field names first, then labels.
	 */
	private function classify_quantity_source( string $quantity_key, string $label ): string {
		if ( str_contains( $quantity_key, 'pickup' ) ) {
			return 'store';
		}

		if ( str_contains( $quantity_key, 'delivery' ) ) {
			return 'central';
		}

		return $this->classify_source( $label );
	}

	/**
	 * Classifies stock source labels.
	 */
	private function classify_source( string $label ): string {
		$label = strtolower( $label );

		if ( str_contains( $label, 'store' ) || str_contains( $label, 'branch' ) || str_contains( $label, 'customer' ) || str_contains( $label, 'filial' ) ) {
			return 'store';
		}

		if ( str_contains( $label, 'central' ) || preg_match( '/\b(cz|at)\b/', $label ) || str_contains( $label, 'warehouse' ) || str_contains( $label, 'depot' ) ) {
			return 'central';
		}

		return 'all';
	}

	/**
	 * Returns the stored cursor for stock batch work.
	 */
	private function batch_cursor(): int {
		$status = $this->settings->get_status();
		$row    = isset( $status['stock'] ) && is_array( $status['stock'] ) ? $status['stock'] : array();

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
}
