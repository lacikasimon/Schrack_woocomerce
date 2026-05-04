<?php
/**
 * Elementor registration widgets.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Schrack_Elementor_Registration_Widget_Base extends \Elementor\Widget_Base {
	/**
	 * Returns the registration mode rendered by this widget.
	 */
	abstract protected function registration_mode(): string;

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-user-circle-o';
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
		return array( 'schrack-wc-registration' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$mode = $this->registration_mode();

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
				'default' => 'b2b' === $mode ? __( 'Cont companie', 'schrack-woocommerce-sync' ) : __( 'Cont client', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'   => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'b2b' === $mode ? __( 'Inregistrare B2B', 'schrack-woocommerce-sync' ) : __( 'Creeaza cont', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'   => __( 'Descriere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => 'b2b' === $mode
					? __( 'Trimite datele companiei pentru acces B2B si validare comerciala.', 'schrack-woocommerce-sync' )
					: __( 'Creeaza rapid un cont pentru comenzi si istoric in magazin.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Text buton', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'b2b' === $mode ? __( 'Trimite cererea B2B', 'schrack-woocommerce-sync' ) : __( 'Creeaza contul', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'redirect_url',
			array(
				'label'       => __( 'Redirect dupa succes', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'placeholder' => home_url( '/' ),
				'description' => __( 'Lasa gol pentru a ramane pe aceeasi pagina.', 'schrack-woocommerce-sync' ),
				'show_external' => false,
			)
		);

		$this->add_control(
			'show_login_link',
			array(
				'label'        => __( 'Link autentificare', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'auto_login',
			array(
				'label'        => __( 'Autentificare automata', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'customer' === $mode ? 'yes' : 'no',
				'description'  => __( 'Pentru formularul B2B, contul ramane marcat ca cerere in asteptare chiar daca acest control este activat.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_terms',
			array(
				'label' => __( 'Termeni', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'require_terms',
			array(
				'label'        => __( 'Acceptare termeni', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'terms_text',
			array(
				'label'   => __( 'Text termeni', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Sunt de acord cu politica magazinului.', 'schrack-woocommerce-sync' ),
				'condition' => array(
					'require_terms' => 'yes',
				),
			)
		);

		$this->add_control(
			'terms_url',
			array(
				'label'         => __( 'URL termeni', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'show_external' => false,
				'condition'     => array(
					'require_terms' => 'yes',
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
			'max_width',
			array(
				'label'      => __( 'Latime maxima', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 320,
						'max'  => 1040,
						'step' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 760,
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

		foreach ( array( 'redirect_url', 'terms_url' ) as $url_key ) {
			if ( isset( $settings[ $url_key ] ) && is_array( $settings[ $url_key ] ) ) {
				$settings[ $url_key ] = (string) ( $settings[ $url_key ]['url'] ?? '' );
			}
		}

		if ( isset( $settings['max_width'] ) && is_array( $settings['max_width'] ) ) {
			$settings['max_width'] = (string) absint( $settings['max_width']['size'] ?? 760 );
		}

		$renderer = new Schrack_Registration_Renderer();

		echo $renderer->render( $settings, $this->get_id(), $this->registration_mode() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

class Schrack_Elementor_Customer_Register_Widget extends Schrack_Elementor_Registration_Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_customer_register';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Inregistrare client Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Returns registration mode.
	 */
	protected function registration_mode(): string {
		return 'customer';
	}
}

class Schrack_Elementor_B2B_Register_Widget extends Schrack_Elementor_Registration_Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_b2b_register';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Inregistrare B2B Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Returns registration mode.
	 */
	protected function registration_mode(): string {
		return 'b2b';
	}
}
