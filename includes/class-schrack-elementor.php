<?php
/**
 * Elementor integration for Schrack product filters.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor {
	/**
	 * Product filter renderer.
	 *
	 * @var Schrack_Product_Filter_Renderer
	 */
	private Schrack_Product_Filter_Renderer $renderer;

	/**
	 * Whether the widget was registered during this request.
	 *
	 * @var bool
	 */
	private bool $widgets_registered = false;

	/**
	 * Constructor.
	 */
	public function __construct( ?Schrack_Product_Filter_Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Schrack_Product_Filter_Renderer();
	}

	/**
	 * Registers Elementor and AJAX hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_legacy_widgets' ) );
		add_action( 'wp_ajax_' . Schrack_Product_Filter_Renderer::AJAX_ACTION, array( $this, 'ajax_filter_products' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Product_Filter_Renderer::AJAX_ACTION, array( $this, 'ajax_filter_products' ) );
		add_action( 'wp_ajax_' . Schrack_Product_Filter_Renderer::CATEGORY_AJAX_ACTION, array( $this, 'ajax_filter_categories' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Product_Filter_Renderer::CATEGORY_AJAX_ACTION, array( $this, 'ajax_filter_categories' ) );
	}

	/**
	 * Registers frontend assets used by the Elementor widget.
	 */
	public function register_assets(): void {
		wp_register_style(
			'schrack-wc-product-filter',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-products.css',
			array(),
			SCHRACK_WC_SYNC_VERSION
		);

		wp_register_script(
			'schrack-wc-product-filter',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-products.js',
			array(),
			SCHRACK_WC_SYNC_VERSION,
			true
		);
	}

	/**
	 * Adds a Schrack widget category inside Elementor.
	 *
	 * @param mixed $elements_manager Elementor elements manager.
	 */
	public function register_elementor_category( mixed $elements_manager = null ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			'schrack',
			array(
				'title' => __( 'Schrack', 'schrack-woocommerce-sync' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Registers widgets for current Elementor versions.
	 *
	 * @param mixed $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( mixed $widgets_manager = null ): void {
		if ( $this->widgets_registered || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-filter-widget.php';

		$widget = new Schrack_Elementor_Product_Filter_Widget();

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( $widget );
			$this->widgets_registered = true;
		}
	}

	/**
	 * Registers widgets for older Elementor versions.
	 *
	 * @param mixed $widgets_manager Elementor widgets manager.
	 */
	public function register_legacy_widgets( mixed $widgets_manager = null ): void {
		if ( $this->widgets_registered || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-filter-widget.php';

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( new Schrack_Elementor_Product_Filter_Widget() );
			$this->widgets_registered = true;
		}
	}

	/**
	 * Handles AJAX product filtering.
	 */
	public function ajax_filter_products(): void {
		check_ajax_referer( Schrack_Product_Filter_Renderer::NONCE_ACTION, 'nonce' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce is not available.', 'schrack-woocommerce-sync' ),
				),
				400
			);
		}

		$config_raw = isset( $_POST['config'] ) ? wp_unslash( (string) $_POST['config'] ) : '{}';
		$config     = json_decode( $config_raw, true );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$filters = array(
			'search'          => isset( $_POST['search'] ) ? wp_unslash( (string) $_POST['search'] ) : '',
			'category'        => isset( $_POST['category'] ) ? wp_unslash( (string) $_POST['category'] ) : '',
			'category_search' => isset( $_POST['category_search'] ) ? wp_unslash( (string) $_POST['category_search'] ) : '',
			'min_price'       => isset( $_POST['min_price'] ) ? wp_unslash( (string) $_POST['min_price'] ) : '',
			'max_price'       => isset( $_POST['max_price'] ) ? wp_unslash( (string) $_POST['max_price'] ) : '',
			'orderby'         => isset( $_POST['orderby'] ) ? wp_unslash( (string) $_POST['orderby'] ) : '',
			'paged'           => isset( $_POST['paged'] ) ? wp_unslash( (string) $_POST['paged'] ) : 1,
		);

		wp_send_json_success( $this->renderer->render_results( $config, $filters ) );
	}

	/**
	 * Handles AJAX category picker search.
	 */
	public function ajax_filter_categories(): void {
		check_ajax_referer( Schrack_Product_Filter_Renderer::NONCE_ACTION, 'nonce' );

		$search   = isset( $_POST['search'] ) ? wp_unslash( (string) $_POST['search'] ) : '';
		$selected = isset( $_POST['selected'] ) ? absint( wp_unslash( (string) $_POST['selected'] ) ) : 0;
		$limit    = isset( $_POST['limit'] ) ? absint( wp_unslash( (string) $_POST['limit'] ) ) : 30;

		wp_send_json_success( $this->renderer->render_category_results( $search, $selected, $limit ) );
	}
}
