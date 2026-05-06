<?php
/**
 * B2B customer pricing.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_B2B_Pricing {
	/**
	 * Per-request discount cache by user ID.
	 *
	 * @var array<int,float>
	 */
	private array $discount_cache = array();

	/**
	 * Registers pricing hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_product_get_price', array( $this, 'discount_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'discount_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'discount_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'discount_price' ), 20, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'discount_variation_price' ), 20, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'discount_variation_price' ), 20, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'variation_prices_hash' ), 20, 3 );
	}

	/**
	 * Applies the current user's approved B2B discount to a product price.
	 *
	 * @param mixed      $price Product price.
	 * @param WC_Product $product Product object.
	 * @return mixed
	 */
	public function discount_price( mixed $price, WC_Product $product ): mixed {
		unset( $product );

		if ( $this->should_skip_pricing() ) {
			return $price;
		}

		return $this->apply_discount( $price );
	}

	/**
	 * Applies B2B discount inside variable product price arrays.
	 *
	 * @param mixed                $price Variation price.
	 * @param WC_Product_Variation $variation Variation object.
	 * @param WC_Product           $product Parent product.
	 * @return mixed
	 */
	public function discount_variation_price( mixed $price, WC_Product_Variation $variation, WC_Product $product ): mixed {
		unset( $variation, $product );

		if ( $this->should_skip_pricing() ) {
			return $price;
		}

		return $this->apply_discount( $price );
	}

	/**
	 * Adds the current B2B user discount to WooCommerce's variation price cache hash.
	 *
	 * @param array<string,mixed> $hash Price hash.
	 * @param WC_Product          $product Product object.
	 * @param bool                $for_display Whether prices are for display.
	 * @return array<string,mixed>
	 */
	public function variation_prices_hash( array $hash, WC_Product $product, bool $for_display ): array {
		unset( $product, $for_display );

		if ( $this->should_skip_pricing() ) {
			return $hash;
		}

		$discount = $this->current_user_discount_percent();

		if ( $discount <= 0.0 ) {
			return $hash;
		}

		$hash['schrack_b2b_discount'] = $discount;

		return $hash;
	}

	/**
	 * Returns true when pricing hooks should not alter product prices.
	 */
	private function should_skip_pricing(): bool {
		return is_admin() && ! wp_doing_ajax();
	}

	/**
	 * Applies a percentage discount to a raw WooCommerce price value.
	 *
	 * @param mixed $price Raw price.
	 * @return mixed
	 */
	private function apply_discount( mixed $price ): mixed {
		if ( ! is_numeric( $price ) ) {
			return $price;
		}

		$discount = $this->current_user_discount_percent();

		if ( $discount <= 0.0 ) {
			return $price;
		}

		$base_price = (float) $price;

		if ( $base_price <= 0.0 ) {
			return $price;
		}

		$discounted_price = max( 0.0, $base_price * ( 1 - ( $discount / 100 ) ) );
		$decimals         = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return function_exists( 'wc_format_decimal' )
			? wc_format_decimal( $discounted_price, $decimals )
			: number_format( $discounted_price, $decimals, '.', '' );
	}

	/**
	 * Returns the current user's approved B2B discount percent.
	 */
	private function current_user_discount_percent(): float {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return 0.0;
		}

		if ( array_key_exists( $user_id, $this->discount_cache ) ) {
			return $this->discount_cache[ $user_id ];
		}

		$account_type = sanitize_key( (string) get_user_meta( $user_id, '_schrack_account_type', true ) );
		$status       = sanitize_key( (string) get_user_meta( $user_id, '_schrack_b2b_status', true ) );

		if ( 'b2b' !== $account_type || ! in_array( $status, array( 'approved', 'active' ), true ) ) {
			$this->discount_cache[ $user_id ] = 0.0;

			return 0.0;
		}

		$discount = (float) str_replace( ',', '.', (string) get_user_meta( $user_id, '_schrack_b2b_discount_percent', true ) );
		$discount = max( 0.0, min( 100.0, $discount ) );

		$this->discount_cache[ $user_id ] = $discount;

		return $discount;
	}
}
