<?php
/**
 * Elementor order received widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Order_Received_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_order_received';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Confirmare comanda Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-check-circle-o';
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
				'label'     => __( 'Eticheta', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Comanda primita', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_header' => 'yes',
				),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'     => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Multumim pentru comanda', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_header' => 'yes',
				),
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'     => __( 'Descriere', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'default'   => __( 'Am primit comanda ta. Mai jos gasesti detaliile comenzii si informatiile pentru confirmare.', 'schrack-woocommerce-sync' ),
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
			'order_received_heading',
			array(
				'label'   => __( 'Titlu zona confirmare', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Detalii comanda', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'order_received_badge',
			array(
				'label'   => __( 'Eticheta zona confirmare', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Confirmare trimisa', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_links',
			array(
				'label' => __( 'Linkuri', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

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
				'label'     => __( 'Text link', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Continua cumparaturile', 'schrack-woocommerce-sync' ),
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

		$this->add_control(
			'shop_url',
			array(
				'label'         => __( 'URL magazin fallback', 'schrack-woocommerce-sync' ),
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
					'size' => 1040,
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

		$settings['order_received_eyebrow']  = (string) ( $settings['eyebrow'] ?? '' );
		$settings['order_received_title']    = (string) ( $settings['title'] ?? '' );
		$settings['order_received_subtitle'] = (string) ( $settings['subtitle'] ?? '' );
		$settings['render_mode']             = 'order_received';

		$renderer = new Schrack_Cart_Checkout_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
