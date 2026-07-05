<?php
/**
 * Elementor product filter widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Product_Filter_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_product_filter';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Filtru produse Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-products';
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
		return array( 'schrack-wc-product-filter' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_script_depends(): array {
		return array( 'schrack-wc-product-filter' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'section_products',
			array(
				'label' => __( 'Produse', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'products_per_page',
			array(
				'label'   => __( 'Produse pe pagina', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 12,
				'min'     => 1,
				'max'     => 60,
				'step'    => 1,
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => __( 'Coloane', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '5',
				'options' => array(
					'5' => __( '5 coloane', 'schrack-woocommerce-sync' ),
					'6' => __( '6 coloane', 'schrack-woocommerce-sync' ),
				),
			)
		);

		$this->add_control(
			'default_category',
			array(
				'label'       => __( 'Categorie implicita', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'default'     => '',
				'label_block' => true,
				'options'     => $this->category_options(),
			)
		);

		$this->add_control(
			'inherit_current_category',
			array(
				'label'        => __( 'Preia categoria din URL', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Pe arhivele WooCommerce de categorie, filtrul porneste automat cu categoria curenta.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'default_orderby',
			array(
				'label'   => __( 'Sortare implicita', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'menu_order',
				'options' => array(
					'menu_order' => __( 'Implicit', 'schrack-woocommerce-sync' ),
					'title'      => __( 'Nume A-Z', 'schrack-woocommerce-sync' ),
					'price'      => __( 'Pret crescator', 'schrack-woocommerce-sync' ),
					'price-desc' => __( 'Pret descrescator', 'schrack-woocommerce-sync' ),
					'date'       => __( 'Cele mai noi', 'schrack-woocommerce-sync' ),
					'popularity' => __( 'Popularitate', 'schrack-woocommerce-sync' ),
				),
			)
		);

		$this->add_control(
			'pagination_mode',
			array(
				'label'       => __( 'Mod paginare', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'numbered',
				'description' => __( 'Paginile numerotate pastreaza navigarea clasica si afiseaza totalul rezultatelor.', 'schrack-woocommerce-sync' ),
				'options'     => array(
					'numbered'  => __( 'Pagini numerotate', 'schrack-woocommerce-sync' ),
					'load_more' => __( 'Incarca mai multe', 'schrack-woocommerce-sync' ),
				),
			)
		);

		$this->add_control(
			'pagination_granularity',
			array(
				'label'       => __( 'Granulatie paginare', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 7,
				'min'         => 3,
				'max'         => 11,
				'step'        => 2,
				'description' => __( 'Controleaza cate numere de pagina sunt vizibile in jurul paginii curente.', 'schrack-woocommerce-sync' ),
				'condition'   => array(
					'pagination_mode' => 'numbered',
				),
			)
		);

		$this->add_control(
			'exact_totals',
			array(
				'label'        => __( 'Total exact produse', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'no',
				'description'  => __( 'Lasa dezactivat pentru cataloage cu 40000 de produse, daca nu ai nevoie de totaluri exacte.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_filters',
			array(
				'label' => __( 'Filtre', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( $this->filter_switches() as $key => $label ) {
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
			'button_text',
			array(
				'label'   => __( 'Text buton filtrare', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Aplica filtrele', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'reset_text',
			array(
				'label'   => __( 'Text buton resetare', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Reseteaza', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'load_more_text',
			array(
				'label'   => __( 'Text buton incarcare', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Incarca mai multe', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'min_search_chars',
			array(
				'label'       => __( 'Numar minim caractere cautare', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 2,
				'min'         => 1,
				'max'         => 5,
				'step'        => 1,
				'description' => __( 'Previne cautarile dintr-un singur caracter in intreg catalogul mare.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'category_results_limit',
			array(
				'label'       => __( 'Rezultate cautare categorii', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 30,
				'min'         => 10,
				'max'         => 80,
				'step'        => 5,
				'description' => __( 'Limiteaza fiecare raspuns async pentru arbori de categorii foarte mari.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_cards',
			array(
				'label' => __( 'Carduri produse', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( $this->card_switches() as $key => $label ) {
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
			'details_button_text',
			array(
				'label'   => __( 'Text buton detalii', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Detalii', 'schrack-woocommerce-sync' ),
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
			'card_radius',
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

		$this->add_control(
			'sidebar_width',
			array(
				'label'      => __( 'Latime sidebar desktop', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 260,
						'max'  => 420,
						'step' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 300,
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the Elementor widget.
	 */
	protected function render(): void {
		$renderer = new Schrack_Product_Filter_Renderer();

		echo $renderer->render( $this->get_settings_for_display(), $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Returns category select options.
	 *
	 * @return array<string,string>
	 */
	private function category_options(): array {
		$options = array(
			'' => __( 'Toate categoriile', 'schrack-woocommerce-sync' ),
		);

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return $options;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $options;
		}

		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$options[ (string) $term->term_id ] = $term->name;
			}
		}

		return $options;
	}

	/**
	 * Returns filter switch controls.
	 *
	 * @return array<string,string>
	 */
	private function filter_switches(): array {
		return array(
			'show_search'              => __( 'Cautare produse', 'schrack-woocommerce-sync' ),
			'show_category_filter'     => __( 'Selector categorie', 'schrack-woocommerce-sync' ),
			'show_category_search'     => __( 'Cautare categorie', 'schrack-woocommerce-sync' ),
			'show_price_filter'        => __( 'Interval pret', 'schrack-woocommerce-sync' ),
			'show_stock_filter'        => __( 'Comutator produse fara stoc', 'schrack-woocommerce-sync' ),
			'show_manufacturer_filter' => __( 'Filtru producator', 'schrack-woocommerce-sync' ),
			'show_product_line_filter' => __( 'Filtru serie/gama produs', 'schrack-woocommerce-sync' ),
			'show_special_offer_filter' => __( 'Filtru oferte speciale Telesystem', 'schrack-woocommerce-sync' ),
			'show_attribute_filters'   => __( 'Filtre tehnice (IP, tensiune, putere...)', 'schrack-woocommerce-sync' ),
			'show_sort'                => __( 'Sortare', 'schrack-woocommerce-sync' ),
		);
	}

	/**
	 * Returns card display switch controls.
	 *
	 * @return array<string,string>
	 */
	private function card_switches(): array {
		return array(
			'show_images'      => __( 'Imagini', 'schrack-woocommerce-sync' ),
			'show_categories'  => __( 'Categorii', 'schrack-woocommerce-sync' ),
			'show_excerpt'     => __( 'Descriere scurta', 'schrack-woocommerce-sync' ),
			'show_stock'       => __( 'Eticheta stoc', 'schrack-woocommerce-sync' ),
			'show_add_to_cart' => __( 'Adauga in cos', 'schrack-woocommerce-sync' ),
		);
	}
}
