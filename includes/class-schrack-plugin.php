<?php
/**
 * Main plugin bootstrap.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Schrack_Plugin|null
	 */
	private static ?Schrack_Plugin $instance = null;

	/**
	 * Settings service.
	 *
	 * @var Schrack_Settings|null
	 */
	private ?Schrack_Settings $settings = null;

	/**
	 * Logger service.
	 *
	 * @var Schrack_Logger|null
	 */
	private ?Schrack_Logger $logger = null;

	/**
	 * Admin service.
	 *
	 * @var Schrack_Admin|null
	 */
	private ?Schrack_Admin $admin = null;

	/**
	 * Cron service.
	 *
	 * @var Schrack_Cron|null
	 */
	private ?Schrack_Cron $cron = null;

	/**
	 * Elementor integration.
	 *
	 * @var Schrack_Elementor|null
	 */
	private ?Schrack_Elementor $elementor = null;

	/**
	 * Frontend image loader.
	 *
	 * @var Schrack_Frontend_Image_Loader|null
	 */
	private ?Schrack_Frontend_Image_Loader $frontend_image_loader = null;

	/**
	 * B2B pricing service.
	 *
	 * @var Schrack_B2B_Pricing|null
	 */
	private ?Schrack_B2B_Pricing $b2b_pricing = null;

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): Schrack_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initializes the plugin.
	 */
	public function init(): void {
		$this->load_dependencies();

		$this->settings = new Schrack_Settings();
		$this->logger   = new Schrack_Logger( $this->settings );
		$this->cron     = new Schrack_Cron( $this->settings, $this->logger );
		$this->cron->init();
		$this->frontend_image_loader = new Schrack_Frontend_Image_Loader( $this->settings, $this->logger );
		$this->frontend_image_loader->init();
		$this->b2b_pricing = new Schrack_B2B_Pricing();
		$this->b2b_pricing->init();
		$this->elementor = new Schrack_Elementor();
		$this->elementor->init();

		add_action( 'wp_head', array( $this, 'render_favicons' ), 100 );
		add_action( 'admin_head', array( $this, 'render_favicons' ), 100 );
		add_action( 'login_head', array( $this, 'render_favicons' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_support_widget_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_support_widget' ), 5 );

		if ( is_admin() ) {
			$this->admin = new Schrack_Admin( $this->settings, $this->logger, $this->cron );
			$this->admin->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Schrack_WP_CLI::register( $this->settings, $this->logger, $this->cron );
		}

		add_action( 'admin_notices', array( $this, 'dependency_notices' ) );
	}

	/**
	 * Loads required class files.
	 */
	private function load_dependencies(): void {
		$files = array(
			'class-schrack-settings.php',
			'class-schrack-logger.php',
			'class-schrack-category-markup.php',
			'class-schrack-attribute-extractor.php',
			'class-schrack-soap-client.php',
			'class-schrack-product-mapper.php',
			'class-schrack-frontend-image-loader.php',
			'class-schrack-stock-label.php',
			'class-schrack-b2b-pricing.php',
			'class-schrack-catalog-importer.php',
			'class-schrack-telesystem-importer.php',
			'class-schrack-image-sync.php',
			'class-schrack-price-sync.php',
			'class-schrack-stock-sync.php',
			'class-schrack-product-filter-renderer.php',
			'class-schrack-header-renderer.php',
			'class-schrack-header-search-renderer.php',
			'class-schrack-product-page-renderer.php',
			'class-schrack-registration-renderer.php',
			'class-schrack-account-renderer.php',
			'class-schrack-cart-checkout-renderer.php',
			'class-schrack-homepage-renderer.php',
			'class-schrack-footer-renderer.php',
			'class-schrack-funding-renderer.php',
			'class-schrack-support-renderer.php',
			'class-schrack-elementor.php',
			'class-schrack-cron.php',
			'class-schrack-admin.php',
			'class-schrack-wp-cli.php',
		);

		foreach ( $files as $file ) {
			require_once SCHRACK_WC_SYNC_PATH . 'includes/' . $file;
		}
	}

	/**
	 * Activation checks and setup.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			wp_die( esc_html__( 'Schrack WooCommerce Sync requires PHP 8.1 or newer.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! extension_loaded( 'soap' ) ) {
			wp_die( esc_html__( 'Schrack WooCommerce Sync requires the PHP SOAP extension.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! class_exists( 'WooCommerce' ) && ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			wp_die( esc_html__( 'Schrack WooCommerce Sync requires WooCommerce to be installed and active.', 'schrack-woocommerce-sync' ) );
		}

		require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-settings.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-logger.php';

		Schrack_Settings::install_defaults();
		Schrack_Logger::create_table();
	}

	/**
	 * Deactivation cleanup.
	 */
	public static function deactivate(): void {
		require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-settings.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-cron.php';

		Schrack_Cron::clear_scheduled_actions();
	}

	/**
	 * Shows dependency notices after activation-time checks are no longer enough.
	 */
	public function dependency_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Schrack WooCommerce Sync is inactive until WooCommerce is active.', 'schrack-woocommerce-sync' )
			);
		}

		if ( ! extension_loaded( 'soap' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Schrack WooCommerce Sync requires the PHP SOAP extension.', 'schrack-woocommerce-sync' )
			);
		}
	}

	/**
	 * Enqueues global frontend support widget assets when enabled.
	 */
	public function enqueue_support_widget_assets(): void {
		if ( ! $this->is_support_widget_enabled() ) {
			return;
		}

		wp_enqueue_style( 'schrack-wc-support' );
		wp_enqueue_script( 'schrack-wc-support' );
	}

	/**
	 * Renders the global frontend support widget when enabled.
	 */
	public function render_support_widget(): void {
		if ( ! $this->is_support_widget_enabled() ) {
			return;
		}

		$renderer = new Schrack_Support_Renderer();

		echo $renderer->render( array(), 'global' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Prints the Syshub favicon used by the React website.
	 */
	public function render_favicons(): void {
		$svg_url = esc_url( SCHRACK_WC_SYNC_URL . 'assets/favicons/favicon.svg' );

		printf( '<link rel="icon" href="%s" type="image/svg+xml">' . "\n", $svg_url );
		printf( '<link rel="alternate icon" href="%s">' . "\n", $svg_url );
	}

	/**
	 * Checks whether the global support widget should be visible.
	 */
	private function is_support_widget_enabled(): bool {
		return ! is_admin() && $this->settings instanceof Schrack_Settings && 'yes' === $this->settings->get( 'support_widget_enabled', 'no' );
	}

	/**
	 * Returns settings.
	 */
	public function settings(): ?Schrack_Settings {
		return $this->settings;
	}

	/**
	 * Returns logger.
	 */
	public function logger(): ?Schrack_Logger {
		return $this->logger;
	}
}
