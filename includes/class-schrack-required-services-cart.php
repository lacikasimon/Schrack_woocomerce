<?php
/**
 * Keeps required service lines together with their owning WooCommerce cart line.
 * Prices, taxes, stock reduction and order lines remain native WooCommerce data.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Required_Services_Cart {
	public const PARENT_KEY = '_schrack_required_parent';
	public const SERVICES_KEY = '_schrack_required_services';
	public const AJAX_ACTION = 'schrack_add_required_product';
	private bool $syncing = false;
	private array $before_add = array();

	public function init(): void {
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_add' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'prepare_add' ), 1000, 4 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'after_add' ), 10, 6 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'quantity_changed' ), 10, 4 );
		add_action( 'woocommerce_remove_cart_item', array( $this, 'remove_group' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'restore_group' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'check_session' ) );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_checkout' ) );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'quantity_html' ), 10, 3 );
	}

	/** Preserve submitted attributes, including WooCommerce variations with an "any" value. */
	public function ajax_add(): void {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'security', false ) ) {
			wp_send_json( array( 'error' => true, 'message' => __( 'Sesiunea paginii a expirat. Redeschide pagina produsului și încearcă din nou.', 'schrack-woocommerce-sync' ) ), 403 );
		}
		$id = isset( $_POST['product_id'] ) && is_scalar( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity = isset( $_POST['quantity'] ) && is_scalar( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1;
		$product = wc_get_product( $id );
		$variation_id = 0;
		$variation = array();
		try {
			if ( ! $product instanceof WC_Product || $quantity <= 0 || empty( Schrack_Product_Services::required_ids( $product ) ) ) {
				throw $this->unavailable_error();
			}
			if ( $product->is_type( 'variation' ) ) {
				$variation_id = $id;
				$id = $product->get_parent_id();
				foreach ( $_POST as $key => $value ) {
					if ( str_starts_with( (string) $key, 'attribute_' ) && is_string( $value ) ) {
						$variation[ sanitize_key( $key ) ] = wc_clean( wp_unslash( $value ) );
					}
				}
			}
			if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $id, $quantity, $variation_id, $variation ) || ! WC()->cart->add_to_cart( $id, $quantity, $variation_id, $variation ) ) {
				throw $this->unavailable_error();
			}
			WC_AJAX::get_refreshed_fragments();
		} catch ( Exception $error ) {
			$notices = wc_get_notices( 'error' );
			$message = $notices ? implode( ' ', array_map( static fn( $notice ) => wp_strip_all_tags( $notice['notice'] ), $notices ) ) : $error->getMessage();
			$all_notices = wc_get_notices();
			unset( $all_notices['error'] );
			wc_set_notices( $all_notices );
			wp_send_json( array( 'error' => true, 'message' => $message ) );
		}
	}

	/** Shared by native WC_Cart and Store API additions before the cart is changed. */
	public function prepare_add( array $data, int $product_id, int $variation_id, $quantity ): array {
		if ( $this->syncing ) {
			return $data;
		}
		// Only this class may create trusted child links or a required-services manifest.
		unset( $data[ self::PARENT_KEY ], $data[ self::SERVICES_KEY ] );
		$product = wc_get_product( $variation_id ?: $product_id );
		$ids = $product instanceof WC_Product ? Schrack_Product_Services::required_ids( $product ) : array();
		if ( $ids ) {
			$this->assert_services( $product, $ids );
			$data[ self::SERVICES_KEY ] = $ids;
			$this->before_add = WC()->cart->get_cart_contents();
		}
		return $data;
	}

	public function after_add( string $key, int $product_id, $quantity, int $variation_id, array $variation, array $data ): void {
		if ( $this->syncing || empty( $data[ self::SERVICES_KEY ] ) ) {
			return;
		}
		$cart = WC()->cart;
		try {
			$expected = (float) ( $this->before_add[ $key ]['quantity'] ?? 0 ) + (float) $quantity;
			if ( (float) ( $cart->get_cart_contents()[ $key ]['quantity'] ?? 0 ) !== $expected ) {
				throw $this->unavailable_error();
			}
			$this->sync_services( $key, $cart );
		} catch ( Exception $error ) {
			$cart->set_cart_contents( $this->before_add );
			$cart->calculate_totals();
			throw $error; // WC_Cart returns false and displays the error; no partial purchase.
		}
	}

	public function quantity_changed( string $key, $quantity, $old_quantity, WC_Cart $cart ): void {
		if ( $this->syncing ) {
			return;
		}
		$items = $cart->get_cart_contents();
		$item = $items[ $key ] ?? array();
		if ( ! empty( $item[ self::PARENT_KEY ] ) ) {
			$parent = $items[ $item[ self::PARENT_KEY ] ] ?? null;
			if ( ! $parent ) {
				$cart->remove_cart_item( $key );
				return;
			}
			$this->syncing = true;
			try {
				$cart->set_quantity( $key, $parent['quantity'], false );
			} finally {
				$this->syncing = false;
			}
			return;
		}
		if ( empty( $item[ self::SERVICES_KEY ] ) ) {
			return;
		}
		$items[ $key ]['quantity'] = $old_quantity;
		try {
			$this->sync_services( $key, $cart );
		} catch ( Exception $error ) {
			$cart->set_cart_contents( $items );
			$cart->calculate_totals();
			if ( 'store-api' === $cart->cart_context ) {
				throw $error;
			}
			wc_add_notice( $error->getMessage(), 'error' );
		}
	}

	/** Runs before WC removes the selected line. The guard prevents recursive cascades. */
	public function remove_group( string $key, WC_Cart $cart ): void {
		if ( $this->syncing ) {
			return;
		}
		$items = $cart->get_cart_contents();
		$root = $items[ $key ][ self::PARENT_KEY ] ?? $key;
		$this->syncing = true;
		try {
			foreach ( $items as $other_key => $item ) {
				if ( $other_key !== $key && ( $other_key === $root || ( $item[ self::PARENT_KEY ] ?? '' ) === $root ) ) {
					$cart->remove_cart_item( $other_key );
				}
			}
		} finally {
			$this->syncing = false;
		}
	}

	public function restore_group( string $key, WC_Cart $cart ): void {
		if ( $this->syncing ) {
			return;
		}
		$items = $cart->get_cart_contents();
		$root = $items[ $key ][ self::PARENT_KEY ] ?? $key;
		$this->syncing = true;
		try {
			foreach ( $cart->get_removed_cart_contents() as $other_key => $item ) {
				if ( $other_key !== $key && ( $other_key === $root || ( $item[ self::PARENT_KEY ] ?? '' ) === $root ) ) {
					$cart->restore_cart_item( $other_key );
				}
			}
		} finally {
			$this->syncing = false;
		}
		try {
			$this->assert_complete( $root, $cart );
			$this->sync_services( $root, $cart );
		} catch ( Exception $error ) {
			$cart->remove_cart_item( $key );
			wc_add_notice( $error->getMessage(), 'error' );
		}
	}

	/** Never silently introduce a newly chargeable service when reopening an old cart. */
	public function check_session( WC_Cart $cart ): void {
		foreach ( $cart->get_cart_contents() as $key => $item ) {
			if ( ! isset( $cart->get_cart_contents()[ $key ] ) ) {
				continue;
			}
			try {
				$this->assert_complete( $key, $cart );
			} catch ( Exception $error ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( __( 'Un produs și serviciile sale obligatorii au fost eliminate deoarece configurația nu mai este disponibilă. Adaugă din nou produsul de pe pagina sa.', 'schrack-woocommerce-sync' ), 'notice' );
			}
		}
	}

	public function check_checkout(): void {
		foreach ( WC()->cart->get_cart_contents() as $key => $item ) {
			try {
				$this->assert_complete( $key, WC()->cart );
			} catch ( Exception $error ) {
				wc_add_notice( $error->getMessage(), 'error' );
				return;
			}
		}
	}

	public function item_data( array $data, array $item ): array {
		$parent = WC()->cart ? ( WC()->cart->get_cart_contents()[ $item[ self::PARENT_KEY ] ?? '' ] ?? null ) : null;
		if ( $parent ) {
			$data[] = array( 'key' => __( 'Serviciu obligatoriu pentru', 'schrack-woocommerce-sync' ), 'value' => $parent['data']->get_name() );
			$data[] = array( 'key' => __( 'Eliminare', 'schrack-woocommerce-sync' ), 'value' => __( 'Elimină și produsul asociat din coș.', 'schrack-woocommerce-sync' ) );
		}
		return $data;
	}

	public function quantity_html( string $html, string $key, array $item ): string {
		if ( empty( $item[ self::PARENT_KEY ] ) ) {
			return $html;
		}
		return '<span class="schrack-required-service-quantity">' . esc_html( (string) $item['quantity'] ) . '</span><input type="hidden" name="cart[' . esc_attr( $key ) . '][qty]" value="' . esc_attr( (string) $item['quantity'] ) . '"><small>' . esc_html__( 'Urmează cantitatea produsului', 'schrack-woocommerce-sync' ) . '</small>';
	}

	private function sync_services( string $key, WC_Cart $cart ): void {
		$items = $cart->get_cart_contents();
		$parent = $items[ $key ] ?? null;
		if ( ! $parent || ! $parent['data'] instanceof WC_Product ) {
			throw $this->unavailable_error();
		}
		$ids = Schrack_Product_Services::required_ids( $parent['data'] );
		if ( $ids !== ( $parent[ self::SERVICES_KEY ] ?? array() ) ) {
			throw $this->unavailable_error();
		}
		$this->assert_services( $parent['data'], $ids );
		$children = $this->children( $key, $items );
		$quantity = $parent['quantity'];
		// Validate every service before modifying any line, including aggregate cart stock.
		foreach ( $ids as $id ) {
			$service = wc_get_product( $id );
			$other_quantity = 0;
			foreach ( $items as $other_key => $item ) {
				if ( $other_key !== ( $children[ $id ] ?? '' ) && $item['data']->get_stock_managed_by_id() === $service->get_stock_managed_by_id() ) {
					$other_quantity += $item['quantity'];
				}
			}
			if ( ! $service->has_enough_stock( $quantity + $other_quantity ) || ( $service->is_sold_individually() && $quantity + $other_quantity > 1 ) || $quantity < $service->get_min_purchase_quantity() || ( $service->get_max_purchase_quantity() > 0 && $quantity > $service->get_max_purchase_quantity() ) ) {
				throw $this->unavailable_error();
			}
			$delta = $quantity - ( $items[ $children[ $id ] ?? '' ]['quantity'] ?? 0 );
			if ( $delta > 0 && ! apply_filters( 'woocommerce_add_to_cart_validation', true, $id, $delta ) ) {
				throw $this->unavailable_error();
			}
		}
		$this->syncing = true;
		try {
			foreach ( $ids as $id ) {
				if ( isset( $children[ $id ] ) ) {
					$cart->set_quantity( $children[ $id ], $quantity, false );
				} elseif ( ! $cart->add_to_cart( $id, $quantity, 0, array(), array( self::PARENT_KEY => $key ) ) ) {
					throw $this->unavailable_error();
				}
			}
		} finally {
			$this->syncing = false;
		}
	}

	private function assert_services( WC_Product $product, array $ids ): void {
		if ( $ids && ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
			throw $this->unavailable_error();
		}
		foreach ( $ids as $id ) {
			$service = wc_get_product( $id );
			if ( $id === $product->get_id() || ! $service instanceof WC_Product || ! $service->is_type( 'simple' ) || 'publish' !== $service->get_status() || post_password_required( $id ) || ! $service->is_purchasable() || ! $service->is_in_stock() || Schrack_Product_Services::required_ids( $service ) ) {
				throw $this->unavailable_error();
			}
		}
	}

	private function children( string $key, array $items ): array {
		$children = array();
		foreach ( $items as $child_key => $item ) {
			if ( ( $item[ self::PARENT_KEY ] ?? '' ) === $key ) {
				$id = (int) $item['product_id'];
				if ( isset( $children[ $id ] ) || ! empty( $item['variation_id'] ) ) {
					throw $this->unavailable_error();
				}
				$children[ $id ] = $child_key;
			}
		}
		return $children;
	}

	private function assert_complete( string $key, WC_Cart $cart ): void {
		$items = $cart->get_cart_contents();
		$item = $items[ $key ] ?? null;
		if ( ! $item || ! $item['data'] instanceof WC_Product ) {
			throw $this->unavailable_error();
		}
		if ( ! empty( $item[ self::PARENT_KEY ] ) ) {
			$root = $items[ $item[ self::PARENT_KEY ] ] ?? null;
			if ( ! $root || ! empty( $root[ self::PARENT_KEY ] ) || ! in_array( (int) $item['product_id'], $root[ self::SERVICES_KEY ] ?? array(), true ) || (float) $item['quantity'] !== (float) $root['quantity'] ) {
				throw $this->unavailable_error();
			}
			return;
		}
		$ids = Schrack_Product_Services::required_ids( $item['data'] );
		$this->assert_services( $item['data'], $ids );
		$children = $this->children( $key, $items );
		if ( $ids !== ( $item[ self::SERVICES_KEY ] ?? array() ) || count( $children ) !== count( $ids ) ) {
			throw $this->unavailable_error();
		}
		foreach ( $ids as $id ) {
			if ( ! isset( $children[ $id ] ) || (float) $items[ $children[ $id ] ]['quantity'] !== (float) $item['quantity'] ) {
				throw $this->unavailable_error();
			}
		}
	}

	private function unavailable_error(): Exception {
		if ( 'store-api' === WC()->cart->cart_context && class_exists( 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			return new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'schrack_required_services', $this->unavailable_message(), 400 );
		}
		return new Exception( $this->unavailable_message() );
	}

	private function unavailable_message(): string {
		return __( 'Produsul poate fi cumpărat doar împreună cu toate serviciile obligatorii, în aceeași cantitate. Verifică disponibilitatea serviciilor și adaugă din nou produsul.', 'schrack-woocommerce-sync' );
	}
}
