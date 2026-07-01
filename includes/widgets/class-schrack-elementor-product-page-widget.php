<?php
/**
 * Elementor product page widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Product_Page_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_product_page';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Pagina produs Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-product-info';
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
		return array( 'schrack-wc-product-page' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_product',
			array(
				'label' => __( 'Produs', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'product_source',
			array(
				'label'   => __( 'Sursa produs', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current' => __( 'Produs curent', 'schrack-woocommerce-sync' ),
					'custom'  => __( 'ID sau SKU manual', 'schrack-woocommerce-sync' ),
				),
			)
		);

		$this->add_control(
			'product_lookup',
			array(
				'label'       => __( 'ID produs sau SKU', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'label_block' => true,
				'condition'   => array(
					'product_source' => 'custom',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Afisare', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( $this->display_switches() as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'label_on'     => __( 'Afiseaza', 'schrack-woocommerce-sync' ),
					'label_off'    => __( 'Ascunde', 'schrack-woocommerce-sync' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);
		}

		$this->add_control(
			'cart_button_text',
			array(
				'label'   => __( 'Text buton cos', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Adauga in cos', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'technical_limit',
			array(
				'label'       => __( 'Limita atribute tehnice', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'max'         => 250,
				'step'        => 1,
				'description' => __( '0 afiseaza toate atributele disponibile.', 'schrack-woocommerce-sync' ),
				'condition'   => array(
					'show_technical_attributes' => 'yes',
				),
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
			'radius',
			array(
				'label'      => __( 'Rotunjire', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 12,
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
		$renderer = new Schrack_Product_Page_Renderer();

		echo $renderer->render( $this->get_settings_for_display(), $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Returns display switch controls.
	 *
	 * @return array<string,string>
	 */
	private function display_switches(): array {
		return array(
			'show_gallery'              => __( 'Galerie imagini', 'schrack-woocommerce-sync' ),
			'show_categories'           => __( 'Categorii', 'schrack-woocommerce-sync' ),
			'show_short_description'    => __( 'Descriere scurta', 'schrack-woocommerce-sync' ),
			'show_stock'                => __( 'Stoc', 'schrack-woocommerce-sync' ),
			'show_cart'                 => __( 'Cos cumparaturi', 'schrack-woocommerce-sync' ),
			'show_specs'                => __( 'Detalii produs', 'schrack-woocommerce-sync' ),
			'show_technical_attributes' => __( 'Atribute tehnice Schrack', 'schrack-woocommerce-sync' ),
		);
	}
}
