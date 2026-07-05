<?php
/**
 * Elementor integration for Schrack widgets.
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
		add_action( 'admin_post_' . Schrack_Registration_Renderer::ACTION, array( $this, 'handle_registration' ) );
		add_action( 'admin_post_nopriv_' . Schrack_Registration_Renderer::ACTION, array( $this, 'handle_registration' ) );
		add_action( 'admin_post_' . Schrack_Account_Renderer::ACTION, array( $this, 'handle_account_login' ) );
		add_action( 'admin_post_nopriv_' . Schrack_Account_Renderer::ACTION, array( $this, 'handle_account_login' ) );
		add_action( 'admin_post_' . Schrack_Account_Renderer::UPDATE_ACTION, array( $this, 'handle_account_update' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_legacy_widgets' ) );
		add_action( 'wp_ajax_' . Schrack_Product_Filter_Renderer::AJAX_ACTION, array( $this, 'ajax_filter_products' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Product_Filter_Renderer::AJAX_ACTION, array( $this, 'ajax_filter_products' ) );
		add_action( 'wp_ajax_' . Schrack_Product_Filter_Renderer::CATEGORY_AJAX_ACTION, array( $this, 'ajax_filter_categories' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Product_Filter_Renderer::CATEGORY_AJAX_ACTION, array( $this, 'ajax_filter_categories' ) );
		add_action( 'wp_ajax_' . Schrack_Header_Search_Renderer::AJAX_ACTION, array( $this, 'ajax_header_search' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Header_Search_Renderer::AJAX_ACTION, array( $this, 'ajax_header_search' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
		add_shortcode( 'schrack_account_page', array( $this, 'account_shortcode' ) );
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

		wp_register_style(
			'schrack-wc-product-page',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-product-page.css',
			array(),
			SCHRACK_WC_SYNC_VERSION
		);

		wp_register_style(
			'schrack-wc-header-search',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header-search.css',
			array(),
			SCHRACK_WC_SYNC_VERSION
		);

		wp_register_script(
			'schrack-wc-header-search',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header-search.js',
			array(),
			SCHRACK_WC_SYNC_VERSION,
			true
		);

		wp_register_style(
			'schrack-wc-header',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header.css',
			array(),
			$this->asset_version( 'assets/elementor-header.css' )
		);

		wp_register_script(
			'schrack-wc-header',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header.js',
			array(),
			$this->asset_version( 'assets/elementor-header.js' ),
			true
		);

		wp_register_style(
			'schrack-wc-registration',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-registration.css',
			array(),
			SCHRACK_WC_SYNC_VERSION
		);

		wp_register_style(
			'schrack-wc-account',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-account.css',
			array(),
			$this->asset_version( 'assets/elementor-account.css' )
		);

		wp_register_style(
			'schrack-wc-cart-checkout',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-cart-checkout.css',
			array(),
			$this->asset_version( 'assets/elementor-cart-checkout.css' )
		);

		wp_register_script(
			'schrack-wc-cart-checkout',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-cart-checkout.js',
			array(),
			$this->asset_version( 'assets/elementor-cart-checkout.js' ),
			true
		);

		wp_register_style(
			'schrack-wc-support',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-support.css',
			array(),
			$this->asset_version( 'assets/elementor-support.css' )
		);

		wp_register_script(
			'schrack-wc-support',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-support.js',
			array(),
			$this->asset_version( 'assets/elementor-support.js' ),
			true
		);

		wp_register_style(
			'schrack-wc-homepage',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-homepage.css',
			array(),
			$this->asset_version( 'assets/elementor-homepage.css' )
		);

		wp_register_style(
			'schrack-wc-footer',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-footer.css',
			array(),
			$this->asset_version( 'assets/elementor-footer.css' )
		);

		wp_register_style(
			'schrack-wc-funding',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-funding.css',
			array(),
			$this->asset_version( 'assets/elementor-funding.css' )
		);

		wp_register_script(
			'schrack-wc-homepage',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-homepage.js',
			array(),
			$this->asset_version( 'assets/elementor-homepage.js' ),
			true
		);
	}

	/**
	 * Returns a cache-busting asset version while developing Elementor modules.
	 */
	private function asset_version( string $relative_path ): string {
		$path = SCHRACK_WC_SYNC_PATH . ltrim( $relative_path, '/' );

		if ( is_readable( $path ) ) {
			return (string) filemtime( $path );
		}

		return SCHRACK_WC_SYNC_VERSION;
	}

	/**
	 * Enqueues the shop/archive polish layer for WooCommerce listing screens.
	 */
	public function enqueue_shop_archive_assets(): void {
		if ( ! $this->is_shop_archive_context() ) {
			return;
		}

		wp_enqueue_style( 'schrack-wc-shop-archive' );
		wp_enqueue_script( 'schrack-wc-shop-archive' );
	}

	/**
	 * Adds a body class used by the shop/archive polish layer.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function shop_archive_body_class( array $classes ): array {
		if ( $this->is_shop_archive_context() ) {
			$classes[] = 'schrack-shop-archive-page';
		}

		return $classes;
	}

	/**
	 * Translates common shop archive strings left by the active theme/widgets.
	 */
	public function translate_shop_archive_text( string $translation, string $text, string $domain ): string {
		if ( is_admin() ) {
			return $translation;
		}

		$map = array(
			'Out of stock' => 'Stoc epuizat',
			'Read more' => 'Detalii produs',
			'Add to cart' => 'Adauga in cos',
			'Select options' => 'Alege optiuni',
			'View cart' => 'Vezi cosul',
		);

		return $map[ $text ] ?? $translation;
	}

	/**
	 * Translates the shop page title when the page is rendered in the frontend.
	 */
	public function translate_shop_page_title( string $title, int $post_id = 0 ): string {
		if ( is_admin() || ! $this->is_shop_archive_context() ) {
			return $title;
		}

		if ( 'Shop' === trim( wp_strip_all_tags( $title ) ) ) {
			return __( 'Catalog produse', 'schrack-woocommerce-sync' );
		}

		return $title;
	}

	/**
	 * Translates the product category widget title.
	 *
	 * @param string       $title Widget title.
	 * @param mixed        $instance Widget instance.
	 * @param string|mixed $id_base Widget base id.
	 */
	public function translate_shop_widget_title( string $title, mixed $instance = array(), mixed $id_base = '' ): string {
		if ( is_admin() ) {
			return $title;
		}

		if ( 'Category' === trim( wp_strip_all_tags( $title ) ) ) {
			return __( 'Categorii', 'schrack-woocommerce-sync' );
		}

		return $title;
	}

	/**
	 * Translates WooCommerce archive page titles.
	 */
	public function translate_woocommerce_page_title( string $title ): string {
		if ( 'Shop' === trim( wp_strip_all_tags( $title ) ) ) {
			return __( 'Catalog produse', 'schrack-woocommerce-sync' );
		}

		return $title;
	}

	/**
	 * Translates stock availability labels.
	 */
	public function translate_availability_text( string $availability, WC_Product $product ): string {
		if ( ! $product->is_in_stock() ) {
			return __( 'Stoc epuizat', 'schrack-woocommerce-sync' );
		}

		return $availability;
	}

	/**
	 * Returns whether the current frontend request is a WooCommerce listing screen.
	 */
	private function is_shop_archive_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return true;
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return true;
		}

		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			return true;
		}

		if ( function_exists( 'is_page' ) && is_page( array( 'shop', 'shop-3' ) ) ) {
			return true;
		}

		$queried = get_queried_object();

		if ( is_object( $queried ) && isset( $queried->post_name ) ) {
			return in_array( (string) $queried->post_name, array( 'shop', 'shop-3' ), true );
		}

		return false;
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
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-search-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-page-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-registration-widgets.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-account-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-cart-checkout-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-pay-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-received-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-homepage-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-footer-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-funding-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-support-widget.php';

		$widgets = array(
			new Schrack_Elementor_Header_Widget(),
			new Schrack_Elementor_Homepage_Widget(),
			new Schrack_Elementor_Footer_Widget(),
			new Schrack_Elementor_Funding_Widget(),
			new Schrack_Elementor_Product_Filter_Widget(),
			new Schrack_Elementor_Header_Search_Widget(),
			new Schrack_Elementor_Product_Page_Widget(),
			new Schrack_Elementor_Customer_Register_Widget(),
			new Schrack_Elementor_B2B_Register_Widget(),
			new Schrack_Elementor_Account_Widget(),
			new Schrack_Elementor_Cart_Checkout_Widget(),
			new Schrack_Elementor_Order_Pay_Widget(),
			new Schrack_Elementor_Order_Received_Widget(),
			new Schrack_Elementor_Support_Widget(),
		);

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
			foreach ( $widgets as $widget ) {
				$widgets_manager->register( $widget );
			}

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
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-search-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-page-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-registration-widgets.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-account-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-cart-checkout-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-pay-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-received-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-homepage-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-footer-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-funding-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-support-widget.php';

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( new Schrack_Elementor_Header_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Homepage_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Footer_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Funding_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Product_Filter_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Header_Search_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Product_Page_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Customer_Register_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_B2B_Register_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Account_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Cart_Checkout_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Order_Pay_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Order_Received_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Support_Widget() );
			$this->widgets_registered = true;
		}
	}

	/**
	 * Refreshes header cart fragments after WooCommerce AJAX add-to-cart events.
	 *
	 * @param array<string,string> $fragments Existing fragments.
	 * @return array<string,string>
	 */
	public function cart_fragments( array $fragments ): array {
		$renderer = new Schrack_Header_Renderer();

		$fragments['span.schrack-header__cart-count'] = $renderer->cart_count_fragment();
		$fragments['span.schrack-header__cart-total'] = $renderer->cart_total_fragment();

		return $fragments;
	}

	/**
	 * Handles frontend registration form posts.
	 */
	public function handle_registration(): void {
		$renderer = new Schrack_Registration_Renderer();
		$renderer->handle_registration();
	}

	/**
	 * Handles frontend login form posts.
	 */
	public function handle_account_login(): void {
		$renderer = new Schrack_Account_Renderer();
		$renderer->handle_login();
	}

	/**
	 * Handles frontend account update form posts.
	 */
	public function handle_account_update(): void {
		$renderer = new Schrack_Account_Renderer();
		$renderer->handle_update();
	}

	/**
	 * Renders the account portal by shortcode.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function account_shortcode( array|string $atts = array() ): string {
		$settings = is_array( $atts ) ? $atts : array();
		$renderer = new Schrack_Account_Renderer();

		return $renderer->render( $settings, 'shortcode' );
	}

	/**
	 * Handles AJAX product filtering.
	 */
	public function ajax_filter_products(): void {
		check_ajax_referer( Schrack_Product_Filter_Renderer::NONCE_ACTION, 'nonce' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce nu este disponibil.', 'schrack-woocommerce-sync' ),
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
			'search'               => isset( $_POST['search'] ) ? wp_unslash( (string) $_POST['search'] ) : '',
			'category'             => isset( $_POST['category'] ) ? wp_unslash( (string) $_POST['category'] ) : '',
			'category_search'      => isset( $_POST['category_search'] ) ? wp_unslash( (string) $_POST['category_search'] ) : '',
			'min_price'            => isset( $_POST['min_price'] ) ? wp_unslash( (string) $_POST['min_price'] ) : '',
			'max_price'            => isset( $_POST['max_price'] ) ? wp_unslash( (string) $_POST['max_price'] ) : '',
			'include_out_of_stock' => isset( $_POST['include_out_of_stock'] ) ? wp_unslash( (string) $_POST['include_out_of_stock'] ) : '',
			'manufacturer'         => isset( $_POST['manufacturer'] ) ? wp_unslash( (string) $_POST['manufacturer'] ) : '',
			'product_line'         => isset( $_POST['product_line'] ) ? wp_unslash( (string) $_POST['product_line'] ) : '',
			'special_offer_only'   => isset( $_POST['special_offer_only'] ) ? wp_unslash( (string) $_POST['special_offer_only'] ) : '',
			'orderby'              => isset( $_POST['orderby'] ) ? wp_unslash( (string) $_POST['orderby'] ) : '',
			'paged'                => isset( $_POST['paged'] ) ? wp_unslash( (string) $_POST['paged'] ) : 1,
			'attributes'           => isset( $_POST['attr'] ) && is_array( $_POST['attr'] )
				? $this->renderer->attribute_filters_from_array( wp_unslash( $_POST['attr'] ) )
				: array(),
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

	/**
	 * Handles AJAX header product search.
	 */
	public function ajax_header_search(): void {
		check_ajax_referer( Schrack_Header_Search_Renderer::NONCE_ACTION, 'nonce' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce nu este disponibil.', 'schrack-woocommerce-sync' ),
				),
				400
			);
		}

		$config_raw = isset( $_POST['config'] ) ? wp_unslash( (string) $_POST['config'] ) : '{}';
		$config     = json_decode( $config_raw, true );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$search   = isset( $_POST['search'] ) ? wp_unslash( (string) $_POST['search'] ) : '';
		$renderer = new Schrack_Header_Search_Renderer();

		wp_send_json_success( $renderer->render_results( $search, $config ) );
	}
}
