<?php
/**
 * Elementor header widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Header_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_header';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Header Syshub', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-header';
	}

	/**
	 * Elementor categories.
	 *
	 * @return array<int,string>
	 */
	public function get_categories(): array {
		return array( 'schrack', 'theme-elements', 'woocommerce-elements' );
	}

	/**
	 * Frontend style handles.
	 *
	 * @return array<int,string>
	 */
	public function get_style_depends(): array {
		return array( 'schrack-wc-header', 'schrack-wc-header-search' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_script_depends(): array {
		return array( 'schrack-wc-header', 'schrack-wc-header-search' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_brand',
			array(
				'label' => __( 'Brand', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'company_name',
			array(
				'label'   => __( 'Nume companie', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'GENE SYS SECURITY SRL',
			)
		);

		$this->add_control(
			'brand_name',
			array(
				'label'   => __( 'Nume brand', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'GENE SYS SECURITY',
			)
		);

		$this->add_control(
			'brand_suffix',
			array(
				'label'   => __( 'Sufix brand', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'SHOP',
			)
		);

		$this->add_control(
			'logo_url',
			array(
				'label'       => __( 'URL logo', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url' => 'https://syshub.ro/assets/genesys-logo-D16z0xlU.svg',
				),
				'label_block' => true,
			)
		);

		$this->add_control(
			'site_url',
			array(
				'label'       => __( 'URL brand', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url' => home_url( '/' ),
				),
				'label_block' => true,
				'show_external' => false,
			)
		);

		$this->add_control(
			'show_brand_text',
			array(
				'label'        => __( 'Afiseaza nume brand', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_eu_logos',
			array(
				'label' => __( 'Finantare UE', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_eu_logos',
			array(
				'label'        => __( 'Afiseaza logo-uri UE sub meniu', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_search',
			array(
				'label' => __( 'Cautare produse', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_search',
			array(
				'label'        => __( 'Afiseaza cautarea', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'search_placeholder',
			array(
				'label'   => __( 'Placeholder', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cauta produse, coduri, SKU...', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'search_button_text',
			array(
				'label'   => __( 'Text buton', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cauta', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'search_min_chars',
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
			'search_max_results',
			array(
				'label'   => __( 'Sugestii afisate', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 8,
				'min'     => 3,
				'max'     => 12,
				'step'    => 1,
			)
		);

		$this->add_control(
			'search_enable_fuzzy',
			array(
				'label'        => __( 'Fuzzy match', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'search_fuzzy_pool',
			array(
				'label'   => __( 'Produse analizate fuzzy', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 120,
				'min'     => 40,
				'max'     => 240,
				'step'    => 20,
			)
		);

		foreach (
			array(
				'show_search_images' => __( 'Imagini in sugestii', 'schrack-woocommerce-sync' ),
				'show_search_price'  => __( 'Pret in sugestii', 'schrack-woocommerce-sync' ),
				'show_search_sku'    => __( 'SKU / cod Schrack', 'schrack-woocommerce-sync' ),
				'show_search_stock'  => __( 'Stoc in sugestii', 'schrack-woocommerce-sync' ),
			) as $key => $label
		) {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
					'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'section_menu',
			array(
				'label' => __( 'Meniu', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'menu_id',
			array(
				'label'   => __( 'Meniu WordPress', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '0',
				'options' => $this->menu_options(),
			)
		);

		$this->add_control(
			'menu_label',
			array(
				'label'   => __( 'Eticheta meniu', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Meniu', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_actions',
			array(
				'label' => __( 'Actiuni WooCommerce', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach (
			array(
				'show_account'    => __( 'Cont utilizator', 'schrack-woocommerce-sync' ),
				'show_cart'       => __( 'Cos cumparaturi', 'schrack-woocommerce-sync' ),
				'show_cart_total' => __( 'Total cos', 'schrack-woocommerce-sync' ),
			) as $key => $label
		) {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
					'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);
		}

		$this->add_control(
			'cart_label',
			array(
				'label'   => __( 'Eticheta cos', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cos', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'account_label',
			array(
				'label'   => __( 'Eticheta cont', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Contul meu', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'login_label',
			array(
				'label'   => __( 'Eticheta autentificare', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Autentificare', 'schrack-woocommerce-sync' ),
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
						'min'  => 960,
						'max'  => 1440,
						'step' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1280,
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

		$this->add_control(
			'is_sticky',
			array(
				'label'        => __( 'Header lipit sus', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		foreach ( array( 'logo_url', 'site_url' ) as $url_key ) {
			if ( isset( $settings[ $url_key ] ) && is_array( $settings[ $url_key ] ) ) {
				$settings[ $url_key ] = (string) ( $settings[ $url_key ]['url'] ?? '' );
			}
		}

		foreach ( array( 'max_width', 'radius' ) as $slider_key ) {
			if ( isset( $settings[ $slider_key ] ) && is_array( $settings[ $slider_key ] ) ) {
				$settings[ $slider_key ] = (string) absint( $settings[ $slider_key ]['size'] ?? 0 );
			}
		}

		$renderer = new Schrack_Header_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Returns available WordPress menus for the Elementor select control.
	 *
	 * @return array<string,string>
	 */
	private function menu_options(): array {
		$options = array(
			'0' => __( 'Linkuri implicite', 'schrack-woocommerce-sync' ),
		);

		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) ) {
			return $options;
		}

		foreach ( $menus as $menu ) {
			if ( ! $menu instanceof WP_Term ) {
				continue;
			}

			$options[ (string) $menu->term_id ] = $menu->name;
		}

		return $options;
	}
}
