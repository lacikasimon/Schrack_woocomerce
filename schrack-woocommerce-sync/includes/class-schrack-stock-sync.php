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
		$raw    = $this->client->get_stock_item_quantities( $sku );
		$mapped = $this->map_stock_response( $raw );
		$mapped['raw'] = $raw;

		return $mapped;
	}

	/**
	 * Syncs one product.
	 */
	public function sync_product( int $product_id ): ?float {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$sku = $product->get_sku();

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
				$this->logger->error( 'stock', 'Failed to sync Schrack stock.', null, array( 'product_id' => $product_id, 'error' => $exception->getMessage() ) );
			}
		}

		$this->settings->update_status(
			'stock',
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
