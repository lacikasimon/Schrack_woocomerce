<?php
/**
 * Elementor account portal widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Account_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_account_portal';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Cont client / B2B Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-lock-user';
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
		return array( 'schrack-wc-account' );
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
			'eyebrow',
			array(
				'label'   => __( 'Eticheta', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cont Syshub', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'   => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Autentificare si cont client', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'   => __( 'Descriere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Acces rapid la comenzi, facturare si cereri B2B pentru proiecte tehnice.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'login_title',
			array(
				'label'   => __( 'Titlu login', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Intra in cont', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'login_subtitle',
			array(
				'label'   => __( 'Descriere login', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Foloseste emailul contului pentru istoric comenzi si date de facturare.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'show_b2b_panel',
			array(
				'label'        => __( 'Panel B2B', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'b2b_title',
			array(
				'label'     => __( 'Titlu B2B', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Acces B2B pentru firme', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_b2b_panel' => 'yes',
				),
			)
		);

		$this->add_control(
			'b2b_text',
			array(
				'label'     => __( 'Text B2B', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'default'   => __( 'Clientii B2B pot trimite datele companiei pentru verificare, conditii comerciale si suport pe proiect.', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_b2b_panel' => 'yes',
				),
			)
		);

		$this->add_control(
			'b2b_button_text',
			array(
				'label'     => __( 'Text buton B2B', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => __( 'Solicita cont B2B', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'show_b2b_panel' => 'yes',
				),
			)
		);

		$this->add_control(
			'customer_button_text',
			array(
				'label'   => __( 'Text link cont client', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Creeaza cont client', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'show_recent_orders',
			array(
				'label'        => __( 'Comenzi recente', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
			'success_redirect',
			array(
				'label'         => __( 'Redirect dupa login', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => home_url( '/' ),
				'description'   => __( 'Lasa gol pentru a ramane pe pagina curenta.', 'schrack-woocommerce-sync' ),
				'show_external' => false,
			)
		);

		$this->add_control(
			'customer_register_url',
			array(
				'label'         => __( 'URL inregistrare client', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'show_external' => false,
			)
		);

		$this->add_control(
			'b2b_register_url',
			array(
				'label'         => __( 'URL inregistrare B2B', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'show_external' => false,
			)
		);

		$this->add_control(
			'shop_url',
			array(
				'label'         => __( 'URL catalog', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'show_external' => false,
			)
		);

		$this->add_control(
			'support_url',
			array(
				'label'         => __( 'URL suport', 'schrack-woocommerce-sync' ),
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
			'max_width',
			array(
				'label'      => __( 'Latime maxima', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 360,
						'max'  => 1320,
						'step' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1120,
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

		$this->end_controls_section();
	}

	/**
	 * Renders the Elementor widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		foreach ( array( 'success_redirect', 'customer_register_url', 'b2b_register_url', 'shop_url', 'support_url' ) as $url_key ) {
			if ( isset( $settings[ $url_key ] ) && is_array( $settings[ $url_key ] ) ) {
				$settings[ $url_key ] = (string) ( $settings[ $url_key ]['url'] ?? '' );
			}
		}

		if ( isset( $settings['max_width'] ) && is_array( $settings['max_width'] ) ) {
			$settings['max_width'] = (string) absint( $settings['max_width']['size'] ?? 1120 );
		}

		$renderer = new Schrack_Account_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
