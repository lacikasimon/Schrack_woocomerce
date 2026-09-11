<?php
/**
 * Manually linked service products for the product page.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Product_Services {
	public const META_KEY = '_schrack_recommended_service_ids';
	public const REQUIRED_META_KEY = '_schrack_required_service_ids';

	/**
	 * Uses WooCommerce's linked-products panel and product save lifecycle.
	 */
	public function init(): void {
		add_action( 'woocommerce_product_options_related', array( $this, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'render_required_notice' ) );
	}

	public function render_required_notice(): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo self::required_notice( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Renders the native AJAX product selector, including saved selections.
	 */
	public function render_field(): void {
		global $product_object;

		if ( ! $product_object instanceof WC_Product ) {
			return;
		}

		wp_nonce_field( 'schrack_save_product_services', 'schrack_product_services_nonce' );
		$this->render_selector( $product_object, self::META_KEY, __( 'Servicii recomandate', 'schrack-woocommerce-sync' ), __( 'Selectează produse care reprezintă servicii, de exemplu montajul unui sistem fotovoltaic. Acestea apar separat pe pagina produsului.', 'schrack-woocommerce-sync' ) );
		echo '<input type="hidden" name="schrack_required_services_present" value="1">';
		$this->render_selector( $product_object, self::REQUIRED_META_KEY, __( 'Servicii obligatorii', 'schrack-woocommerce-sync' ), __( 'Serviciile se adaugă automat în coș, câte o unitate pentru fiecare produs. Eliminarea unui serviciu elimină și produsul. Selectează produse simple, fără alte servicii obligatorii; bifează „Virtual” dacă nu necesită livrare.', 'schrack-woocommerce-sync' ) );
	}

	private function render_selector( WC_Product $product_object, string $key, string $label, string $description ): void {
		$id = ltrim( $key, '_' );
		?>
		<div class="options_group">
			<p class="form-field">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<select class="wc-product-search" multiple="multiple" style="width: 50%;" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>[]" data-sortable="true" data-placeholder="<?php esc_attr_e( 'Caută un serviciu după nume sau SKU…', 'schrack-woocommerce-sync' ); ?>" data-action="woocommerce_json_search_products" data-exclude="<?php echo esc_attr( (string) $product_object->get_id() ); ?>">
					<?php foreach ( self::normalize_ids( $product_object->get_meta( $key, true ), $product_object->get_id() ) as $service_id ) : ?>
						<?php $service = wc_get_product( $service_id ); ?>
						<?php if ( $service instanceof WC_Product ) : ?>
							<option value="<?php echo esc_attr( (string) $service_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $service->get_formatted_name() ) ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php echo esc_html( $description ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Leaves links intact on imports/quick edits; an empty editor selection clears them.
	 */
	public function save( WC_Product $product ): void {
		$nonce = $_POST['schrack_product_services_nonce'] ?? '';

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'schrack_save_product_services' ) || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}

		if ( isset( $_POST['schrack_required_services_present'] ) ) {
			$this->save_required( $product );
		}

		$posted = $_POST[ self::META_KEY ] ?? array();

		if ( ! is_array( $posted ) ) {
			return;
		}

		$ids = array();
		foreach ( self::normalize_ids( wp_unslash( $posted ), $product->get_id() ) as $service_id ) {
			$service = wc_get_product( $service_id );
			if ( $service instanceof WC_Product && ! $service->is_type( 'variation' ) && ! in_array( $service->get_status(), array( 'trash', 'auto-draft' ), true ) ) {
				$ids[] = $service_id;
			}
		}

		if ( empty( $ids ) ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data( self::META_KEY, $ids );
		}
	}

	/**
	 * Renders public services and required charges; WooCommerce supplies current prices.
	 */
	public static function render( WC_Product $product, bool $show_recommended = true ): string {
		$required = self::required_ids( $product );
		$recommended = $show_recommended ? self::normalize_ids( $product->get_meta( self::META_KEY, true ), $product->get_id() ) : array();
		$services = array();
		foreach ( array_unique( array_merge( $required, $recommended ) ) as $service_id ) {
			$service = wc_get_product( $service_id );
			if ( $service instanceof WC_Product && ! $service->is_type( 'variation' ) && 'publish' === $service->get_status() && ( in_array( $service_id, $required, true ) || $service->is_visible() ) && ! post_password_required( $service_id ) ) {
				$services[] = $service;
			}
		}

		if ( empty( $services ) && empty( $required ) ) {
			return '';
		}

		$title = empty( $required ) ? __( 'Servicii recomandate', 'schrack-woocommerce-sync' ) : __( 'Servicii pentru acest produs', 'schrack-woocommerce-sync' );
		wp_enqueue_style( 'schrack-wc-product-services' );
		ob_start();
		?>
		<section class="schrack-product-services" aria-label="<?php echo esc_attr( $title ); ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="schrack-product-services__intro"><?php echo esc_html( empty( $required ) ? __( 'Completează produsul cu serviciile potrivite proiectului tău. Serviciile se comandă separat.', 'schrack-woocommerce-sync' ) : __( 'Serviciile marcate „Obligatoriu” se adaugă automat în coș, iar prețul lor se adaugă la prețul produsului. Celelalte servicii sunt opționale.', 'schrack-woocommerce-sync' ) ); ?></p>
			<div class="schrack-product-services__grid">
				<?php foreach ( $services as $service ) : ?>
					<article class="schrack-product-services__card">
						<div class="schrack-product-services__image"><?php echo wp_kses_post( $service->get_image( 'woocommerce_thumbnail' ) ); ?></div>
						<div class="schrack-product-services__content">
							<?php if ( in_array( $service->get_id(), $required, true ) ) : ?><span class="schrack-product-services__required-badge"><?php esc_html_e( 'Obligatoriu · adăugat automat', 'schrack-woocommerce-sync' ); ?></span><?php endif; ?>
							<h3><a href="<?php echo esc_url( $service->get_permalink() ); ?>"><?php echo esc_html( $service->get_name() ); ?></a></h3>
							<?php $description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $service->get_short_description() ) ), 24 ); ?>
							<?php if ( '' !== $description ) : ?>
								<p class="schrack-product-services__description"><?php echo esc_html( $description ); ?></p>
							<?php endif; ?>
							<div class="schrack-product-services__price"><?php echo wp_kses_post( $service->get_price_html() ); ?></div>
							<a class="schrack-product-services__link" href="<?php echo esc_url( $service->get_permalink() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Vezi serviciul: %s', 'schrack-woocommerce-sync' ), $service->get_name() ) ); ?>"><?php esc_html_e( 'Vezi serviciul', 'schrack-woocommerce-sync' ); ?> <span aria-hidden="true">→</span></a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}
	/** Required links are inherited by each variation of a configured product. */
	public static function required_ids( WC_Product $product ): array {
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		return $product instanceof WC_Product ? self::normalize_ids( $product->get_meta( self::REQUIRED_META_KEY, true ), 0 ) : array();
	}

	private function save_required( WC_Product $product ): void {
		$posted = $_POST[ self::REQUIRED_META_KEY ] ?? array();
		if ( ! is_array( $posted ) ) { return; }
		$ids = self::normalize_ids( wp_unslash( $posted ), 0 );
		$valid = empty( $ids ) || $product->is_type( 'simple' ) || $product->is_type( 'variable' );
		foreach ( $ids as $id ) {
			$service = wc_get_product( $id );
			if ( $id === $product->get_id() || ! $service instanceof WC_Product || ! $service->is_type( 'simple' ) || in_array( $service->get_status(), array( 'trash', 'auto-draft' ), true ) || ! empty( self::required_ids( $service ) ) ) {
				$valid = false;
			}
		}
		if ( ! $valid ) {
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				WC_Admin_Meta_Boxes::add_error( __( 'Serviciile obligatorii nu au fost modificate. Folosește un produs simplu sau variabil și selectează servicii simple distincte, fără alte servicii obligatorii.', 'schrack-woocommerce-sync' ) );
			}
			return;
		}
		if ( empty( $ids ) ) {
			$product->delete_meta_data( self::REQUIRED_META_KEY );
		} else {
			$product->update_meta_data( self::REQUIRED_META_KEY, $ids );
		}
	}

	/** Makes the required additions and their prices explicit next to the buy button. */
	public static function required_notice( WC_Product $product ): string {
		$ids = self::required_ids( $product );
		if ( empty( $ids ) ) { return ''; }
		wp_enqueue_style( 'schrack-wc-product-services' );
		wp_enqueue_script( 'schrack-wc-product-services' );
		$items = '';
		foreach ( $ids as $id ) {
			$service = wc_get_product( $id );
			if ( $service instanceof WC_Product && 'publish' === $service->get_status() && ! post_password_required( $id ) ) {
				$items .= '<li><strong>' . esc_html( $service->get_name() ) . '</strong> ' . wp_kses_post( $service->get_price_html() ) . '</li>';
			} else {
				$items .= '<li>' . esc_html__( 'Un serviciu obligatoriu este momentan indisponibil. Produsul nu poate fi comandat.', 'schrack-woocommerce-sync' ) . '</li>';
			}
		}
		return '<div class="schrack-product-services__required-notice" data-required-services data-nonce="' . esc_attr( wp_create_nonce( 'schrack_add_required_product' ) ) . '" data-cart-url="' . esc_url( wc_get_cart_url() ) . '" data-add-url="' . esc_url( WC_AJAX::get_endpoint( 'schrack_add_required_product' ) ) . '"><strong>' . esc_html__( 'Servicii obligatorii — cost suplimentar', 'schrack-woocommerce-sync' ) . '</strong><ul>' . $items . '</ul><p>' . esc_html__( 'Se adaugă automat în coș, câte o unitate pentru fiecare produs. Eliminarea unui serviciu elimină și produsul asociat.', 'schrack-woocommerce-sync' ) . '</p><div data-required-services-result role="status" aria-live="polite"></div></div>';
	}

	/**
	 * Keeps positive product IDs once, in editor order, excluding the source product.
	 *
	 * @return array<int,int>
	 */
	private static function normalize_ids( mixed $value, int $product_id ): array {
		$ids = array();
		foreach ( is_array( $value ) ? $value : array() as $id ) {
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! ctype_digit( (string) $id ) ) {
				continue;
			}
			$id = (int) $id;
			if ( $id > 0 && $id !== $product_id ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}
}
