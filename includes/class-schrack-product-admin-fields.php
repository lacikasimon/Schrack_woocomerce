<?php
/**
 * Product edit screen additions: supplier sidebar box and raw feed data box.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Product_Admin_Fields {
	/**
	 * Registers hooks.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
	}

	/**
	 * Adds the supplier sidebar box and the raw feed data box to the product edit screen.
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'schrack_supplier_info',
			__( 'Beszállító', 'schrack-woocommerce-sync' ),
			array( $this, 'render_supplier_box' ),
			'product',
			'side',
			'high'
		);

		add_meta_box(
			'schrack_raw_feed_data',
			__( 'Nyers beszállítói adatok (feed)', 'schrack-woocommerce-sync' ),
			array( $this, 'render_raw_feed_box' ),
			'product',
			'normal',
			'low'
		);
	}

	/**
	 * Renders the sidebar box showing which supplier a product was synced from.
	 */
	public function render_supplier_box( WP_Post $post ): void {
		$product_id = $post->ID;
		$source     = get_post_meta( $product_id, '_schrack_catalog_source', true );
		$source     = is_string( $source ) && '' !== $source ? $source : '';

		$fields = array();

		if ( '' === $source ) {
			$fields[] = array(
				'label' => __( 'Forrás', 'schrack-woocommerce-sync' ),
				'value' => __( 'Nincs beszállítói adat (manuálisan létrehozott termék)', 'schrack-woocommerce-sync' ),
			);

			include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-supplier-box.php';
			return;
		}

		$item_number_key  = 'schrack' === $source ? '_schrack_item_number' : '_' . $source . '_item_number';
		$ean_key          = 'schrack' === $source ? '_schrack_ean' : '_' . $source . '_ean';
		$manufacturer_key = 'schrack' === $source ? '_schrack_manufacturer' : '_' . $source . '_manufacturer';

		$fields[] = array(
			'label' => __( 'Forrás', 'schrack-woocommerce-sync' ),
			'value' => $this->source_label( $source ),
		);

		$supplier = get_post_meta( $product_id, '_schrack_supplier', true );
		if ( is_string( $supplier ) && '' !== $supplier ) {
			$fields[] = array(
				'label' => __( 'Beszállító', 'schrack-woocommerce-sync' ),
				'value' => $supplier,
			);
		}

		$item_number = get_post_meta( $product_id, $item_number_key, true );
		if ( is_string( $item_number ) && '' !== $item_number ) {
			$fields[] = array(
				'label' => __( 'Cikkszám a beszállítónál', 'schrack-woocommerce-sync' ),
				'value' => $item_number,
			);
		}

		$manufacturer = get_post_meta( $product_id, $manufacturer_key, true );
		if ( is_string( $manufacturer ) && '' !== $manufacturer ) {
			$fields[] = array(
				'label' => __( 'Gyártó', 'schrack-woocommerce-sync' ),
				'value' => $manufacturer,
			);
		}

		$ean = get_post_meta( $product_id, $ean_key, true );
		if ( is_string( $ean ) && '' !== $ean ) {
			$fields[] = array(
				'label' => __( 'EAN', 'schrack-woocommerce-sync' ),
				'value' => $ean,
			);
		}

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-supplier-box.php';
	}

	/**
	 * Renders the raw, unfiltered feed data table for the product.
	 */
	public function render_raw_feed_box( WP_Post $post ): void {
		$raw_json = get_post_meta( $post->ID, '_schrack_raw_feed_data', true );
		$raw_data = is_string( $raw_json ) ? json_decode( $raw_json, true ) : null;
		$raw_data = is_array( $raw_data ) ? $raw_data : array();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-raw-feed-box.php';
	}

	/**
	 * Returns a readable source label matching Schrack_Product_Mapper::catalog_source_label().
	 */
	private function source_label( string $source ): string {
		return match ( sanitize_key( $source ) ) {
			'telesystem' => 'Telesystem',
			'schrack'    => 'Schrack',
			default      => ucwords( str_replace( array( '-', '_' ), ' ', sanitize_key( $source ) ) ),
		};
	}
}
