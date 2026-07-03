<?php
/**
 * Shared frontend stock label helper.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Stock_Label {
	private const LOW_STOCK_THRESHOLD = 5;

	/**
	 * Returns the display text and CSS state for one product stock badge.
	 *
	 * @return array{text:string,class:string,status:string,quantity:int|null}
	 */
	public static function badge( WC_Product $product ): array {
		$quantity = self::stock_quantity( $product );

		if ( self::is_on_request( $product ) ) {
			return array(
				'text'     => __( 'On request', 'schrack-woocommerce-sync' ),
				'class'    => 'is-on-request',
				'status'   => 'on-request',
				'quantity' => $quantity,
			);
		}

		if ( ! $product->is_in_stock() ) {
			return array(
				'text'     => __( 'Stoc epuizat', 'schrack-woocommerce-sync' ),
				'class'    => 'is-out-of-stock',
				'status'   => 'out-of-stock',
				'quantity' => $quantity,
			);
		}

		if ( null !== $quantity && $quantity > 0 && $quantity < self::LOW_STOCK_THRESHOLD ) {
			return array(
				'text'     => __( 'Stoc redus', 'schrack-woocommerce-sync' ),
				'class'    => 'is-low-stock',
				'status'   => 'low-stock',
				'quantity' => $quantity,
			);
		}

		return array(
			'text'     => __( 'In stoc', 'schrack-woocommerce-sync' ),
			'class'    => 'is-in-stock',
			'status'   => 'in-stock',
			'quantity' => $quantity,
		);
	}

	/**
	 * Returns whether supplier/catalog data says the product is available on request.
	 */
	private static function is_on_request( WC_Product $product ): bool {
		if ( method_exists( $product, 'get_stock_status' ) && 'onbackorder' === $product->get_stock_status() ) {
			return true;
		}

		foreach ( self::supplier_status_values( $product ) as $value ) {
			if ( self::text_is_on_request( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns supplier/catalog status texts stored by the importers.
	 *
	 * @return array<int,string>
	 */
	private static function supplier_status_values( WC_Product $product ): array {
		$source = sanitize_key( (string) $product->get_meta( '_schrack_catalog_source', true ) );
		$keys   = array(
			'_schrack_catalog_status',
			'_telesystem_catalog_status',
			'_telesystem_stock_text',
		);

		if ( '' !== $source && 'schrack' !== $source ) {
			$keys[] = '_' . $source . '_catalog_status';
			$keys[] = '_' . $source . '_stock_text';
		}

		$values = array();

		foreach ( array_unique( $keys ) as $key ) {
			$value = $product->get_meta( $key, true );

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$values[] = sanitize_text_field( (string) $value );
			}
		}

		return $values;
	}

	/**
	 * Detects common "on request" labels from supplier feeds.
	 */
	private static function text_is_on_request( string $text ): bool {
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}

		$key = strtolower( $text );
		$key = preg_replace( '/[^a-z0-9]+/', '', $key );
		$key = is_string( $key ) ? $key : '';

		foreach ( array( 'onrequest', 'uponrequest', 'lacomanda', 'lacerere', 'cerere', 'aufanfrage', 'anfrage' ) as $needle ) {
			if ( str_contains( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the managed stock quantity when WooCommerce stores one.
	 */
	private static function stock_quantity( WC_Product $product ): ?int {
		$quantity = $product->get_stock_quantity();

		if ( null === $quantity || '' === $quantity ) {
			return null;
		}

		return max( 0, (int) $quantity );
	}
}
