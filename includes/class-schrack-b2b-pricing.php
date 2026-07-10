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
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 30, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price_html' ), 30, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'cart_item_subtotal_html' ), 30, 3 );
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
	 * Adds the full price next to discounted B2B product prices.
	 *
	 * @param string     $price_html Product price HTML.
	 * @param WC_Product $product Product object.
	 */
	public function price_html( string $price_html, WC_Product $product ): string {
		$price_html = $this->append_full_price_html( $price_html, $product );

		return $this->append_measurement_unit_html( $price_html, $product );
	}

	/**
	 * Adds the full B2B price when applicable and the measurement unit in cart
	 * unit-price rows.
	 *
	 * @param string              $price_html Cart item unit price HTML.
	 * @param array<string,mixed> $cart_item Cart item data.
	 * @param string              $cart_item_key Cart item key.
	 */
	public function cart_item_price_html( string $price_html, array $cart_item, string $cart_item_key ): string {
		unset( $cart_item_key );

		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return $price_html;
		}

		$price_html = $this->append_full_price_html( $price_html, $product );

		return $this->append_measurement_unit_html( $price_html, $product );
	}

	/**
	 * Appends the supplier sales unit to displayed unit prices, e.g.
	 * "301,60 lei / m.". Line subtotals intentionally remain totals without a
	 * unit suffix.
	 */
	private function append_measurement_unit_html( string $price_html, WC_Product $product ): string {
		if (
			$this->should_skip_pricing() ||
			'' === trim( wp_strip_all_tags( $price_html ) ) ||
			false !== strpos( $price_html, 'schrack-price-unit' )
		) {
			return $price_html;
		}

		$unit = $this->product_measurement_unit( $product );

		if ( '' === $unit ) {
			return $price_html;
		}

		return sprintf(
			'%1$s<span class="schrack-price-unit" aria-label="%2$s"> / %3$s</span>',
			$price_html,
			esc_attr__( 'Unitate de masura', 'schrack-woocommerce-sync' ),
			esc_html( $unit )
		);
	}

	/**
	 * Reads the source-specific imported sales unit, falling back from a
	 * variation to its parent product when needed.
	 */
	private function product_measurement_unit( WC_Product $product ): string {
		$source = sanitize_key( (string) $product->get_meta( '_schrack_catalog_source', true ) );
		$source = '' !== $source ? $source : 'schrack';
		$key    = 'schrack' === $source ? '_schrack_unit' : '_' . $source . '_unit';
		$unit   = $product->get_meta( $key, true );

		if ( ( ! is_scalar( $unit ) || '' === trim( (string) $unit ) ) && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent instanceof WC_Product ) {
				return $this->product_measurement_unit( $parent );
			}
		}

		return is_scalar( $unit ) ? sanitize_text_field( trim( (string) $unit ) ) : '';
	}

	/**
	 * Adds the full line subtotal in cart rows for B2B users.
	 *
	 * @param string              $subtotal_html Cart item subtotal HTML.
	 * @param array<string,mixed> $cart_item Cart item data.
	 * @param string              $cart_item_key Cart item key.
	 */
	public function cart_item_subtotal_html( string $subtotal_html, array $cart_item, string $cart_item_key ): string {
		unset( $cart_item_key );

		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return $subtotal_html;
		}

		$quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

		return $this->append_full_price_html(
			$subtotal_html,
			$product,
			__( 'Total intreg:', 'schrack-woocommerce-sync' ),
			$quantity
		);
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
	 * Appends the full non-B2B price when a B2B discount is active.
	 */
	private function append_full_price_html( string $price_html, WC_Product $product, ?string $label = null, int $quantity = 1 ): string {
		if ( $this->should_skip_pricing() || false !== strpos( $price_html, 'schrack-b2b-price' ) ) {
			return $price_html;
		}

		if ( '' === trim( wp_strip_all_tags( $price_html ) ) || $this->current_user_discount_percent() <= 0.0 ) {
			return $price_html;
		}

		if ( 1 === $quantity && false !== stripos( $price_html, '<del' ) ) {
			return $price_html;
		}

		$full_bounds    = $this->full_display_price_bounds( $product, $quantity );
		$current_bounds = $this->current_display_price_bounds( $product, $quantity );

		if ( null === $full_bounds || null === $current_bounds || $full_bounds[1] <= ( $current_bounds[1] + 0.0001 ) ) {
			return $price_html;
		}

		$label           = $label ?: __( 'Pret intreg:', 'schrack-woocommerce-sync' );
		$full_price_html = $this->format_price_bounds( $full_bounds );

		if ( '' === $full_price_html ) {
			return $price_html;
		}

		return sprintf(
			'<span class="schrack-b2b-price"><span class="schrack-b2b-price__current">%1$s</span><br class="schrack-b2b-price__break"><span class="schrack-b2b-price__full">%2$s <del>%3$s</del></span></span>',
			wp_kses_post( $price_html ),
			esc_html( $label ),
			wp_kses_post( $full_price_html )
		);
	}

	/**
	 * Returns the current discounted display price bounds.
	 *
	 * @return array{0:float,1:float}|null
	 */
	private function current_display_price_bounds( WC_Product $product, int $quantity = 1 ): ?array {
		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_prices' ) ) {
			$prices = $product->get_variation_prices( true );
			$values = isset( $prices['price'] ) && is_array( $prices['price'] )
				? array_filter( array_map( 'floatval', $prices['price'] ), static fn( float $price ): bool => $price > 0.0 )
				: array();

			if ( empty( $values ) ) {
				return null;
			}

			return array( min( $values ) * $quantity, max( $values ) * $quantity );
		}

		$price = $product->get_price();

		if ( ! is_numeric( $price ) ) {
			return null;
		}

		$display_price = $this->price_to_display( $product, (float) $price, $quantity );

		if ( $display_price <= 0.0 ) {
			return null;
		}

		return array( $display_price, $display_price );
	}

	/**
	 * Returns the full, non-B2B display price bounds.
	 *
	 * @return array{0:float,1:float}|null
	 */
	private function full_display_price_bounds( WC_Product $product, int $quantity = 1 ): ?array {
		if ( $product->is_type( 'variable' ) ) {
			$children = method_exists( $product, 'get_visible_children' ) ? $product->get_visible_children() : $product->get_children();
			$values   = array();

			foreach ( $children as $child_id ) {
				$variation = wc_get_product( (int) $child_id );

				if ( ! $variation instanceof WC_Product ) {
					continue;
				}

				$raw_price = $this->raw_reference_price( $variation );

				if ( $raw_price <= 0.0 ) {
					continue;
				}

				$values[] = $this->price_to_display( $variation, $raw_price, $quantity );
			}

			if ( empty( $values ) ) {
				return null;
			}

			return array( min( $values ), max( $values ) );
		}

		$raw_price = $this->raw_reference_price( $product );

		if ( $raw_price <= 0.0 ) {
			return null;
		}

		$display_price = $this->price_to_display( $product, $raw_price, $quantity );

		if ( $display_price <= 0.0 ) {
			return null;
		}

		return array( $display_price, $display_price );
	}

	/**
	 * Returns the product's raw full price before B2B filters run.
	 */
	private function raw_reference_price( WC_Product $product ): float {
		foreach ( array( '_regular_price', '_price' ) as $meta_key ) {
			$price = get_post_meta( $product->get_id(), $meta_key, true );

			if ( is_numeric( $price ) && (float) $price > 0.0 ) {
				return (float) $price;
			}
		}

		return 0.0;
	}

	/**
	 * Converts a raw price to the current WooCommerce display mode.
	 */
	private function price_to_display( WC_Product $product, float $price, int $quantity = 1 ): float {
		if ( function_exists( 'wc_get_price_to_display' ) ) {
			return (float) wc_get_price_to_display(
				$product,
				array(
					'price' => $price,
					'qty'   => max( 1, $quantity ),
				)
			);
		}

		return $price * max( 1, $quantity );
	}

	/**
	 * Formats a single price or price range.
	 *
	 * @param array{0:float,1:float} $bounds Price min/max values.
	 */
	private function format_price_bounds( array $bounds ): string {
		$min = (float) $bounds[0];
		$max = (float) $bounds[1];

		if ( $min <= 0.0 || $max <= 0.0 ) {
			return '';
		}

		if ( abs( $max - $min ) < 0.0001 ) {
			return function_exists( 'wc_price' ) ? wc_price( $min ) : number_format( $min, 2, '.', '' );
		}

		return function_exists( 'wc_format_price_range' )
			? wc_format_price_range( $min, $max )
			: sprintf( '%1$s - %2$s', number_format( $min, 2, '.', '' ), number_format( $max, 2, '.', '' ) );
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
