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
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}

		return (int) wc_get_product_id_by_sku( sanitize_text_field( $sku ) );
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

		$this->logger->info( 'catalog', 'Created Schrack product.', $this->string_value( $data['sku'] ?? '' ), array( 'product_id' => $product_id ) );

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

		$this->logger->info( 'catalog', 'Updated Schrack product.', $this->string_value( $data['sku'] ?? '' ), array( 'product_id' => $product_id ) );

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

		$this->logger->info(
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

		$this->logger->info(
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

		$parent = 0;
		$ids    = array();

		foreach ( $parts as $name ) {
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

			$ids[]  = $term_id;
			$parent = $term_id;
		}

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

		if ( $is_new || ! empty( $data['category_path'] ) ) {
			$product->update_meta_data( '_schrack_raw_category', sanitize_text_field( is_array( $data['category_path'] ?? '' ) ? implode( ' > ', $data['category_path'] ) : $this->string_value( $data['category_path'] ?? '' ) ) );
		}

		if ( ! empty( $data['technical_attributes'] ) ) {
			$product->update_meta_data( '_schrack_technical_attributes', wp_json_encode( $data['technical_attributes'] ) );
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
	 * Safely converts scalar input to a string.
	 */
	private function string_value( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
