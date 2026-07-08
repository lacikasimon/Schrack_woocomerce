<?php
/**
 * Elementor featured categories widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Featured_Categories_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_featured_categories';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Categorii principale Schrack', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-posts-grid';
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
		return array( 'schrack-wc-featured-categories' );
	}

	/**
	 * Frontend script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_script_depends(): array {
		return array( 'schrack-wc-featured-categories' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$category_options        = $this->category_options();
		$single_category_options = array( '' => __( 'Alege o categorie', 'schrack-woocommerce-sync' ) ) + $category_options;

		$this->start_controls_section(
			'section_hero',
			array(
				'label' => __( 'Navigare categorii (fundal transparent)', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'category_limit',
			array(
				'label'       => __( 'Numar categorii principale', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 10,
				'min'         => 4,
				'max'         => 12,
				'step'        => 1,
				'description' => __( 'Cele mai populate categorii de nivel principal sunt alese automat, dupa numarul de produse.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'hero_background_image',
			array(
				'label' => __( 'Imagine fundal navigare', 'schrack-woocommerce-sync' ),
				'type'  => \Elementor\Controls_Manager::MEDIA,
			)
		);

		$this->add_control(
			'hero_height',
			array(
				'label'      => __( 'Inaltime zona fundal', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 220,
						'max'  => 720,
						'step' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 420,
				),
			)
		);

		$this->add_control(
			'hero_overlay_opacity',
			array(
				'label'      => __( 'Opacitate strat intunecat', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( '%' ),
				'range'      => array(
					'%' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 5,
					),
				),
				'default'    => array(
					'unit' => '%',
					'size' => 45,
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_promo',
			array(
				'label' => __( 'Sectiune promotionala', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'promo_category_id',
			array(
				'label'       => __( 'Categorie promovata', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'options'     => $single_category_options,
				'multiple'    => false,
				'label_block' => true,
				'default'     => '',
			)
		);

		$this->add_control(
			'promo_eyebrow',
			array(
				'label'   => __( 'Text scurt deasupra titlului', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);

		$this->add_control(
			'promo_title',
			array(
				'label'       => __( 'Titlu (optional, implicit numele categoriei)', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'promo_subtitle',
			array(
				'label'   => __( 'Descriere (optional, implicit descrierea categoriei)', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => '',
			)
		);

		$this->add_control(
			'promo_button_text',
			array(
				'label'   => __( 'Text buton', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Vezi categoria', 'schrack-woocommerce-sync' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_grids',
			array(
				'label' => __( 'Produse pe categorie', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'products_per_category',
			array(
				'label'   => __( 'Produse afisate per categorie', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 5,
				'min'     => 0,
				'max'     => 10,
				'step'    => 1,
			)
		);

		$this->add_control(
			'grid_columns',
			array(
				'label'   => __( 'Coloane grila', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 5,
				'min'     => 2,
				'max'     => 6,
				'step'    => 1,
			)
		);

		$this->add_control(
			'products_orderby',
			array(
				'label'   => __( 'Ordine produse', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'       => __( 'Cele mai noi', 'schrack-woocommerce-sync' ),
					'popularity' => __( 'Popularitate', 'schrack-woocommerce-sync' ),
					'price'      => __( 'Pret crescator', 'schrack-woocommerce-sync' ),
					'title'      => __( 'Nume', 'schrack-woocommerce-sync' ),
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

		if ( isset( $settings['hero_background_image'] ) && is_array( $settings['hero_background_image'] ) ) {
			$settings['hero_background_image'] = (string) ( $settings['hero_background_image']['url'] ?? '' );
		}

		foreach ( array( 'hero_height', 'hero_overlay_opacity', 'max_width', 'radius' ) as $slider_key ) {
			if ( isset( $settings[ $slider_key ] ) && is_array( $settings[ $slider_key ] ) ) {
				$settings[ $slider_key ] = (string) absint( $settings[ $slider_key ]['size'] ?? 0 );
			}
		}

		$renderer = new Schrack_Featured_Categories_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
