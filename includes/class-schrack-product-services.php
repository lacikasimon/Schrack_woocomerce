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

	/**
	 * Uses WooCommerce's linked-products panel and product save lifecycle.
	 */
	public function init(): void {
		add_action( 'woocommerce_product_options_related', array( $this, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
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
		?>
		<div class="options_group">
			<p class="form-field">
				<label for="schrack_recommended_service_ids"><?php esc_html_e( 'Servicii recomandate', 'schrack-woocommerce-sync' ); ?></label>
				<select class="wc-product-search" multiple="multiple" style="width: 50%;" id="schrack_recommended_service_ids" name="<?php echo esc_attr( self::META_KEY ); ?>[]" data-sortable="true" data-placeholder="<?php esc_attr_e( 'Caută un serviciu după nume sau SKU…', 'schrack-woocommerce-sync' ); ?>" data-action="woocommerce_json_search_products" data-exclude="<?php echo esc_attr( (string) $product_object->get_id() ); ?>">
					<?php foreach ( self::normalize_ids( $product_object->get_meta( self::META_KEY, true ), $product_object->get_id() ) as $service_id ) : ?>
						<?php $service = wc_get_product( $service_id ); ?>
						<?php if ( $service instanceof WC_Product ) : ?>
							<option value="<?php echo esc_attr( (string) $service_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $service->get_formatted_name() ) ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( 'Selectează produse care reprezintă servicii, de exemplu montajul unui sistem fotovoltaic. Acestea apar separat pe pagina produsului. Pentru un serviciu fără livrare, bifează „Virtual” în produsul serviciului.', 'schrack-woocommerce-sync' ); ?></span>
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
	 * Renders only published, visible services; WooCommerce supplies current prices.
	 */
	public static function render( WC_Product $product ): string {
		$services = array();
		foreach ( self::normalize_ids( $product->get_meta( self::META_KEY, true ), $product->get_id() ) as $service_id ) {
			$service = wc_get_product( $service_id );
			if ( $service instanceof WC_Product && ! $service->is_type( 'variation' ) && 'publish' === $service->get_status() && $service->is_visible() && ! post_password_required( $service_id ) ) {
				$services[] = $service;
			}
		}

		if ( empty( $services ) ) {
			return '';
		}

		wp_enqueue_style( 'schrack-wc-product-services' );
		ob_start();
		?>
		<section class="schrack-product-services" aria-label="<?php esc_attr_e( 'Servicii recomandate', 'schrack-woocommerce-sync' ); ?>">
			<h2><?php esc_html_e( 'Servicii recomandate', 'schrack-woocommerce-sync' ); ?></h2>
			<p class="schrack-product-services__intro"><?php esc_html_e( 'Completează produsul cu serviciile potrivite proiectului tău. Serviciile se comandă separat.', 'schrack-woocommerce-sync' ); ?></p>
			<div class="schrack-product-services__grid">
				<?php foreach ( $services as $service ) : ?>
					<article class="schrack-product-services__card">
						<div class="schrack-product-services__image"><?php echo wp_kses_post( $service->get_image( 'woocommerce_thumbnail' ) ); ?></div>
						<div class="schrack-product-services__content">
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
