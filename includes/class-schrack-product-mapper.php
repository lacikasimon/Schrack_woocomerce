<?php
/**
 * Maps Schrack data to WooCommerce products.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Product_Mapper {
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
	 * Category markup service.
	 *
	 * @var Schrack_Category_Markup
	 */
	private Schrack_Category_Markup $markup;

	/**
	 * Per-request SKU to product ID cache.
	 *
	 * @var array<string,int>
	 */
	private array $sku_product_id_cache = array();

	/**
	 * Per-request category lookup cache.
	 *
	 * @var array<string,int>
	 */
	private array $category_term_cache = array();

	/**
	 * Per-request category path assignment cache.
	 *
	 * @var array<string,array<int,int>>
	 */
	private array $category_path_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, ?Schrack_Category_Markup $markup = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->markup   = $markup ?: new Schrack_Category_Markup( $settings );
	}

	/**
	 * Finds a product by SKU.
	 */
	public function find_product_by_sku( string $sku ): int {
		$sku = sanitize_text_field( $sku );

		if ( '' === $sku ) {
			return 0;
		}

		if ( array_key_exists( $sku, $this->sku_product_id_cache ) ) {
			return $this->sku_product_id_cache[ $sku ];
		}

		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			$this->sku_product_id_cache[ $sku ] = 0;
			return 0;
		}

		$product_id = (int) wc_get_product_id_by_sku( $sku );

		$this->sku_product_id_cache[ $sku ] = $product_id;

		return $product_id;
	}

	/**
	 * Primes product IDs for a catalog batch so each row does not run its own SKU query.
	 *
	 * @param array<int,string> $skus Product SKUs.
	 */
	public function prime_product_ids_by_skus( array $skus ): void {
		global $wpdb;

		$skus = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $sku ): string => is_scalar( $sku ) ? sanitize_text_field( trim( (string) $sku ) ) : '',
						$skus
					)
				)
			)
		);

		$missing = array();

		foreach ( $skus as $sku ) {
			if ( ! array_key_exists( $sku, $this->sku_product_id_cache ) ) {
				$missing[] = $sku;
			}
		}

		if ( empty( $missing ) ) {
			return;
		}

		foreach ( array_chunk( $missing, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = "
				SELECT sku_meta.meta_value AS sku, sku_meta.post_id AS product_id
				FROM {$wpdb->postmeta} AS sku_meta
				INNER JOIN {$wpdb->posts} AS products
					ON products.ID = sku_meta.post_id
				WHERE sku_meta.meta_key = '_sku'
					AND sku_meta.meta_value IN ({$placeholders})
					AND products.post_type IN ('product', 'product_variation')
					AND products.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY sku_meta.post_id ASC
			";
			$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$sku        = isset( $row['sku'] ) ? sanitize_text_field( (string) $row['sku'] ) : '';
					$product_id = absint( $row['product_id'] ?? 0 );

					if ( '' !== $sku && $product_id > 0 && ! array_key_exists( $sku, $this->sku_product_id_cache ) ) {
						$this->sku_product_id_cache[ $sku ] = $product_id;
					}
				}
			}

			foreach ( $chunk as $sku ) {
				if ( ! array_key_exists( $sku, $this->sku_product_id_cache ) ) {
					$this->sku_product_id_cache[ $sku ] = 0;
				}
			}
		}
	}

	/**
	 * Reads Schrack SKUs for a product batch without loading WooCommerce product objects.
	 *
	 * @param array<int,int> $product_ids Product IDs.
	 * @return array<int,string>
	 */
	public function schrack_skus_by_product_ids( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );

		if ( empty( $product_ids ) ) {
			return array();
		}

		$skus = array();

		foreach ( array_chunk( $product_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$sql          = "
				SELECT products.ID AS product_id,
					MAX(CASE WHEN meta.meta_key = '_schrack_item_number' THEN meta.meta_value ELSE '' END) AS schrack_sku,
					MAX(CASE WHEN meta.meta_key = '_sku' THEN meta.meta_value ELSE '' END) AS product_sku
				FROM {$wpdb->posts} AS products
				LEFT JOIN {$wpdb->postmeta} AS meta
					ON meta.post_id = products.ID
					AND meta.meta_key IN ('_schrack_item_number', '_sku')
				WHERE products.ID IN ({$placeholders})
					AND products.post_type IN ('product', 'product_variation')
					AND products.post_status NOT IN ('trash', 'auto-draft')
				GROUP BY products.ID
			";
			$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$product_id = absint( $row['product_id'] ?? 0 );
				$sku        = isset( $row['schrack_sku'] ) && '' !== trim( (string) $row['schrack_sku'] )
					? (string) $row['schrack_sku']
					: (string) ( $row['product_sku'] ?? '' );
				$sku        = sanitize_text_field( trim( $sku ) );

				if ( $product_id > 0 && '' !== $sku ) {
					$skus[ $product_id ] = $sku;
				}
			}
		}

		return $skus;
	}

	/**
	 * Returns a stable batch of products that have a Schrack item number.
	 *
	 * @return array{product_ids:array<int,int>,total_products:int,batch_start:int}
	 */
	public function schrack_product_batch( int $limit, int $offset ): array {
		global $wpdb;

		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );
		$sql    = "
			SELECT products.ID
			FROM {$wpdb->posts} AS products
			INNER JOIN {$wpdb->postmeta} AS item_meta
				ON item_meta.post_id = products.ID
				AND item_meta.meta_key = '_schrack_item_number'
				AND item_meta.meta_value <> ''
			WHERE products.post_type = 'product'
				AND products.post_status IN ('publish', 'draft', 'private')
			GROUP BY products.ID
			ORDER BY products.ID ASC
			LIMIT %d OFFSET %d
		";
		$product_ids = $wpdb->get_col( $wpdb->prepare( $sql, $limit, $offset ) );
		$total_sql   = "
			SELECT COUNT(DISTINCT products.ID)
			FROM {$wpdb->posts} AS products
			INNER JOIN {$wpdb->postmeta} AS item_meta
				ON item_meta.post_id = products.ID
				AND item_meta.meta_key = '_schrack_item_number'
				AND item_meta.meta_value <> ''
			WHERE products.post_type = 'product'
				AND products.post_status IN ('publish', 'draft', 'private')
		";

		return array(
			'product_ids'    => array_map( 'absint', is_array( $product_ids ) ? $product_ids : array() ),
			'total_products' => (int) $wpdb->get_var( $total_sql ),
			'batch_start'    => $offset,
		);
	}

	/**
	 * Creates or updates a product by SKU.
	 *
	 * @param array<string,mixed> $data Normalized product data.
	 */
	public function upsert( array $data ): int {
		$sku = isset( $data['sku'] ) ? sanitize_text_field( $this->string_value( $data['sku'] ) ) : '';

		if ( '' === $sku ) {
			throw new InvalidArgumentException( 'SKU is required for Schrack product import.' );
		}

		$product_id = $this->find_product_by_sku( $sku );

		if ( $product_id > 0 ) {
			return $this->update_product( $product_id, $data );
		}

		return $this->create_product( $data );
	}

	/**
	 * Creates a simple WooCommerce product.
	 *
	 * @param array<string,mixed> $data Normalized product data.
	 */
	public function create_product( array $data ): int {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			throw new RuntimeException( 'WooCommerce product classes are not available.' );
		}

		$product = new WC_Product_Simple();
		$this->apply_product_data( $product, $data, true );
		$product_id = $product->save();
		$this->sku_product_id_cache[ sanitize_text_field( $this->string_value( $data['sku'] ?? '' ) ) ] = (int) $product_id;

		$this->logger->debug( 'catalog', 'Created Schrack product.', $this->string_value( $data['sku'] ?? '' ), array( 'product_id' => $product_id ) );

		return (int) $product_id;
	}

	/**
	 * Updates an existing product.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $data Normalized product data.
	 */
	public function update_product( int $product_id, array $data ): int {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( 'WooCommerce product was not found.' );
		}

		$this->apply_product_data( $product, $data, false );
		$product->save();
		$this->sku_product_id_cache[ sanitize_text_field( $this->string_value( $data['sku'] ?? '' ) ) ] = $product_id;

		$this->logger->debug( 'catalog', 'Updated Schrack product.', $this->string_value( $data['sku'] ?? '' ), array( 'product_id' => $product_id ) );

		return $product_id;
	}

	/**
	 * Updates WooCommerce regular price from a Schrack purchase price.
	 */
	public function update_price( int $product_id, float $purchase_price ): float {
		$purchase_price = max( 0.0, $purchase_price );
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( 'WooCommerce product was not found.' );
		}

		$sale_price = $this->markup->calculate_sale_price( $purchase_price, $product_id );
		$decimals   = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$price      = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $sale_price, $decimals ) : number_format( $sale_price, $decimals, '.', '' );

		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->update_meta_data( '_schrack_purchase_price', $purchase_price );
		$product->update_meta_data( '_schrack_last_price_sync', current_time( 'mysql' ) );
		$product->save();

		$this->logger->debug(
			'price',
			'Updated WooCommerce price from Schrack purchase price.',
			$product->get_sku(),
			array(
				'product_id'      => $product_id,
				'purchase_price'  => $purchase_price,
				'woocommerce_price' => $sale_price,
			)
		);

		return $sale_price;
	}

	/**
	 * Updates price metadata and WooCommerce lookup rows without loading the product object.
	 */
	public function update_price_fast( int $product_id, float $purchase_price ): float {
		$purchase_price = max( 0.0, $purchase_price );
		$sale_price     = $this->markup->calculate_sale_price( $purchase_price, $product_id );
		$decimals       = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$price          = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $sale_price, $decimals ) : number_format( $sale_price, $decimals, '.', '' );

		update_post_meta( $product_id, '_regular_price', $price );
		update_post_meta( $product_id, '_price', $price );
		update_post_meta( $product_id, '_schrack_purchase_price', $purchase_price );
		update_post_meta( $product_id, '_schrack_last_price_sync', current_time( 'mysql' ) );

		$this->update_price_lookup( $product_id, (float) $price );
		$this->clean_product_runtime_cache( $product_id );

		$this->logger->debug(
			'price',
			'Updated WooCommerce price from Schrack purchase price.',
			null,
			array(
				'product_id'         => $product_id,
				'purchase_price'     => $purchase_price,
				'woocommerce_price'  => $sale_price,
				'update_mode'        => 'fast_meta',
			)
		);

		return $sale_price;
	}

	/**
	 * Updates WooCommerce stock fields.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $stock_data Stock data.
	 */
	public function update_stock( int $product_id, array $stock_data ): float {
		if ( 'yes' !== $this->settings->get( 'stock_handling_enabled', 'yes' ) ) {
			$this->logger->info( 'stock', 'Skipped stock update because stock handling is disabled.', null, array( 'product_id' => $product_id ) );
			return 0.0;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( 'WooCommerce product was not found.' );
		}

		$total_stock = isset( $stock_data['total_stock'] ) ? max( 0, (float) $stock_data['total_stock'] ) : 0.0;

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $total_stock );
		$product->set_stock_status( $total_stock > 0 ? 'instock' : 'outofstock' );
		$product->update_meta_data( '_schrack_last_stock_sync', current_time( 'mysql' ) );
		$product->update_meta_data( '_schrack_stock_breakdown', wp_json_encode( $stock_data['warehouses'] ?? array() ) );
		$product->save();

		$this->logger->debug(
			'stock',
			'Updated WooCommerce stock from Schrack quantities.',
			$product->get_sku(),
			array(
				'product_id'   => $product_id,
				'total_stock'  => $total_stock,
				'stock_source' => $this->settings->get( 'stock_source', 'all' ),
			)
		);

		return $total_stock;
	}

	/**
	 * Updates stock metadata and WooCommerce lookup rows without loading the product object.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $stock_data Stock data.
	 */
	public function update_stock_fast( int $product_id, array $stock_data ): float {
		if ( 'yes' !== $this->settings->get( 'stock_handling_enabled', 'yes' ) ) {
			$this->logger->debug( 'stock', 'Skipped stock update because stock handling is disabled.', null, array( 'product_id' => $product_id ) );
			return 0.0;
		}

		$total_stock    = isset( $stock_data['total_stock'] ) ? max( 0, (float) $stock_data['total_stock'] ) : 0.0;
		$stock_quantity = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $total_stock ) : $total_stock;
		$stock_status   = $total_stock > 0 ? 'instock' : 'outofstock';

		update_post_meta( $product_id, '_manage_stock', 'yes' );
		update_post_meta( $product_id, '_stock', $stock_quantity );
		update_post_meta( $product_id, '_stock_status', $stock_status );
		update_post_meta( $product_id, '_schrack_last_stock_sync', current_time( 'mysql' ) );
		update_post_meta( $product_id, '_schrack_stock_breakdown', wp_json_encode( $stock_data['warehouses'] ?? array() ) );

		$this->update_stock_lookup( $product_id, (float) $stock_quantity, $stock_status );
		$this->update_stock_visibility_term( $product_id, $stock_status );
		$this->clean_product_runtime_cache( $product_id );

		$this->logger->debug(
			'stock',
			'Updated WooCommerce stock from Schrack quantities.',
			null,
			array(
				'product_id'   => $product_id,
				'total_stock'  => $total_stock,
				'stock_source' => $this->settings->get( 'stock_source', 'all' ),
				'update_mode'  => 'fast_meta',
			)
		);

		return $total_stock;
	}

	/**
	 * Assigns product categories from a category path.
	 *
	 * @param string|array<int,string> $category_path Category path.
	 * @return array<int,int>
	 */
	public function assign_categories( string|array $category_path ): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$parts = is_array( $category_path )
			? array_map( static fn ( mixed $part ): string => sanitize_text_field( is_scalar( $part ) ? (string) $part : '' ), $category_path )
			: preg_split( '/\s*(?:>|\/|\|)\s*/', sanitize_text_field( $category_path ) );

		$parts = array_values( array_filter( array_map( 'trim', (array) $parts ) ) );

		if ( empty( $parts ) ) {
			return array();
		}

		$path_key = implode( ' > ', array_map( array( $this, 'category_cache_name' ), $parts ) );

		if ( isset( $this->category_path_cache[ $path_key ] ) ) {
			return $this->category_path_cache[ $path_key ];
		}

		$parent = 0;
		$ids    = array();

		foreach ( $parts as $name ) {
			$cache_key = $this->category_cache_key( $parent, $name );

			if ( isset( $this->category_term_cache[ $cache_key ] ) ) {
				$term_id = $this->category_term_cache[ $cache_key ];
				$ids[]   = $term_id;
				$parent  = $term_id;
				continue;
			}

			$existing = term_exists( $name, 'product_cat', $parent );

			if ( is_wp_error( $existing ) ) {
				continue;
			}

			if ( 0 === $existing || null === $existing ) {
				$created = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );

				if ( is_wp_error( $created ) ) {
					continue;
				}

				$term_id = (int) $created['term_id'];
			} elseif ( is_array( $existing ) ) {
				$term_id = (int) $existing['term_id'];
			} else {
				$term_id = (int) $existing;
			}

			$this->category_term_cache[ $cache_key ] = $term_id;
			$ids[]  = $term_id;
			$parent = $term_id;
		}

		$this->category_path_cache[ $path_key ] = $ids;

		return $ids;
	}

	/**
	 * Applies normalized data to a WooCommerce product.
	 *
	 * @param WC_Product          $product Product object.
	 * @param array<string,mixed> $data Product data.
	 * @param bool                $is_new Whether this is a new product.
	 */
	private function apply_product_data( WC_Product $product, array $data, bool $is_new ): void {
		$sku  = sanitize_text_field( $this->string_value( $data['sku'] ?? '' ) );
		$name = trim( sanitize_text_field( $this->string_value( $data['name'] ?? '' ) ) );

		if ( $is_new && '' === $name ) {
			$name = 'Schrack ' . $sku;
		}

		if ( $is_new ) {
			$product->set_sku( $sku );
			$product->set_status( (string) $this->settings->get( 'publish_status', 'draft' ) );
			$product->set_catalog_visibility( 'visible' );
		}

		if ( '' !== $name ) {
			$product->set_name( $name );
		}

		if ( $is_new || $this->has_text_value( $data, 'short_description' ) ) {
			$product->set_short_description( wp_kses_post( $this->string_value( $data['short_description'] ?? '' ) ) );
		}

		if ( $is_new || $this->has_text_value( $data, 'description' ) ) {
			$product->set_description( wp_kses_post( $this->string_value( $data['description'] ?? '' ) ) );
		}

		if ( ! empty( $data['category_path'] ) ) {
			$category_ids = $this->assign_categories( is_array( $data['category_path'] ) ? $data['category_path'] : $this->string_value( $data['category_path'] ) );
			if ( ! empty( $category_ids ) ) {
				$product->set_category_ids( $category_ids );
			}
		}

		$product->update_meta_data( '_schrack_item_number', $sku );
		$this->update_optional_meta( $product, '_schrack_ean', $data, 'ean', $is_new );
		$this->update_optional_meta( $product, '_schrack_manufacturer', $data, 'manufacturer', $is_new );
		$this->update_optional_meta( $product, '_schrack_unit', $data, 'unit', $is_new );
		$this->update_optional_meta( $product, '_schrack_catalog_status', $data, 'catalog_status', $is_new );
		$this->update_product_image_url( $product, $data, $is_new );

		if ( $is_new || ! empty( $data['category_path'] ) ) {
			$product->update_meta_data( '_schrack_raw_category', $this->category_path_label( $data['category_path'] ?? '' ) );
		}

		if ( ! empty( $data['technical_attributes'] ) ) {
			$product->update_meta_data( '_schrack_technical_attributes', wp_json_encode( $data['technical_attributes'] ) );
		}
	}

	/**
	 * Keeps WooCommerce price lookup data in sync for fast direct price updates.
	 */
	private function update_price_lookup( int $product_id, float $price ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_product_meta_lookup';

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, min_price, max_price)
				VALUES (%d, %f, %f)
				ON DUPLICATE KEY UPDATE min_price = VALUES(min_price), max_price = VALUES(max_price)",
				$product_id,
				$price,
				$price
			)
		);
	}

	/**
	 * Keeps WooCommerce stock lookup data in sync for fast direct stock updates.
	 */
	private function update_stock_lookup( int $product_id, float $stock_quantity, string $stock_status ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_product_meta_lookup';

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, stock_quantity, stock_status)
				VALUES (%d, %f, %s)
				ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity), stock_status = VALUES(stock_status)",
				$product_id,
				$stock_quantity,
				$stock_status
			)
		);
	}

	/**
	 * Mirrors WooCommerce's out-of-stock visibility term without a full product save.
	 */
	private function update_stock_visibility_term( int $product_id, string $stock_status ): void {
		if ( ! taxonomy_exists( 'product_visibility' ) ) {
			return;
		}

		if ( 'outofstock' === $stock_status ) {
			wp_set_object_terms( $product_id, 'outofstock', 'product_visibility', true );
			return;
		}

		wp_remove_object_terms( $product_id, 'outofstock', 'product_visibility' );
	}

	/**
	 * Clears the lightweight runtime caches affected by direct product metadata writes.
	 */
	private function clean_product_runtime_cache( int $product_id ): void {
		wp_cache_delete( $product_id, 'post_meta' );
		clean_post_cache( $product_id );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Checks whether an optional text value has meaningful content.
	 *
	 * @param array<string,mixed> $data Product data.
	 */
	private function has_text_value( array $data, string $key ): bool {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] );
	}

	/**
	 * Updates optional Schrack metadata without wiping existing values on sparse imports.
	 *
	 * @param array<string,mixed> $data Product data.
	 */
	private function update_optional_meta( WC_Product $product, string $meta_key, array $data, string $data_key, bool $is_new ): void {
		if ( ! $is_new && ! $this->has_text_value( $data, $data_key ) ) {
			return;
		}

		$product->update_meta_data( $meta_key, sanitize_text_field( $this->string_value( $data[ $data_key ] ?? '' ) ) );
	}

	/**
	 * Stores the Schrack catalog photo URL without slowing down product creation.
	 *
	 * @param array<string,mixed> $data Product data.
	 */
	private function update_product_image_url( WC_Product $product, array $data, bool $is_new ): void {
		$image_url = isset( $data['image_url'] ) ? $this->normalize_image_url( $this->string_value( $data['image_url'] ) ) : '';

		if ( '' === $image_url && ! $is_new ) {
			return;
		}

		$current_url = $this->normalize_image_url( $this->string_value( $product->get_meta( '_schrack_image_url', true ) ) );

		$product->update_meta_data( '_schrack_image_url', $image_url );

		if ( '' !== $image_url && $image_url !== $current_url ) {
			$product->update_meta_data( '_schrack_image_status', 'pending' );
			$product->delete_meta_data( '_schrack_image_error' );
		}
	}

	/**
	 * Imports a stored Schrack catalog photo as the WooCommerce featured image.
	 */
	public function import_product_image( int $product_id ): int {
		$result = $this->import_product_image_with_result( $product_id );

		return absint( $result['attachment_id'] ?? 0 );
	}

	/**
	 * Imports a stored Schrack catalog photo and returns a detailed sync result.
	 *
	 * @return array<string,mixed>
	 */
	public function import_product_image_with_result( int $product_id ): array {
		if ( 'yes' !== $this->settings->get( 'image_import_enabled', 'yes' ) ) {
			return array(
				'status'        => 'skipped_disabled',
				'attachment_id' => 0,
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return array(
				'status'        => 'missing_product',
				'attachment_id' => 0,
				'error'         => 'WooCommerce product was not found.',
			);
		}

		$image_url    = $this->normalize_image_url( $this->string_value( $product->get_meta( '_schrack_image_url', true ) ) );
		$imported_url = $this->normalize_image_url( $this->string_value( $product->get_meta( '_schrack_imported_image_url', true ) ) );
		$stored_attachment_id = absint( $product->get_meta( '_schrack_image_attachment_id', true ) );

		if ( '' === $image_url ) {
			$this->mark_product_image_sync( $product, 'missing_url', '', 0 );
			$product->save();

			return array(
				'status'        => 'missing_url',
				'attachment_id' => 0,
			);
		}

		if ( $imported_url === $image_url && $this->is_valid_image_attachment( (int) $product->get_image_id() ) ) {
			$attachment_id = (int) $product->get_image_id();
			$this->mark_attachment_image_source( $attachment_id, $image_url );
			$this->mark_product_image_sync( $product, 'already_imported', $image_url, $attachment_id );
			$product->save();

			return array(
				'status'        => 'already_imported',
				'attachment_id' => $attachment_id,
				'image_url'     => $image_url,
			);
		}

		if ( $imported_url === $image_url && $this->is_valid_image_attachment( $stored_attachment_id ) ) {
			$this->attach_existing_product_image( $product, $stored_attachment_id, $image_url, 'reused_existing' );

			return array(
				'status'        => 'reused_existing',
				'attachment_id' => $stored_attachment_id,
				'image_url'     => $image_url,
			);
		}

		$existing_attachment_id = $this->find_existing_image_attachment( $image_url );

		if ( $existing_attachment_id > 0 ) {
			$this->attach_existing_product_image( $product, $existing_attachment_id, $image_url, 'reused_existing' );

			return array(
				'status'        => 'reused_existing',
				'attachment_id' => $existing_attachment_id,
				'image_url'     => $image_url,
			);
		}

		$attachment_id = $this->sideload_product_image( $image_url, $product );

		if ( is_wp_error( $attachment_id ) ) {
			$error = $attachment_id->get_error_message();
			$this->mark_product_image_sync( $product, 'failed', $image_url, 0, $error );
			$product->save();

			return array(
				'status'        => 'failed',
				'attachment_id' => 0,
				'image_url'     => $image_url,
				'error'         => $error,
			);
		}

		$product->set_image_id( $attachment_id );
		$product->update_meta_data( '_schrack_image_attachment_id', $attachment_id );
		$product->update_meta_data( '_schrack_imported_image_url', $image_url );
		$this->mark_attachment_image_source( $attachment_id, $image_url );
		$this->mark_product_image_sync( $product, 'imported', $image_url, $attachment_id );
		$product->save();

		return array(
			'status'        => 'imported',
			'attachment_id' => $attachment_id,
			'image_url'     => $image_url,
		);
	}

	/**
	 * Reuses a previously imported attachment without downloading it again.
	 */
	private function attach_existing_product_image( WC_Product $product, int $attachment_id, string $image_url, string $status ): void {
		$product->set_image_id( $attachment_id );
		$product->update_meta_data( '_schrack_image_attachment_id', $attachment_id );
		$product->update_meta_data( '_schrack_imported_image_url', $image_url );
		$this->mark_attachment_image_source( $attachment_id, $image_url );
		$this->mark_product_image_sync( $product, $status, $image_url, $attachment_id );
		$product->save();

		$this->logger->debug(
			'images',
			'Reused existing Schrack product image attachment.',
			$product->get_sku(),
			array(
				'image_url'     => $image_url,
				'attachment_id' => $attachment_id,
				'product_id'    => (int) $product->get_id(),
			)
		);
	}

	/**
	 * Finds an existing media-library attachment for a normalized Schrack image URL.
	 */
	private function find_existing_image_attachment( string $image_url ): int {
		$attachment_id = $this->find_attachment_by_source_hash( $image_url );

		if ( $attachment_id > 0 ) {
			return $attachment_id;
		}

		return $this->find_legacy_product_attachment_by_url( $image_url );
	}

	/**
	 * Finds attachments imported by current plugin versions.
	 */
	private function find_attachment_by_source_hash( string $image_url ): int {
		$hash = $this->image_source_hash( $image_url );

		if ( '' === $hash ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 5,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => '_schrack_image_source_hash',
						'value' => $hash,
					),
				),
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( $this->attachment_matches_image_url( $attachment_id, $image_url ) ) {
				return $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Finds attachments imported by older plugin versions via product meta.
	 */
	private function find_legacy_product_attachment_by_url( string $image_url ): int {
		global $wpdb;

		if ( '' === $image_url ) {
			return 0;
		}

		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CAST(attachment_meta.meta_value AS UNSIGNED)
				FROM {$wpdb->postmeta} AS imported_meta
				INNER JOIN {$wpdb->posts} AS products
					ON products.ID = imported_meta.post_id AND products.post_type = 'product'
				INNER JOIN {$wpdb->postmeta} AS attachment_meta
					ON attachment_meta.post_id = imported_meta.post_id AND attachment_meta.meta_key = '_schrack_image_attachment_id'
				WHERE imported_meta.meta_key = '_schrack_imported_image_url'
					AND imported_meta.meta_value = %s
					AND attachment_meta.meta_value <> ''
				LIMIT 10",
				$image_url
			)
		);

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( $this->is_valid_image_attachment( $attachment_id ) ) {
				$this->mark_attachment_image_source( $attachment_id, $image_url );
				return $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Tags an imported attachment so future products can reuse it by URL.
	 */
	private function mark_attachment_image_source( int $attachment_id, string $image_url ): void {
		if ( ! $this->is_valid_image_attachment( $attachment_id ) || '' === $image_url ) {
			return;
		}

		update_post_meta( $attachment_id, '_schrack_image_source_url', $image_url );
		update_post_meta( $attachment_id, '_schrack_image_source_hash', $this->image_source_hash( $image_url ) );
	}

	/**
	 * Checks whether an attachment is a usable image.
	 */
	private function is_valid_image_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		return function_exists( 'wp_attachment_is_image' )
			? wp_attachment_is_image( $attachment_id )
			: '' !== (string) wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Confirms an attachment was imported from the same normalized image URL.
	 */
	private function attachment_matches_image_url( int $attachment_id, string $image_url ): bool {
		if ( ! $this->is_valid_image_attachment( $attachment_id ) ) {
			return false;
		}

		$stored_url = $this->normalize_image_url( $this->string_value( get_post_meta( $attachment_id, '_schrack_image_source_url', true ) ) );

		return $stored_url === $image_url;
	}

	/**
	 * Builds a stable hash for source image URL lookups.
	 */
	private function image_source_hash( string $image_url ): string {
		$image_url = $this->normalize_image_url( $image_url );

		return '' === $image_url ? '' : hash( 'sha256', $image_url );
	}

	/**
	 * Downloads one remote catalog image into the WordPress media library.
	 */
	private function sideload_product_image( string $image_url, WC_Product $product ): int|WP_Error {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$timeout   = max( 5, min( 60, (int) apply_filters( 'schrack_wc_sync_image_download_timeout', 45, $image_url, $product ) ) );
		$temp_file = download_url( $image_url, $timeout );

		if ( is_wp_error( $temp_file ) ) {
			$this->logger->warning(
				'images',
				'Failed to download Schrack product image.',
				$product->get_sku(),
				array(
					'image_url' => $image_url,
					'error'     => $temp_file->get_error_message(),
				)
			);
			return $temp_file;
		}

		$file = array(
			'name'     => $this->image_filename_from_url( $image_url ),
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file, (int) $product->get_id(), $product->get_name() );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			$this->logger->warning(
				'images',
				'Failed to attach Schrack product image.',
				$product->get_sku(),
				array(
					'image_url' => $image_url,
					'error'     => $attachment_id->get_error_message(),
				)
			);
			return $attachment_id;
		}

		$this->logger->debug(
			'images',
			'Imported Schrack product image.',
			$product->get_sku(),
			array(
				'image_url'     => $image_url,
				'attachment_id' => (int) $attachment_id,
			)
		);

		return (int) $attachment_id;
	}

	/**
	 * Stores image sync bookkeeping on the product.
	 */
	private function mark_product_image_sync( WC_Product $product, string $status, string $image_url, int $attachment_id = 0, string $error = '' ): void {
		$product->update_meta_data( '_schrack_last_image_sync', current_time( 'mysql' ) );
		$product->update_meta_data( '_schrack_last_image_attempt_ts', time() );
		$product->update_meta_data( '_schrack_image_status', sanitize_key( $status ) );

		if ( '' !== $image_url ) {
			$product->update_meta_data( '_schrack_image_url', $image_url );
		}

		if ( $attachment_id > 0 ) {
			$product->update_meta_data( '_schrack_image_attachment_id', $attachment_id );
		}

		if ( '' !== $error ) {
			$product->update_meta_data( '_schrack_image_error', sanitize_text_field( $error ) );
		} else {
			$product->delete_meta_data( '_schrack_image_error' );
		}
	}

	/**
	 * Normalizes an image URL stored in catalog data or product meta.
	 */
	private function normalize_image_url( string $image_url ): string {
		$image_url = html_entity_decode( trim( $image_url ), ENT_QUOTES );

		if ( '' === $image_url ) {
			return '';
		}

		if ( preg_match( '/\bsrc=[\'"]([^\'"]+)[\'"]/i', $image_url, $matches ) ) {
			$image_url = $matches[1];
		} elseif ( preg_match( '/https?:\/\/[^\s,;"\'<>|]+/i', $image_url, $matches ) ) {
			$image_url = $matches[0];
		} elseif ( str_starts_with( $image_url, '//' ) ) {
			$image_url = 'https:' . $image_url;
		}

		return esc_url_raw( $image_url );
	}

	/**
	 * Builds a stable media filename from a Schrack image URL.
	 */
	private function image_filename_from_url( string $image_url ): string {
		$path     = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$filename = sanitize_file_name( wp_basename( $path ) );

		return '' !== $filename ? $filename : 'schrack-product-image.jpg';
	}

	/**
	 * Formats a category path for metadata without array-to-string notices.
	 */
	private function category_path_label( mixed $category_path ): string {
		if ( is_array( $category_path ) ) {
			$parts = array_map(
				static fn ( mixed $part ): string => sanitize_text_field( is_scalar( $part ) ? (string) $part : '' ),
				$category_path
			);

			return implode( ' > ', array_values( array_filter( array_map( 'trim', $parts ) ) ) );
		}

		return sanitize_text_field( $this->string_value( $category_path ) );
	}

	/**
	 * Builds a cache key for one category level.
	 */
	private function category_cache_key( int $parent_id, string $name ): string {
		return $parent_id . '|' . $this->category_cache_name( $name );
	}

	/**
	 * Normalizes category names for per-request cache keys.
	 */
	private function category_cache_name( string $name ): string {
		$name = trim( $name );

		if ( function_exists( 'remove_accents' ) ) {
			$name = remove_accents( $name );
		}

		$name = strtolower( $name );
		$name = preg_replace( '/\s+/', ' ', $name );

		return null === $name ? '' : $name;
	}

	/**
	 * Safely converts scalar input to a string.
	 */
	private function string_value( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
