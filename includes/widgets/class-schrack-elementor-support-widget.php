<?php
/**
 * Elementor floating support widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Support_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_support';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Chat Syshub', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-chat';
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
		return array( 'schrack-wc-support' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_script_depends(): array {
		return array( 'schrack-wc-support' );
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
			'panel_title',
			array(
				'label'   => __( 'Titlu panel', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Suport client', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'panel_text',
			array(
				'label'   => __( 'Text panel', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Raspundem la intrebari despre produse, oferte si comenzi.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'whatsapp_label',
			array(
				'label'   => __( 'Text WhatsApp', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Chat WhatsApp', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'whatsapp_message',
			array(
				'label'   => __( 'Mesaj WhatsApp precompletat', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Buna ziua, doresc informatii despre produse sau oferta.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'contact_label',
			array(
				'label'   => __( 'Text cerere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Trimite o cerere', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'contact_url',
			array(
				'label'       => __( 'URL formular contact', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url' => 'https://syshub.ro/contact#formular-contact',
				),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_phone',
			array(
				'label' => __( 'Telefon', 'schrack-woocommerce-sync' ),
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
				'default' => '#1e40af',
			)
		);

		$this->add_control(
			'deep_color',
			array(
				'label'   => __( 'Culoare antet', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#172554',
			)
		);

		$this->add_control(
			'action_color',
			array(
				'label'   => __( 'Culoare WhatsApp', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#16a34a',
			)
		);

		foreach (
			array(
				'bottom_offset' => array(
					'label' => __( 'Distanta jos', 'schrack-woocommerce-sync' ),
					'min'   => 12,
					'max'   => 220,
					'value' => 104,
				),
				'right_offset'  => array(
					'label' => __( 'Distanta dreapta', 'schrack-woocommerce-sync' ),
					'min'   => 8,
					'max'   => 80,
					'value' => 16,
				),
			) as $key => $control
		) {
			$this->add_control(
				$key,
				array(
					'label'      => $control['label'],
					'type'       => \Elementor\Controls_Manager::SLIDER,
					'size_units' => array( 'px' ),
					'range'      => array(
						'px' => array(
							'min'  => $control['min'],
							'max'  => $control['max'],
							'step' => 1,
						),
					),
					'default'    => array(
						'unit' => 'px',
						'size' => $control['value'],
					),
				)
			);
		}

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

		if ( isset( $settings['contact_url'] ) && is_array( $settings['contact_url'] ) ) {
			$settings['contact_url'] = (string) ( $settings['contact_url']['url'] ?? '' );
		}

		foreach ( array( 'bottom_offset', 'right_offset', 'radius' ) as $slider_key ) {
			if ( isset( $settings[ $slider_key ] ) && is_array( $settings[ $slider_key ] ) ) {
				$settings[ $slider_key ] = (string) absint( $settings[ $slider_key ]['size'] ?? 0 );
			}
		}

		$renderer = new Schrack_Support_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
