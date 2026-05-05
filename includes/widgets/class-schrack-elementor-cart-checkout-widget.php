<?php
/**
 * Elementor cart and checkout widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Cart_Checkout_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_cart_checkout';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Cos si checkout Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-cart';
	}

	/**
	 * Elementor category.
	 *
	 * @return array<int,string>
	 */
	public function get_categories(): array {
		return array( 'schrack', 'woocommerce-elements' );
	}

	/**
	 * Frontend style handles.
	 *
	 * @return array<int,string>
	 */
	public function get_style_depends(): array {
		return array( 'schrack-wc-cart-checkout' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Continut', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_header',
			array(
				'label'        => __( 'Afiseaza antetul', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'eyebrow',
			array(
				'label'   => __( 'Eticheta', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Comanda online', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_header' => 'yes',
				),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'   => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cos si finalizare comanda', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_header' => 'yes',
				),
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'   => __( 'Descriere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Verifica produsele, completeaza datele de facturare si trimite comanda in siguranta.', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_header' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_steps',
			array(
				'label'        => __( 'Afiseaza pasii comenzii', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array(
					'show_header' => 'yes',
				),
			)
		);

		$this->add_control(
			'cart_heading',
			array(
				'label'   => __( 'Titlu zona cos', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Produsele din cos', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'checkout_heading',
			array(
				'label'   => __( 'Titlu zona checkout', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Date facturare si livrare', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'order_button_text',
			array(
				'label'   => __( 'Text buton comanda', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Trimite comanda', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_behavior',
			array(
				'label' => __( 'Optiuni WooCommerce', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach (
			array(
				'show_coupon'      => __( 'Cupoane', 'schrack-woocommerce-sync' ),
				'show_cross_sells' => __( 'Produse recomandate', 'schrack-woocommerce-sync' ),
				'show_cart_totals' => __( 'Totaluri in zona cosului', 'schrack-woocommerce-sync' ),
			) as $key => $label
		) {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'label_on'     => __( 'Afiseaza', 'schrack-woocommerce-sync' ),
					'label_off'    => __( 'Ascunde', 'schrack-woocommerce-sync' ),
					'return_value' => 'yes',
					'default'      => 'show_coupon' === $key ? 'yes' : 'no',
				)
			);
		}

		$this->add_control(
			'show_continue_shopping',
			array(
				'label'        => __( 'Link continua cumparaturile', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'continue_shopping_text',
			array(
				'label'   => __( 'Text link', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Continua cumparaturile', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_continue_shopping' => 'yes',
				),
			)
		);

		$this->add_control(
			'continue_shopping_url',
			array(
				'label'         => __( 'URL continua cumparaturile', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'show_external' => false,
				'condition'     => array(
					'show_continue_shopping' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_empty',
			array(
				'label' => __( 'Cos gol', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'empty_title',
			array(
				'label'   => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cosul tau este gol', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'   => __( 'Descriere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Adauga produse in cos pentru a putea trimite comanda.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'shop_button_text',
			array(
				'label'   => __( 'Text buton magazin', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Mergi la magazin', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'shop_url',
			array(
				'label'         => __( 'URL magazin', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'show_external' => false,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Stil', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'   => __( 'Culoare accent', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#135e96',
			)
		);

		$this->add_control(
			'action_color',
			array(
				'label'   => __( 'Culoare actiune', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#b32d2e',
			)
		);

		$this->add_control(
			'max_width',
			array(
				'label'      => __( 'Latime maxima', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 720,
						'max'  => 1440,
						'step' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1240,
				),
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'      => __( 'Rotunjire', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 8,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the Elementor widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		foreach ( array( 'continue_shopping_url', 'shop_url' ) as $url_key ) {
			if ( isset( $settings[ $url_key ] ) && is_array( $settings[ $url_key ] ) ) {
				$settings[ $url_key ] = (string) ( $settings[ $url_key ]['url'] ?? '' );
			}
		}

		foreach ( array( 'max_width', 'radius' ) as $size_key ) {
			if ( isset( $settings[ $size_key ] ) && is_array( $settings[ $size_key ] ) ) {
				$settings[ $size_key ] = (string) absint( $settings[ $size_key ]['size'] ?? 0 );
			}
		}

		$renderer = new Schrack_Cart_Checkout_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
