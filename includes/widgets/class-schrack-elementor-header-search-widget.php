<?php
/**
 * Elementor header product search widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Header_Search_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_header_search';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Cautare header Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-search';
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
		return array( 'schrack-wc-header-search' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_script_depends(): array {
		return array( 'schrack-wc-header-search' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_search',
			array(
				'label' => __( 'Cautare', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'   => __( 'Placeholder', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cauta produse...', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Text buton', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cauta', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'min_chars',
			array(
				'label'   => __( 'Numar minim caractere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 3,
				'min'     => 3,
				'max'     => 5,
				'step'    => 1,
			)
		);

		$this->add_control(
			'max_results',
			array(
				'label'   => __( 'Rezultate rapide', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 3,
				'max'     => 12,
				'step'    => 1,
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

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Stil', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
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
						'min'  => 240,
						'max'  => 720,
						'step' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 460,
				),
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
		$renderer = new Schrack_Header_Search_Renderer();

		echo $renderer->render( $this->get_settings_for_display(), $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Returns display switch controls.
	 *
	 * @return array<string,string>
	 */
	private function display_switches(): array {
		return array(
			'show_images' => __( 'Imagini', 'schrack-woocommerce-sync' ),
			'show_price'  => __( 'Pret', 'schrack-woocommerce-sync' ),
			'show_stock'  => __( 'Stoc', 'schrack-woocommerce-sync' ),
		);
	}
}
