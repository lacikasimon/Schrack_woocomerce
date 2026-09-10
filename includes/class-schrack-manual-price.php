<?php
/**
 * Manual storefront price protection for supplier-synced products.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schrack_Manual_Price {
	public const META_PRICE         = '_schrack_manual_price';
	public const META_STATUS        = '_schrack_manual_price_status';
	public const META_AUTOMATIC     = '_schrack_automatic_price';
	public const META_PREVIOUS      = '_schrack_manual_price_previous';
	public const META_OVERRIDDEN_AT = '_schrack_manual_price_overridden_at';

	/**
	 * Limits automatic pricing to supplier imports, including legacy item metadata.
	 * A WooCommerce SKU or a previously stored price is not proof of a supplier link.
	 *
	 * @param array<int,string> $sources Supplier sources accepted by the caller.
	 */
	public static function is_supplier_product( WC_Product|int $product, array $sources = array( 'schrack', 'telesystem', 'edoc' ) ): bool {
		$read_meta = static function ( string $key ) use ( $product ): mixed {
			return $product instanceof WC_Product
				? $product->get_meta( $key, true )
				: get_post_meta( $product, $key, true );
		};
		$source = $read_meta( '_schrack_catalog_source' );
		$source = is_scalar( $source ) ? sanitize_key( trim( (string) $source ) ) : '';

		if ( '' !== $source ) {
			return in_array( $source, $sources, true );
		}

		foreach ( array_intersect( array( 'schrack', 'telesystem' ), $sources ) as $legacy_source ) {
			$key         = '_' . $legacy_source . '_item_number';
			$item_number = $read_meta( $key );

			if ( is_scalar( $item_number ) && '' !== trim( (string) $item_number ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Applies the manual-price rule to a loaded product.
	 *
	 * @return array{price:float,manual_active:bool,manual_overridden:bool,manual_price:float|null,automatic_price:float}
	 */
	public static function resolve_product( WC_Product $product, float $automatic_price ): array {
		$automatic_price = max( 0.0, $automatic_price );
		$manual_price    = self::positive_price( $product->get_meta( self::META_PRICE, true ) );

		if ( ! self::is_supplier_product( $product ) ) {
			$product->delete_meta_data( self::META_AUTOMATIC );

			if ( null !== $manual_price ) {
				$product->update_meta_data( self::META_STATUS, 'active' );
			}

			return self::result( $manual_price ?? (float) $product->get_regular_price( 'edit' ), null !== $manual_price, false, $manual_price, 0.0 );
		}

		$product->update_meta_data( self::META_AUTOMATIC, self::format_price( $automatic_price ) );

		if ( null === $manual_price ) {
			return self::result( $automatic_price, false, false, null, $automatic_price );
		}

		if ( self::is_automatic_higher( $automatic_price, $manual_price ) ) {
			$product->update_meta_data( self::META_PREVIOUS, self::format_price( $manual_price ) );
			$product->delete_meta_data( self::META_PRICE );
			$product->update_meta_data( self::META_STATUS, 'overridden' );
			$product->update_meta_data( self::META_OVERRIDDEN_AT, current_time( 'mysql' ) );

			return self::result( $automatic_price, false, true, $manual_price, $automatic_price );
		}

		$product->update_meta_data( self::META_STATUS, 'active' );

		return self::result( $manual_price, true, false, $manual_price, $automatic_price );
	}

	/**
	 * Applies the manual-price rule through direct metadata writes used by fast sync.
	 *
	 * @return array{price:float,manual_active:bool,manual_overridden:bool,manual_price:float|null,automatic_price:float}
	 */
	public static function resolve_product_id( int $product_id, float $automatic_price ): array {
		$product_id      = absint( $product_id );
		$automatic_price = max( 0.0, $automatic_price );
		$manual_price    = self::positive_price( get_post_meta( $product_id, self::META_PRICE, true ) );

		if ( $product_id > 0 && ! self::is_supplier_product( $product_id ) ) {
			delete_post_meta( $product_id, self::META_AUTOMATIC );

			if ( null !== $manual_price ) {
				update_post_meta( $product_id, self::META_STATUS, 'active' );
			}

			return self::result( $manual_price ?? (float) get_post_meta( $product_id, '_regular_price', true ), null !== $manual_price, false, $manual_price, 0.0 );
		}

		if ( $product_id > 0 ) {
			update_post_meta( $product_id, self::META_AUTOMATIC, self::format_price( $automatic_price ) );
		}

		if ( null === $manual_price || 0 === $product_id ) {
			return self::result( $automatic_price, false, false, null, $automatic_price );
		}

		if ( self::is_automatic_higher( $automatic_price, $manual_price ) ) {
			update_post_meta( $product_id, self::META_PREVIOUS, self::format_price( $manual_price ) );
			delete_post_meta( $product_id, self::META_PRICE );
			update_post_meta( $product_id, self::META_STATUS, 'overridden' );
			update_post_meta( $product_id, self::META_OVERRIDDEN_AT, current_time( 'mysql' ) );

			return self::result( $automatic_price, false, true, $manual_price, $automatic_price );
		}

		update_post_meta( $product_id, self::META_STATUS, 'active' );

		return self::result( $manual_price, true, false, $manual_price, $automatic_price );
	}

	/**
	 * Saves a manually entered storefront price and immediately applies the rule.
	 *
	 * @return array{price:float,manual_active:bool,manual_overridden:bool,manual_price:float|null,automatic_price:float}
	 */
	public static function set_product_price( WC_Product $product, float $manual_price ): array {
		$manual_price    = max( 0.0, $manual_price );
		$automatic_price = null;

		if ( self::is_supplier_product( $product ) ) {
			$automatic_price = self::positive_price( $product->get_meta( self::META_AUTOMATIC, true ) );

			if ( null === $automatic_price ) {
				$automatic_price = self::positive_price( $product->get_regular_price( 'edit' ) );
			}
		}

		$automatic_price = $automatic_price ?? 0.0;
		$product->update_meta_data( self::META_PRICE, self::format_price( $manual_price ) );
		$product->update_meta_data( self::META_STATUS, 'active' );
		$product->delete_meta_data( self::META_PREVIOUS );
		$product->delete_meta_data( self::META_OVERRIDDEN_AT );

		$result = self::resolve_product( $product, $automatic_price );
		$price  = self::format_price( $result['price'] );

		$product->set_regular_price( $price );
		$product->set_price( $price );

		return $result;
	}

	/**
	 * Removes an active manual price and restores the last automatic price.
	 */
	public static function clear_product_price( WC_Product $product ): void {
		$is_supplier     = self::is_supplier_product( $product );
		$automatic_price = $is_supplier ? self::positive_price( $product->get_meta( self::META_AUTOMATIC, true ) ) : null;

		if ( ! $is_supplier ) {
			$product->delete_meta_data( self::META_AUTOMATIC );
		}

		$product->delete_meta_data( self::META_PRICE );
		$product->delete_meta_data( self::META_STATUS );
		$product->delete_meta_data( self::META_PREVIOUS );
		$product->delete_meta_data( self::META_OVERRIDDEN_AT );

		if ( null !== $automatic_price ) {
			$price = self::format_price( $automatic_price );
			$product->set_regular_price( $price );
			$product->set_price( $price );
		}
	}

	/**
	 * Formats a price using the WooCommerce store precision.
	 */
	public static function format_price( float $price ): string {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return function_exists( 'wc_format_decimal' )
			? wc_format_decimal( max( 0.0, $price ), $decimals )
			: number_format( max( 0.0, $price ), $decimals, '.', '' );
	}

	/**
	 * Returns a positive price or null for an empty/invalid value.
	 */
	private static function positive_price( mixed $value ): ?float {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = str_replace( ',', '.', trim( (string) $value ) );

		return is_numeric( $value ) && (float) $value > 0.0 ? (float) $value : null;
	}

	/**
	 * Compares prices at store precision to avoid floating-point threshold errors.
	 */
	private static function is_automatic_higher( float $automatic_price, float $manual_price ): bool {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return round( $automatic_price, $decimals ) > round( $manual_price, $decimals );
	}

	/**
	 * Builds a consistent resolution result.
	 *
	 * @return array{price:float,manual_active:bool,manual_overridden:bool,manual_price:float|null,automatic_price:float}
	 */
	private static function result( float $price, bool $manual_active, bool $manual_overridden, ?float $manual_price, float $automatic_price ): array {
		return array(
			'price'              => $price,
			'manual_active'      => $manual_active,
			'manual_overridden'  => $manual_overridden,
			'manual_price'       => $manual_price,
			'automatic_price'    => $automatic_price,
		);
	}
}
