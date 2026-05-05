<?php
/**
 * One-page WooCommerce cart and checkout renderer for Elementor widgets.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Cart_Checkout_Renderer {
	/**
	 * Renders the cart and checkout module.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		$settings    = $this->sanitize_settings( $settings );
		$instance_id = '' !== $instance_id ? 'schrack-cart-checkout-' . sanitize_html_class( $instance_id ) : wp_unique_id( 'schrack-cart-checkout-' );

		wp_enqueue_style( 'schrack-wc-cart-checkout' );

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return sprintf(
				'<section id="%1$s" class="schrack-cart-checkout" style="%2$s"><div class="schrack-cart-checkout__notice is-error">%3$s</div></section>',
				esc_attr( $instance_id ),
				esc_attr( $this->style_vars( $settings ) ),
				esc_html__( 'WooCommerce nu este disponibil pentru finalizarea comenzii.', 'schrack-woocommerce-sync' )
			);
		}

		$this->ensure_cart();

		$classes = array( 'schrack-cart-checkout' );

		if ( $this->is_cart_empty() ) {
			$classes[] = 'is-empty';
		}

		if ( 'yes' !== $settings['show_coupon'] ) {
			$classes[] = 'hide-coupon';
		}

		if ( 'yes' !== $settings['show_cross_sells'] ) {
			$classes[] = 'hide-cross-sells';
		}

		if ( 'yes' !== $settings['show_cart_totals'] ) {
			$classes[] = 'hide-cart-totals';
		}

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( $this->style_vars( $settings ) ); ?>"
		>
			<div class="schrack-cart-checkout__inner">
				<?php if ( 'yes' === $settings['show_header'] ) : ?>
					<div class="schrack-cart-checkout__hero">
						<div class="schrack-cart-checkout__intro">
							<?php if ( '' !== $settings['eyebrow'] ) : ?>
								<div class="schrack-cart-checkout__eyebrow"><?php echo esc_html( $settings['eyebrow'] ); ?></div>
							<?php endif; ?>

							<?php if ( '' !== $settings['title'] ) : ?>
								<h1 class="schrack-cart-checkout__title"><?php echo esc_html( $settings['title'] ); ?></h1>
							<?php endif; ?>

							<?php if ( '' !== $settings['subtitle'] ) : ?>
								<p class="schrack-cart-checkout__subtitle"><?php echo esc_html( $settings['subtitle'] ); ?></p>
							<?php endif; ?>
						</div>

						<?php if ( 'yes' === $settings['show_steps'] ) : ?>
							<?php echo $this->steps(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $this->is_cart_empty() ) : ?>
					<?php echo $this->empty_cart_panel( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<div class="schrack-cart-checkout__layout">
						<div class="schrack-cart-checkout__panel schrack-cart-checkout__panel--cart">
							<div class="schrack-cart-checkout__panel-head">
								<h2><?php echo esc_html( $settings['cart_heading'] ); ?></h2>
								<span><?php echo esc_html( $this->cart_count_label() ); ?></span>
							</div>

							<div class="schrack-cart-checkout__woocommerce-cart">
								<?php echo $this->render_shortcode( 'woocommerce_cart', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</div>

						<div class="schrack-cart-checkout__panel schrack-cart-checkout__panel--checkout">
							<div class="schrack-cart-checkout__panel-head">
								<h2><?php echo esc_html( $settings['checkout_heading'] ); ?></h2>
								<span><?php esc_html_e( 'Comanda securizata', 'schrack-woocommerce-sync' ); ?></span>
							</div>

							<div class="schrack-cart-checkout__woocommerce-checkout">
								<?php echo $this->render_shortcode( 'woocommerce_checkout', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						</div>
					</div>

					<?php if ( 'yes' === $settings['show_continue_shopping'] ) : ?>
						<div class="schrack-cart-checkout__continue">
							<a href="<?php echo esc_url( $this->continue_shopping_url( $settings ) ); ?>"><?php echo esc_html( $settings['continue_shopping_text'] ); ?></a>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Normalizes widget settings.
	 *
	 * @param array<string,mixed> $settings Raw Elementor settings.
	 * @return array<string,string>
	 */
	private function sanitize_settings( array $settings ): array {
		$defaults = array(
			'eyebrow'                => __( 'Comanda online', 'schrack-woocommerce-sync' ),
			'title'                  => __( 'Cos si finalizare comanda', 'schrack-woocommerce-sync' ),
			'subtitle'               => __( 'Verifica produsele, completeaza datele de facturare si trimite comanda in siguranta.', 'schrack-woocommerce-sync' ),
			'cart_heading'           => __( 'Produsele din cos', 'schrack-woocommerce-sync' ),
			'checkout_heading'       => __( 'Date facturare si livrare', 'schrack-woocommerce-sync' ),
			'order_button_text'      => __( 'Trimite comanda', 'schrack-woocommerce-sync' ),
			'continue_shopping_text' => __( 'Continua cumparaturile', 'schrack-woocommerce-sync' ),
			'continue_shopping_url'  => '',
			'empty_title'            => __( 'Cosul tau este gol', 'schrack-woocommerce-sync' ),
			'empty_text'             => __( 'Adauga produse in cos pentru a putea trimite comanda.', 'schrack-woocommerce-sync' ),
			'shop_button_text'       => __( 'Mergi la magazin', 'schrack-woocommerce-sync' ),
			'shop_url'               => '',
			'show_header'            => 'yes',
			'show_steps'             => 'yes',
			'show_continue_shopping' => 'yes',
			'show_coupon'            => 'yes',
			'show_cross_sells'       => 'no',
			'show_cart_totals'       => 'no',
			'accent_color'           => '#135e96',
			'action_color'           => '#b32d2e',
			'max_width'              => '1240',
			'radius'                 => '8',
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'eyebrow', 'title', 'subtitle', 'cart_heading', 'checkout_heading', 'order_button_text', 'continue_shopping_text', 'empty_title', 'empty_text', 'shop_button_text' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_header', 'show_steps', 'show_continue_shopping', 'show_coupon', 'show_cross_sells', 'show_cart_totals' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		$settings['continue_shopping_url'] = esc_url_raw( (string) $settings['continue_shopping_url'] );
		$settings['shop_url']              = esc_url_raw( (string) $settings['shop_url'] );
		$settings['accent_color']          = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['action_color']          = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['max_width']             = (string) max( 720, min( 1440, absint( $settings['max_width'] ) ) );
		$settings['radius']                = (string) max( 0, min( 8, absint( $settings['radius'] ) ) );

		return $settings;
	}

	/**
	 * Returns safe CSS variables.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function style_vars( array $settings ): string {
		return sprintf(
			'--schrack-cart-accent:%1$s;--schrack-cart-action:%2$s;--schrack-cart-width:%3$spx;--schrack-cart-radius:%4$spx;',
			$settings['accent_color'],
			$settings['action_color'],
			$settings['max_width'],
			$settings['radius']
		);
	}

	/**
	 * Loads the WooCommerce cart object when available.
	 */
	private function ensure_cart(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$woocommerce = WC();

		if ( is_object( $woocommerce ) && isset( $woocommerce->cart ) && $woocommerce->cart ) {
			return;
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * Returns whether the WooCommerce cart is empty.
	 */
	private function is_cart_empty(): bool {
		$cart = $this->cart();

		return ! $cart || $cart->is_empty();
	}

	/**
	 * Returns current cart object.
	 */
	private function cart(): ?WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$woocommerce = WC();

		if ( is_object( $woocommerce ) && isset( $woocommerce->cart ) && $woocommerce->cart instanceof WC_Cart ) {
			return $woocommerce->cart;
		}

		return null;
	}

	/**
	 * Returns a compact cart count label.
	 */
	private function cart_count_label(): string {
		$cart  = $this->cart();
		$count = $cart ? (int) $cart->get_cart_contents_count() : 0;

		return sprintf(
			/* translators: %d: cart item count. */
			_n( '%d produs', '%d produse', $count, 'schrack-woocommerce-sync' ),
			$count
		);
	}

	/**
	 * Renders WooCommerce shortcode output.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function render_shortcode( string $shortcode, array $settings ): string {
		if ( ! shortcode_exists( $shortcode ) ) {
			return '';
		}

		$current_url = $this->current_url();
		$url_filter  = static fn( string $url = '' ): string => $current_url;
		$filters     = array();

		if ( 'woocommerce_cart' === $shortcode ) {
			$filters[] = array( 'woocommerce_get_cart_url', $url_filter );
		} elseif ( 'woocommerce_checkout' === $shortcode ) {
			$order_button_text = $settings['order_button_text'] ?? __( 'Trimite comanda', 'schrack-woocommerce-sync' );
			$order_filter      = static fn( string $button_text = '' ): string => $order_button_text;

			$filters[] = array( 'woocommerce_get_checkout_url', $url_filter );
			$filters[] = array( 'woocommerce_order_button_text', $order_filter );
		}

		foreach ( $filters as $filter ) {
			add_filter( $filter[0], $filter[1] );
		}

		try {
			return do_shortcode( '[' . $shortcode . ']' );
		} finally {
			foreach ( $filters as $filter ) {
				remove_filter( $filter[0], $filter[1] );
			}
		}
	}

	/**
	 * Renders the checkout progress labels.
	 */
	private function steps(): string {
		$steps = array(
			__( 'Cos', 'schrack-woocommerce-sync' ),
			__( 'Date comanda', 'schrack-woocommerce-sync' ),
			__( 'Plata', 'schrack-woocommerce-sync' ),
		);

		ob_start();
		?>
		<ol class="schrack-cart-checkout__steps" aria-label="<?php esc_attr_e( 'Pasi comanda', 'schrack-woocommerce-sync' ); ?>">
			<?php foreach ( $steps as $index => $label ) : ?>
				<li>
					<span><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<strong><?php echo esc_html( $label ); ?></strong>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders empty cart state.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function empty_cart_panel( array $settings ): string {
		ob_start();
		?>
		<div class="schrack-cart-checkout__empty">
			<h2><?php echo esc_html( $settings['empty_title'] ); ?></h2>
			<p><?php echo esc_html( $settings['empty_text'] ); ?></p>
			<a href="<?php echo esc_url( $this->shop_url( $settings ) ); ?>"><?php echo esc_html( $settings['shop_button_text'] ); ?></a>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns the continue shopping URL.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function continue_shopping_url( array $settings ): string {
		if ( '' !== $settings['continue_shopping_url'] ) {
			return $settings['continue_shopping_url'];
		}

		return $this->shop_url( $settings );
	}

	/**
	 * Returns shop URL from settings or WooCommerce fallback.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function shop_url( array $settings ): string {
		if ( '' !== $settings['shop_url'] ) {
			return $settings['shop_url'];
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/shop/' );
	}

	/**
	 * Returns the current frontend URL for one-page cart and checkout posts.
	 */
	private function current_url(): string {
		$scheme      = is_ssl() ? 'https://' : 'http://';
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';

		if ( '' === $host ) {
			return home_url( '/' );
		}

		return esc_url_raw(
			remove_query_arg(
				array(
					'add-to-cart',
					'apply_coupon',
					'remove_coupon',
					'removed_item',
					'undo_item',
					'updated_cart',
				),
				$scheme . $host . $request_uri
			)
		);
	}
}
