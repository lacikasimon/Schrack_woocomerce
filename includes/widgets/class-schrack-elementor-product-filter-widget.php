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
		return __( 'Schrack Product Filter', 'schrack-woocommerce-sync' );
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
				'label' => __( 'Products', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'products_per_page',
			array(
				'label'   => __( 'Products per page', 'schrack-woocommerce-sync' ),
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
				'label'   => __( 'Columns', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'1' => __( '1 column', 'schrack-woocommerce-sync' ),
					'2' => __( '2 columns', 'schrack-woocommerce-sync' ),
					'3' => __( '3 columns', 'schrack-woocommerce-sync' ),
					'4' => __( '4 columns', 'schrack-woocommerce-sync' ),
					'5' => __( '5 columns', 'schrack-woocommerce-sync' ),
				),
			)
		);

		$this->add_control(
			'default_category',
			array(
				'label'       => __( 'Default category', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'default'     => '',
				'label_block' => true,
				'options'     => $this->category_options(),
			)
		);

		$this->add_control(
			'default_orderby',
			array(
				'label'   => __( 'Default sorting', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'menu_order',
				'options' => array(
					'menu_order' => __( 'Default', 'schrack-woocommerce-sync' ),
					'title'      => __( 'Name A-Z', 'schrack-woocommerce-sync' ),
					'price'      => __( 'Price low to high', 'schrack-woocommerce-sync' ),
					'price-desc' => __( 'Price high to low', 'schrack-woocommerce-sync' ),
					'date'       => __( 'Newest', 'schrack-woocommerce-sync' ),
					'popularity' => __( 'Popularity', 'schrack-woocommerce-sync' ),
				),
			)
		);

		$this->add_control(
			'hide_out_of_stock',
			array(
				'label'        => __( 'Hide out-of-stock products', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'No', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_filters',
			array(
				'label' => __( 'Filters', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( $this->filter_switches() as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'label_on'     => __( 'Show', 'schrack-woocommerce-sync' ),
					'label_off'    => __( 'Hide', 'schrack-woocommerce-sync' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);
		}

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Filter button text', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Apply filters', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'reset_text',
			array(
				'label'   => __( 'Reset button text', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Reset', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_cards',
			array(
				'label' => __( 'Product Cards', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		foreach ( $this->card_switches() as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'label_on'     => __( 'Show', 'schrack-woocommerce-sync' ),
					'label_off'    => __( 'Hide', 'schrack-woocommerce-sync' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);
		}

		$this->add_control(
			'details_button_text',
			array(
				'label'   => __( 'Details button text', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Details', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Style', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'   => __( 'Accent color', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#135e96',
			)
		);

		$this->add_control(
			'action_color',
			array(
				'label'   => __( 'Action color', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#b32d2e',
			)
		);

		$this->add_control(
			'card_radius',
			array(
				'label'      => __( 'Card radius', 'schrack-woocommerce-sync' ),
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
			'' => __( 'All categories', 'schrack-woocommerce-sync' ),
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
			'show_search'          => __( 'Product search', 'schrack-woocommerce-sync' ),
			'show_category_filter' => __( 'Category dropdown', 'schrack-woocommerce-sync' ),
			'show_category_search' => __( 'Category search', 'schrack-woocommerce-sync' ),
			'show_price_filter'    => __( 'Price range', 'schrack-woocommerce-sync' ),
			'show_sort'            => __( 'Sorting', 'schrack-woocommerce-sync' ),
		);
	}

	/**
	 * Returns card display switch controls.
	 *
	 * @return array<string,string>
	 */
	private function card_switches(): array {
		return array(
			'show_images'      => __( 'Images', 'schrack-woocommerce-sync' ),
			'show_categories'  => __( 'Categories', 'schrack-woocommerce-sync' ),
			'show_excerpt'     => __( 'Excerpt', 'schrack-woocommerce-sync' ),
			'show_stock'       => __( 'Stock label', 'schrack-woocommerce-sync' ),
			'show_add_to_cart' => __( 'Add to cart', 'schrack-woocommerce-sync' ),
		);
	}
}
