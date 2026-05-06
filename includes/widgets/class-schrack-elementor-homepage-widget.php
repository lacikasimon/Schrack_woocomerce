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
		$category_options = $this->category_options();
		$single_category_options = array( '' => __( 'Automat / magazin', 'schrack-woocommerce-sync' ) ) + $category_options;

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
				'label'   => __( 'Mesaj pozitionare', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Produse tehnice pentru proiecte electrice, securitate și fotovoltaice — cu suport tehnic Syshub.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Tot ce ai nevoie pentru proiecte electrice, securitate și energie solară', 'schrack-woocommerce-sync' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'subtitle',
			array(
				'label'   => __( 'Descriere', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => __( 'Alege produse tehnice potrivite pentru locuințe, spații comerciale și proiecte industriale — online, rapid și cu suport de specialitate.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'support_text',
			array(
				'label'   => __( 'Descriere suplimentara optionala', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => '',
			)
		);

		$this->add_control(
			'company_meta',
			array(
				'label'   => __( 'Nota hero optionala', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Text buton produse', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Vezi produsele', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'secondary_button_text',
			array(
				'label'   => __( 'Text buton consultanta', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Cere consultanță', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'shop_url',
			array(
				'label'         => __( 'URL produse', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => $shop_placeholder,
				'show_external' => false,
			)
		);

		$this->add_control(
			'consultation_url',
			array(
				'label'         => __( 'URL consultanta', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => 'https://syshub.ro/contact',
				'show_external' => false,
			)
		);

		$this->add_control(
			'material_list_url',
			array(
				'label'         => __( 'URL lista materiale', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => 'https://syshub.ro/contact',
				'show_external' => false,
			)
		);

		$this->add_control(
			'offer_url',
			array(
				'label'         => __( 'URL oferta', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => 'https://syshub.ro/contact',
				'show_external' => false,
			)
		);

		$this->add_control(
			'contact_url',
			array(
				'label'         => __( 'URL contact final', 'schrack-woocommerce-sync' ),
				'type'          => \Elementor\Controls_Manager::URL,
				'placeholder'   => 'https://syshub.ro/contact',
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
				'label'       => __( 'Categorii incarcate pentru mapare', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 220,
				'min'         => 20,
				'max'         => 600,
				'step'        => 20,
				'description' => __( 'Limiteaza termenii folositi intern pentru legarea cardurilor curate la categorii reale.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'featured_category_count',
			array(
				'label'   => __( 'Compatibilitate veche: carduri categorii', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 0,
				'max'     => 8,
				'step'    => 1,
			)
		);

		$this->add_control(
			'block_category_heading',
			array(
				'label'     => __( 'Mapare categorii reale', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'hero_category_ids',
			array(
				'label'       => __( 'Compatibilitate veche: categorii hero', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $category_options,
				'multiple'    => true,
				'label_block' => true,
				'description' => __( 'Hero-ul nou foloseste domenii curate si nu afiseaza nume brute de categorii.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'solution_category_ids',
			array(
				'label'       => __( 'Categorii pentru produse recomandate', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $category_options,
				'multiple'    => true,
				'label_block' => true,
				'description' => __( 'Limiteaza produsele recomandate la aceste categorii. Gol = mapare automata pe categoriile curate.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'featured_category_ids',
			array(
				'label'       => __( 'Categorii reale pentru maparea categoriilor curate', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $category_options,
				'multiple'    => true,
				'label_block' => true,
				'description' => __( 'Cardurile publice raman curate; selectia schimba doar linkurile catre categoriile WooCommerce.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'tree_category_ids',
			array(
				'label'       => __( 'Compatibilitate veche: arbore categorii', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $category_options,
				'multiple'    => true,
				'label_block' => true,
				'description' => __( 'Pagina principală nu mai afișează arbore brut de categorii importate.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'project_category_ids',
			array(
				'label'       => __( 'Categorii reale pentru proiecte', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $category_options,
				'multiple'    => true,
				'label_block' => true,
				'description' => __( 'Limiteaza linkurile din cardurile pe tip de proiect. Titlurile publice raman curate.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_homepage_card_category_controls( $single_category_options );

		$this->add_control(
			'bridge_category_ids',
			array(
				'label'       => __( 'Compatibilitate veche: categorie serviciu-produse', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $category_options,
				'multiple'    => true,
				'label_block' => true,
				'description' => __( 'Blocul vechi a fost inlocuit cu cerere B2B si oferta personalizata.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'recommended_product_limit',
			array(
				'label'   => __( 'Produse recomandate afisate', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 8,
				'min'     => 0,
				'max'     => 12,
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
				'default'      => 'no',
			)
		);

		$this->add_control(
			'show_featured_categories',
			array(
				'label'        => __( 'Categorii principale curate', 'schrack-woocommerce-sync' ),
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
				'label'        => __( 'Navigare dupa tipul proiectului', 'schrack-woocommerce-sync' ),
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
				'label'        => __( 'Bloc B2B / oferta', 'schrack-woocommerce-sync' ),
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
				'label'        => __( 'Produse recomandate', 'schrack-woocommerce-sync' ),
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
				'label'        => __( 'Compatibilitate veche: flux ofertare', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'show_references',
			array(
				'label'        => __( 'Compatibilitate veche: repere proiecte', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'show_final_cta',
			array(
				'label'        => __( 'CTA final contact', 'schrack-woocommerce-sync' ),
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
				'label'        => __( 'De ce Syshub', 'schrack-woocommerce-sync' ),
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

		foreach ( array( 'shop_url', 'consultation_url', 'material_list_url', 'offer_url', 'contact_url' ) as $url_key ) {
			if ( isset( $settings[ $url_key ] ) && is_array( $settings[ $url_key ] ) ) {
				$settings[ $url_key ] = (string) ( $settings[ $url_key ]['url'] ?? '' );
			}
		}

		foreach ( array( 'max_width', 'radius' ) as $slider_key ) {
			if ( isset( $settings[ $slider_key ] ) && is_array( $settings[ $slider_key ] ) ) {
				$settings[ $slider_key ] = (string) absint( $settings[ $slider_key ]['size'] ?? 0 );
			}
		}

		$renderer = new Schrack_Homepage_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Adds one category select for each curated homepage card.
	 *
	 * @param array<int|string,string> $options Category select options.
	 */
	private function add_homepage_card_category_controls( array $options ): void {
		$this->add_control(
			'hero_card_category_heading',
			array(
				'label'     => __( 'Linkuri categorii - carduri hero', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_single_category_control( 'hero_electric_category_id', __( 'Hero: Electric', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'hero_security_category_id', __( 'Hero: Securitate', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'hero_solar_category_id', __( 'Hero: Fotovoltaice', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'hero_automation_category_id', __( 'Hero: Automatizări', 'schrack-woocommerce-sync' ), $options );

		$this->add_control(
			'project_card_category_heading',
			array(
				'label'     => __( 'Linkuri categorii - carduri proiecte', 'schrack-woocommerce-sync' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_single_category_control( 'project_residential_category_id', __( 'Proiect: Instalații electrice rezidențiale', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'project_commercial_category_id', __( 'Proiect: Clădiri comerciale și birouri', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'project_video_category_id', __( 'Proiect: Sisteme de supraveghere video', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'project_alarm_category_id', __( 'Proiect: Sisteme de alarmare și control acces', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'project_solar_category_id', __( 'Proiect: Proiecte fotovoltaice', 'schrack-woocommerce-sync' ), $options );
		$this->add_single_category_control( 'project_automation_category_id', __( 'Proiect: Tablouri electrice și automatizări', 'schrack-woocommerce-sync' ), $options );
	}

	/**
	 * Adds a single category select control.
	 *
	 * @param array<int|string,string> $options Category select options.
	 */
	private function add_single_category_control( string $id, string $label, array $options ): void {
		$this->add_control(
			$id,
			array(
				'label'       => $label,
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $options,
				'multiple'    => false,
				'label_block' => true,
				'default'     => '',
			)
		);
	}

	/**
	 * Returns product category options for Elementor select controls.
	 *
	 * @return array<int,string>
	 */
	private function category_options(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$options = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$options[ (int) $term->term_id ] = sprintf(
				'%s (%d)',
				$term->name,
				(int) $term->count
			);
		}

		return $options;
	}
}
