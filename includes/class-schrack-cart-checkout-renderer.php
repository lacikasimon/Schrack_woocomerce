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

		$is_order_received = 'order_received' === $settings['render_mode'] || $this->is_order_received_request();
		$is_order_pay      = ! $is_order_received && ( 'order_pay' === $settings['render_mode'] || $this->is_order_pay_request() );
		$step_mode         = $is_order_received ? 'order_received' : ( $is_order_pay ? 'order_pay' : 'cart_checkout' );
		$hero_eyebrow      = $is_order_received ? $settings['order_received_eyebrow'] : $settings['eyebrow'];
		$hero_title        = $is_order_received ? $settings['order_received_title'] : $settings['title'];
		$hero_subtitle     = $is_order_received ? $settings['order_received_subtitle'] : $settings['subtitle'];
		$classes           = array( 'schrack-cart-checkout' );

		if ( $is_order_received ) {
			$classes[] = 'is-order-received';
		} elseif ( $is_order_pay ) {
			$classes[] = 'is-order-pay';
		} elseif ( $this->is_cart_empty() ) {
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
							<?php if ( '' !== $hero_eyebrow ) : ?>
								<div class="schrack-cart-checkout__eyebrow"><?php echo esc_html( $hero_eyebrow ); ?></div>
							<?php endif; ?>

							<?php if ( '' !== $hero_title ) : ?>
								<h1 class="schrack-cart-checkout__title"><?php echo esc_html( $hero_title ); ?></h1>
							<?php endif; ?>

							<?php if ( '' !== $hero_subtitle ) : ?>
								<p class="schrack-cart-checkout__subtitle"><?php echo esc_html( $hero_subtitle ); ?></p>
							<?php endif; ?>
						</div>

						<?php if ( 'yes' === $settings['show_steps'] ) : ?>
							<?php echo $this->steps( $step_mode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $is_order_received ) : ?>
					<?php echo $this->order_received_panel( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php elseif ( $is_order_pay ) : ?>
					<?php echo $this->order_pay_panel( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php elseif ( $this->is_cart_empty() ) : ?>
					<?php echo $this->empty_cart_panel( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<div class="schrack-cart-checkout__layout">
						<div class="schrack-cart-checkout__cart-column">
							<div class="schrack-cart-checkout__panel schrack-cart-checkout__panel--cart">
								<div class="schrack-cart-checkout__panel-head">
									<h2><?php echo esc_html( $settings['cart_heading'] ); ?></h2>
									<span><?php echo esc_html( $this->cart_count_label() ); ?></span>
								</div>

								<div class="schrack-cart-checkout__woocommerce-cart">
									<?php echo $this->render_shortcode( 'woocommerce_cart', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</div>

							<?php if ( 'yes' === $settings['show_continue_shopping'] ) : ?>
								<div class="schrack-cart-checkout__continue">
									<a href="<?php echo esc_url( $this->continue_shopping_url( $settings ) ); ?>"><?php echo esc_html( $settings['continue_shopping_text'] ); ?></a>
								</div>
							<?php endif; ?>
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
			'order_pay_heading'      => __( 'Finalizeaza plata', 'schrack-woocommerce-sync' ),
			'order_pay_badge'        => __( 'Plata securizata', 'schrack-woocommerce-sync' ),
			'order_pay_button_text'  => __( 'Plateste comanda', 'schrack-woocommerce-sync' ),
			'order_received_eyebrow'  => __( 'Comanda primita', 'schrack-woocommerce-sync' ),
			'order_received_title'    => __( 'Multumim pentru comanda', 'schrack-woocommerce-sync' ),
			'order_received_subtitle' => __( 'Am primit comanda ta. Mai jos gasesti detaliile comenzii si informatiile pentru confirmare.', 'schrack-woocommerce-sync' ),
			'order_received_heading'  => __( 'Detalii comanda', 'schrack-woocommerce-sync' ),
			'order_received_badge'    => __( 'Confirmare trimisa', 'schrack-woocommerce-sync' ),
			'continue_shopping_text'  => __( 'Continua cumparaturile', 'schrack-woocommerce-sync' ),
			'continue_shopping_url'   => '',
			'empty_title'             => __( 'Cosul tau este gol', 'schrack-woocommerce-sync' ),
			'empty_text'              => __( 'Adauga produse in cos pentru a putea trimite comanda.', 'schrack-woocommerce-sync' ),
			'shop_button_text'        => __( 'Mergi la magazin', 'schrack-woocommerce-sync' ),
			'shop_url'                => '',
			'render_mode'             => 'cart_checkout',
			'show_header'             => 'yes',
			'show_steps'              => 'yes',
			'show_continue_shopping'  => 'yes',
			'show_coupon'             => 'yes',
			'show_cross_sells'        => 'no',
			'show_cart_totals'        => 'no',
			'accent_color'            => '#135e96',
			'action_color'            => '#b32d2e',
			'max_width'               => '1240',
			'radius'                  => '8',
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach (
			array(
				'eyebrow',
				'title',
				'subtitle',
				'cart_heading',
				'checkout_heading',
				'order_button_text',
				'order_pay_heading',
				'order_pay_badge',
				'order_pay_button_text',
				'order_received_eyebrow',
				'order_received_title',
				'order_received_subtitle',
				'order_received_heading',
				'order_received_badge',
				'continue_shopping_text',
				'empty_title',
				'empty_text',
				'shop_button_text',
			) as $key
		) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		$render_mode = (string) $settings['render_mode'];
		$settings['render_mode'] = in_array( $render_mode, array( 'cart_checkout', 'order_pay', 'order_received' ), true ) ? $render_mode : 'cart_checkout';

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

		$current_url            = $this->current_url();
		$url_filter             = static fn( string $url = '' ): string => $current_url;
		$gettext_filter         = fn( string $translation, string $text, string $domain ): string => $this->translate_woocommerce_text( $translation, $text, $domain );
		$ngettext_filter        = fn( string $translation, string $single, string $plural, int $number, string $domain ): string => $this->translate_woocommerce_plural( $translation, $single, $plural, $number, $domain );
		$checkout_fields_filter = fn( array $fields ): array => $this->romanian_checkout_fields( $fields );
		$address_fields_filter  = fn( array $fields ): array => $this->romanian_default_address_fields( $fields );
		$filters                = array(
			array( 'gettext', $gettext_filter, 10, 3 ),
			array( 'ngettext', $ngettext_filter, 10, 5 ),
		);
		$proceed_button_priority = false;

		if ( 'woocommerce_cart' === $shortcode ) {
			$filters[] = array( 'woocommerce_get_cart_url', $url_filter, 10, 1 );
			$proceed_button_priority = has_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout' );

			if ( false !== $proceed_button_priority ) {
				remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', $proceed_button_priority );
			}
		} elseif ( 'woocommerce_checkout' === $shortcode ) {
			$order_button_text     = $settings['order_button_text'] ?? __( 'Trimite comanda', 'schrack-woocommerce-sync' );
			$order_pay_button_text = $settings['order_pay_button_text'] ?? __( 'Plateste comanda', 'schrack-woocommerce-sync' );
			$order_filter          = static fn( string $button_text = '' ): string => $order_button_text;
			$order_pay_filter      = static fn( string $button_text = '' ): string => $order_pay_button_text;

			$filters[] = array( 'woocommerce_get_checkout_url', $url_filter, 10, 1 );
			$filters[] = array( 'woocommerce_order_button_text', $order_filter, 10, 1 );
			$filters[] = array( 'woocommerce_pay_order_button_text', $order_pay_filter, 10, 1 );
			$filters[] = array( 'woocommerce_checkout_fields', $checkout_fields_filter, 10, 1 );
			$filters[] = array( 'woocommerce_default_address_fields', $address_fields_filter, 10, 1 );
		}

		foreach ( $filters as $filter ) {
			add_filter( $filter[0], $filter[1], $filter[2], $filter[3] );
		}

		try {
			return do_shortcode( '[' . $shortcode . ']' );
		} finally {
			if ( false !== $proceed_button_priority ) {
				add_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', $proceed_button_priority );
			}

			foreach ( $filters as $filter ) {
				remove_filter( $filter[0], $filter[1], $filter[2] );
			}
		}
	}

	/**
	 * Returns Romanian text for common WooCommerce cart and checkout strings.
	 */
	private function translate_woocommerce_text( string $translation, string $text, string $domain ): string {
		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}

		$map = $this->woocommerce_text_map();

		return $map[ $text ] ?? $translation;
	}

	/**
	 * Returns Romanian plural strings for WooCommerce cart fragments.
	 */
	private function translate_woocommerce_plural( string $translation, string $single, string $plural, int $number, string $domain ): string {
		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}

		if ( '%d product' === $single && '%d products' === $plural ) {
			return sprintf( 1 === $number ? '%d produs' : '%d produse', $number );
		}

		if ( '%d item' === $single && '%d items' === $plural ) {
			return sprintf( 1 === $number ? '%d articol' : '%d articole', $number );
		}

		return $translation;
	}

	/**
	 * Returns common WooCommerce English to Romanian labels used by this widget.
	 *
	 * @return array<string,string>
	 */
	private function woocommerce_text_map(): array {
		return array(
			'Product' => 'Produs',
			'Price' => 'Pret',
			'Quantity' => 'Cantitate',
			'Subtotal' => 'Subtotal',
			'Sub-total' => 'Subtotal',
			'Total' => 'Total',
			'Cart totals' => 'Total cos',
			'Cart updated.' => 'Cosul a fost actualizat.',
			'Coupon code' => 'Cod cupon',
			'Coupon:' => 'Cupon:',
			'Apply coupon' => 'Aplica cuponul',
			'Update cart' => 'Actualizeaza cosul',
			'Continue to checkout' => 'Continua cu finalizarea comenzii',
			'Have a coupon?' => 'Ai un cupon?',
			'Click here to enter your code' => 'Click aici pentru a introduce codul',
			'If you have a coupon code, please apply it below.' => 'Daca ai un cod de cupon, introdu-l mai jos.',
			'Billing details' => 'Detalii pentru facturare',
			'Shipping details' => 'Detalii livrare',
			'Additional information' => 'Informatii suplimentare',
			'Your order' => 'Comanda ta',
			'Payment' => 'Plata',
			'Place order' => 'Trimite comanda',
			'Pay for order' => 'Plateste comanda',
			'Order received' => 'Comanda primita',
			'Thank you. Your order has been received.' => 'Iti multumim. Comanda ta a fost primita.',
			'Order details' => 'Detalii comanda',
			'Order number:' => 'Numar comanda:',
			'Date:' => 'Data:',
			'Email:' => 'Email:',
			'Payment method:' => 'Metoda de plata:',
			'Billing address' => 'Adresa de facturare',
			'Shipping address' => 'Adresa de livrare',
			'Customer details' => 'Detalii client',
			'Note:' => 'Nota:',
			'Order again' => 'Comanda din nou',
			'Our bank details' => 'Detaliile noastre bancare',
			'Bank:' => 'Banca:',
			'Account number:' => 'Numar cont:',
			'Sort code:' => 'Cod sortare:',
			'IBAN:' => 'IBAN:',
			'BIC:' => 'BIC:',
			'First name' => 'Prenume',
			'Last name' => 'Nume',
			'Company name' => 'Companie',
			'Country / Region' => 'Tara / regiune',
			'Street address' => 'Adresa',
			'Town / City' => 'Oras',
			'State / County' => 'Judet',
			'Postcode / ZIP' => 'Cod postal',
			'Phone' => 'Telefon',
			'Email address' => 'Email',
			'Order notes' => 'Note comanda',
			'Order notes (optional)' => 'Note comanda (optional)',
			'Ship to a different address?' => 'Livrezi la alta adresa?',
			'Create an account?' => 'Creeaza un cont?',
			'Returning customer?' => 'Ai deja cont?',
			'Click here to login' => 'Click aici pentru autentificare',
			'Username or email' => 'Utilizator sau email',
			'Password' => 'Parola',
			'Remember me' => 'Tine-ma minte',
			'Login' => 'Autentificare',
			'Lost your password?' => 'Ai uitat parola?',
			'Remove this item' => 'Elimina acest produs',
			'Shipping' => 'Livrare',
			'No shipping options were found.' => 'Nu au fost gasite optiuni de livrare.',
			'Invalid payment method.' => 'Metoda de plata nu este valida.',
			'%s is a required field.' => 'Campul %s este obligatoriu.',
			'Please enter a valid postcode / ZIP.' => 'Te rugam sa introduci un cod postal valid.',
			'Please enter a valid phone number.' => 'Te rugam sa introduci un numar de telefon valid.',
			'Please enter a valid email address.' => 'Te rugam sa introduci o adresa de email valida.',
			'Apartment, suite, unit, etc. (optional)' => 'Apartament, scara, etaj etc. (optional)',
			'House number and street name' => 'Strada si numar',
		);
	}

	/**
	 * Forces Romanian checkout labels and placeholders inside this widget.
	 *
	 * @param array<string,mixed> $fields Checkout fields.
	 * @return array<string,mixed>
	 */
	private function romanian_checkout_fields( array $fields ): array {
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( isset( $fields[ $group ] ) && is_array( $fields[ $group ] ) ) {
				$fields[ $group ] = $this->apply_romanian_field_texts( $fields[ $group ], $this->checkout_field_texts( $group ) );
			}
		}

		if ( isset( $fields['order']['order_comments'] ) && is_array( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['label']       = 'Note comanda';
			$fields['order']['order_comments']['placeholder'] = 'Observatii despre comanda, livrare sau facturare.';
		}

		if ( isset( $fields['account']['account_password'] ) && is_array( $fields['account']['account_password'] ) ) {
			$fields['account']['account_password']['label']       = 'Parola';
			$fields['account']['account_password']['placeholder'] = 'Alege o parola pentru cont';
		}

		return $fields;
	}

	/**
	 * Forces Romanian default address field labels before WooCommerce builds checkout fields.
	 *
	 * @param array<string,mixed> $fields Address fields.
	 * @return array<string,mixed>
	 */
	private function romanian_default_address_fields( array $fields ): array {
		return $this->apply_romanian_field_texts(
			$fields,
			array(
				'first_name' => array( 'label' => 'Prenume', 'placeholder' => 'Prenume' ),
				'last_name'  => array( 'label' => 'Nume', 'placeholder' => 'Nume' ),
				'company'    => array( 'label' => 'Companie', 'placeholder' => 'Nume companie' ),
				'country'    => array( 'label' => 'Tara / regiune', 'placeholder' => 'Tara / regiune' ),
				'address_1'  => array( 'label' => 'Adresa', 'placeholder' => 'Strada si numar' ),
				'address_2'  => array( 'label' => 'Apartament, scara, etaj', 'placeholder' => 'Apartament, scara, etaj etc. (optional)' ),
				'city'       => array( 'label' => 'Oras', 'placeholder' => 'Oras' ),
				'state'      => array( 'label' => 'Judet', 'placeholder' => 'Judet' ),
				'postcode'   => array( 'label' => 'Cod postal', 'placeholder' => 'Cod postal' ),
			)
		);
	}

	/**
	 * Returns checkout field label and placeholder text for one group.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function checkout_field_texts( string $group ): array {
		$prefix = 'shipping' === $group ? 'shipping_' : 'billing_';

		return array(
			$prefix . 'first_name' => array( 'label' => 'Prenume', 'placeholder' => 'Prenume' ),
			$prefix . 'last_name'  => array( 'label' => 'Nume', 'placeholder' => 'Nume' ),
			$prefix . 'company'    => array( 'label' => 'Companie', 'placeholder' => 'Nume companie' ),
			$prefix . 'country'    => array( 'label' => 'Tara / regiune', 'placeholder' => 'Tara / regiune' ),
			$prefix . 'address_1'  => array( 'label' => 'Adresa', 'placeholder' => 'Strada si numar' ),
			$prefix . 'address_2'  => array( 'label' => 'Apartament, scara, etaj', 'placeholder' => 'Apartament, scara, etaj etc. (optional)' ),
			$prefix . 'city'       => array( 'label' => 'Oras', 'placeholder' => 'Oras' ),
			$prefix . 'state'      => array( 'label' => 'Judet', 'placeholder' => 'Judet' ),
			$prefix . 'postcode'   => array( 'label' => 'Cod postal', 'placeholder' => 'Cod postal' ),
			$prefix . 'phone'      => array( 'label' => 'Telefon', 'placeholder' => 'Telefon' ),
			$prefix . 'email'      => array( 'label' => 'Email', 'placeholder' => 'Email' ),
		);
	}

	/**
	 * Applies label and placeholder overrides to a field group.
	 *
	 * @param array<string,mixed> $fields Field group.
	 * @param array<string,array<string,string>> $texts Romanian labels.
	 * @return array<string,mixed>
	 */
	private function apply_romanian_field_texts( array $fields, array $texts ): array {
		foreach ( $texts as $key => $text ) {
			if ( ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) ) {
				continue;
			}

			$fields[ $key ]['label']       = $text['label'];
			$fields[ $key ]['placeholder'] = $text['placeholder'];
		}

		return $fields;
	}

	/**
	 * Renders the checkout progress labels.
	 */
	private function steps( string $mode = 'cart_checkout' ): string {
		if ( 'order_pay' === $mode ) {
			$steps = array(
				__( 'Comanda', 'schrack-woocommerce-sync' ),
				__( 'Verificare', 'schrack-woocommerce-sync' ),
				__( 'Plata', 'schrack-woocommerce-sync' ),
			);
		} elseif ( 'order_received' === $mode ) {
			$steps = array(
				__( 'Cos', 'schrack-woocommerce-sync' ),
				__( 'Comanda', 'schrack-woocommerce-sync' ),
				__( 'Confirmare', 'schrack-woocommerce-sync' ),
			);
		} else {
			$steps = array(
				__( 'Cos', 'schrack-woocommerce-sync' ),
				__( 'Date comanda', 'schrack-woocommerce-sync' ),
				__( 'Plata', 'schrack-woocommerce-sync' ),
			);
		}

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
	 * Renders the WooCommerce order payment module.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function order_pay_panel( array $settings ): string {
		ob_start();
		?>
		<div class="schrack-cart-checkout__order-pay">
			<div class="schrack-cart-checkout__panel schrack-cart-checkout__panel--order-pay">
				<div class="schrack-cart-checkout__panel-head">
					<h2><?php echo esc_html( $settings['order_pay_heading'] ); ?></h2>
					<span><?php echo esc_html( $settings['order_pay_badge'] ); ?></span>
				</div>

				<div class="schrack-cart-checkout__woocommerce-order-pay">
					<?php echo $this->render_shortcode( 'woocommerce_checkout', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>

			<?php if ( 'yes' === $settings['show_continue_shopping'] ) : ?>
				<div class="schrack-cart-checkout__continue">
					<a href="<?php echo esc_url( $this->continue_shopping_url( $settings ) ); ?>"><?php echo esc_html( $settings['continue_shopping_text'] ); ?></a>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the WooCommerce order received module.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function order_received_panel( array $settings ): string {
		ob_start();
		?>
		<div class="schrack-cart-checkout__order-received">
			<div class="schrack-cart-checkout__panel schrack-cart-checkout__panel--order-received">
				<div class="schrack-cart-checkout__panel-head">
					<h2><?php echo esc_html( $settings['order_received_heading'] ); ?></h2>
					<span><?php echo esc_html( $settings['order_received_badge'] ); ?></span>
				</div>

				<div class="schrack-cart-checkout__woocommerce-order-received">
					<?php echo $this->render_shortcode( 'woocommerce_checkout', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>

			<?php if ( 'yes' === $settings['show_continue_shopping'] ) : ?>
				<div class="schrack-cart-checkout__continue">
					<a href="<?php echo esc_url( $this->continue_shopping_url( $settings ) ); ?>"><?php echo esc_html( $settings['continue_shopping_text'] ); ?></a>
				</div>
			<?php endif; ?>
		</div>
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
	 * Detects WooCommerce order payment endpoints.
	 */
	private function is_order_pay_request(): bool {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return true;
		}

		global $wp;

		if ( isset( $wp ) && is_object( $wp ) && isset( $wp->query_vars['order-pay'] ) ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return false;
		}

		return false !== strpos( '/' . trim( $path, '/' ) . '/', '/order-pay/' );
	}

	/**
	 * Detects WooCommerce order confirmation endpoints.
	 */
	private function is_order_received_request(): bool {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return true;
		}

		global $wp;

		if ( isset( $wp ) && is_object( $wp ) && isset( $wp->query_vars['order-received'] ) ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return false;
		}

		return false !== strpos( '/' . trim( $path, '/' ) . '/', '/order-received/' );
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
