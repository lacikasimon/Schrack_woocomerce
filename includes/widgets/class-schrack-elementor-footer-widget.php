<?php
/**
 * Elementor footer widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Footer_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_footer';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Footer Syshub', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-footer';
	}

	/**
	 * Elementor categories.
	 *
	 * @return array<int,string>
	 */
	public function get_categories(): array {
		return array( 'schrack', 'theme-elements' );
	}

	/**
	 * Frontend style handles.
	 *
	 * @return array<int,string>
	 */
	public function get_style_depends(): array {
		return array( 'schrack-wc-footer' );
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
			'top_message',
			array(
				'label'       => __( 'Mesaj banda sus', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Instalatii electrice · Fotovoltaice · Securitate — solutii integrate pentru proiectul tau', 'schrack-woocommerce-sync' ),
				'label_block' => true,
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
				'default' => 'SRL',
			)
		);

		$this->add_control(
			'brand_lead',
			array(
				'label'   => __( 'Descriere scurta', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Proiectare, executie si mentenanta pentru instalatii electrice, fotovoltaice si sisteme de securitate.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'brand_text',
			array(
				'label'   => __( 'Descriere lunga', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Lucram cu beneficiari privati, firme de constructii si administratori de patrimoniu — oferte clare, documentatie conforma si suport dupa receptie.', 'schrack-woocommerce-sync' ),
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
				'label'       => __( 'URL site Syshub', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url' => 'https://syshub.ro/',
				),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_contact',
			array(
				'label' => __( 'Date companie', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'phone_display',
			array(
				'label'   => __( 'Telefon afisat', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '0749 235 958',
			)
		);

		$this->add_control(
			'phone_tel',
			array(
				'label'   => __( 'Telefon link', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '+40749235958',
			)
		);

		$this->add_control(
			'address_one',
			array(
				'label'   => __( 'Adresa linia 1', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Judet Satu Mare, loc. Satu Mare', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'address_two',
			array(
				'label'   => __( 'Adresa linia 2', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Str. Gheorghe Baritiu 86, cod postal 440135', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'cui',
			array(
				'label'   => __( 'CUI', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'RO 38322763',
			)
		);

		$this->add_control(
			'cui_note',
			array(
				'label'   => __( 'Nota CUI', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Platitor de TVA (la facturare)', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'reg_com',
			array(
				'label'   => __( 'Registrul Comertului', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'J2017001105304',
			)
		);

		$this->add_control(
			'euid',
			array(
				'label'   => __( 'EUID', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'ROONRC.J2017001105304',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_visibility',
			array(
				'label' => __( 'Vizibilitate', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach (
			array(
				'show_social'       => __( 'Retele sociale', 'schrack-woocommerce-sync' ),
				'show_anpc'         => __( 'Bloc ANPC', 'schrack-woocommerce-sync' ),
				'show_payments'     => __( 'Logo NETOPIA Payments', 'schrack-woocommerce-sync' ),
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
				'default' => '#1e40af',
			)
		);

		$this->add_control(
			'deep_color',
			array(
				'label'   => __( 'Culoare banda', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#172554',
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

		$renderer = new Schrack_Footer_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
