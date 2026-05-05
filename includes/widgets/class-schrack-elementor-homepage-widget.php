<?php
/**
 * Elementor homepage widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Homepage_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_homepage';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Pagina principala Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-site-identity';
	}

	/**
	 * Elementor categories.
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
		return array( 'schrack-wc-homepage' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_script_depends(): array {
		return array( 'schrack-wc-homepage' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$shop_placeholder = home_url( '/shop/' );

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop_url = wc_get_page_permalink( 'shop' );

			if ( is_string( $shop_url ) && '' !== $shop_url ) {
				$shop_placeholder = $shop_url;
			}
		}

		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Continut', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'eyebrow',
			array(
				'label'   => __( 'Eticheta', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'GENE SYS SECURITY SRL', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Magazin tehnic pentru proiecte electrice, fotovoltaice si securitate', 'schrack-woocommerce-sync' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'   => __( 'Descriere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Alege produse pentru instalatii electrice, sisteme fotovoltaice, CCTV, detectie la efractie si mentenanta, cu repere clare pentru proiecte civile si industriale.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'support_text',
			array(
				'label'   => __( 'Descriere suplimentara', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Syshub aduce contextul de proiectare, executie si documentatie; magazinul te ajuta sa pornesti rapid din categoriile potrivite, de la lista de materiale pana la ofertare.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'company_meta',
			array(
				'label'   => __( 'Meta companie', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Satu Mare - CUI RO 38322763', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Text buton magazin', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Vezi catalogul de produse', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'shop_url',
			array(
				'label'         => __( 'URL magazin', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => $shop_placeholder,
				'show_external' => false,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_catalog',
			array(
				'label' => __( 'Catalog', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'category_limit',
			array(
				'label'       => __( 'Categorii in arbore', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 220,
				'min'         => 20,
				'max'         => 600,
				'step'        => 20,
				'description' => __( 'Limiteaza arborele pentru magazine mari si memorie redusa.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'featured_category_count',
			array(
				'label'   => __( 'Carduri categorii', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 0,
				'max'     => 8,
				'step'    => 1,
			)
		);

		$this->add_control(
			'show_counts',
			array(
				'label'        => __( 'Afiseaza numar produse', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_featured_categories',
			array(
				'label'        => __( 'Carduri categorii populare', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_project_paths',
			array(
				'label'        => __( 'Alegere tip proiect', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_shop_bridge',
			array(
				'label'        => __( 'Serviciu catre produse', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_solution_spotlight',
			array(
				'label'        => __( 'Bloc solutii cu imagini', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_process',
			array(
				'label'        => __( 'Flux ofertare si receptie', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_references',
			array(
				'label'        => __( 'Repere proiecte Syshub', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_final_cta',
			array(
				'label'        => __( 'CTA catalog / oferta', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_services',
			array(
				'label'        => __( 'Carduri servicii Syshub', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
			'max_width',
			array(
				'label'      => __( 'Latime maxima', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 900,
						'max'  => 1440,
						'step' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1180,
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
				'label'   => __( 'Culoare buton', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#b32d2e',
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'      => __( 'Rotunjire card', 'schrack-woocommerce-sync' ),
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
	 * Renders the widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		if ( isset( $settings['shop_url'] ) && is_array( $settings['shop_url'] ) ) {
			$settings['shop_url'] = (string) ( $settings['shop_url']['url'] ?? '' );
		}

		foreach ( array( 'max_width', 'radius' ) as $slider_key ) {
			if ( isset( $settings[ $slider_key ] ) && is_array( $settings[ $slider_key ] ) ) {
				$settings[ $slider_key ] = (string) absint( $settings[ $slider_key ]['size'] ?? 0 );
			}
		}

		$renderer = new Schrack_Homepage_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
