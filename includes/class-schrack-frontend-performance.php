<?php
/** Targeted first-paint optimizations and CookieAdmin/Google tag integration. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Frontend_Performance {
	private bool $consent_enabled = false;
	private int $catalog_inline_bytes = 0;
	private bool $onetap_on_demand = false;
	private bool $preload_product_image = false;

	public function init(): void {
		add_filter( 'style_loader_tag', array( $this, 'inline_critical_style' ), 20, 4 );
		add_filter( 'style_loader_tag', array( $this, 'inline_catalog_style' ), 21, 4 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_consent' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_onetap' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_product_gallery' ), 9 );
		add_filter( 'should_load_block_assets_on_demand', array( $this, 'catalog_block_assets' ) );
		add_action( 'wp_head', array( $this, 'consent_bridge' ), 2 );
		add_action( 'wp_head', array( $this, 'preload_product_image' ), 2 );
		add_filter( 'script_loader_tag', array( $this, 'script_tag' ), 20, 2 );
		add_filter( 'wp_inline_script_attributes', array( $this, 'inline_script_attributes' ) );
	}

	/** Let rendered blocks enqueue their own assets, including forms/audio/video. */
	public function catalog_block_assets( bool $on_demand ): bool {
		return $on_demand || ( ! is_admin() && $this->is_catalog_page() && apply_filters( 'schrack_wc_sync_catalog_block_assets', true ) );
	}

	/** Our gallery links to originals and does not use WooCommerce's lightbox. */
	public function configure_product_gallery(): void {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() || is_preview()
			|| ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' )
			|| ! apply_filters( 'schrack_wc_sync_trim_product_gallery', true ) ) {
			return;
		}
		$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
		if ( ! method_exists( $module, 'get_conditions_manager' ) ) {
			return;
		}
		$conditions = $module->get_conditions_manager();
		if ( ! method_exists( $conditions, 'get_documents_for_location' ) ) {
			return;
		}
		$documents = $conditions->get_documents_for_location( 'single' );
		// Ambiguous locations and unfamiliar widgets retain native gallery support.
		if ( ! is_array( $documents ) || 1 !== count( $documents ) ) {
			return;
		}
		$document = reset( $documents );
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_elements_data' ) ) {
			return;
		}
		$data = $document->get_elements_data();
		if ( ! is_array( $data ) || ! $this->uses_only_our_gallery( $data ) ) {
			return;
		}
		$this->preload_product_image = $this->uses_current_product_image( $data );
		// Rich product content can contain another gallery or a product shortcode.
		$post = get_queried_object();
		foreach ( array( $post->post_content ?? '', $post->post_excerpt ?? '' ) as $content ) {
			if ( has_blocks( $content ) || str_contains( $content, '[' ) || preg_match( '~<(?:script|iframe)\b~i', $content ) ) {
				return;
			}
		}
		foreach ( array( 'wc-product-gallery-lightbox', 'wc-product-gallery-slider', 'wc-product-gallery-zoom' ) as $feature ) {
			remove_theme_support( $feature );
		}
	}

	/** Discover the product hero in the head, before the large navigation markup. */
	public function preload_product_image(): void {
		if ( $this->preload_product_image && apply_filters( 'schrack_wc_sync_preload_product_image', true ) ) {
			do_action( 'schrack_wc_sync_product_image_preload' );
			$this->preload_product_image = false;
		}
	}

	/** Only a single, visible current-product gallery has a predictable hero. */
	private function uses_current_product_image( array $elements ): bool {
		$count = 0;
		$pending = $elements;
		while ( $pending ) {
			$element = array_pop( $pending );
			$settings = $element['settings'] ?? array();
			foreach ( array( '__dynamic__', 'hide_desktop', 'hide_tablet', 'hide_mobile', 'e_display_conditions' ) as $key ) {
				if ( ! empty( $settings[ $key ] ) ) {
					return false;
				}
			}
			if ( 'schrack_product_page' === ( $element['widgetType'] ?? '' ) ) {
				if ( 'current' !== ( $settings['product_source'] ?? 'current' ) || 'yes' !== ( $settings['show_gallery'] ?? 'yes' ) ) {
					return false;
				}
				++$count;
			}
			if ( ! empty( $element['elements'] ) ) {
				array_push( $pending, ...$element['elements'] );
			}
		}
		return 1 === $count;
	}

	/** Fail open for nested templates, third-party widgets and the native gallery. */
	private function uses_only_our_gallery( array $elements ): bool {
		$own = false;
		$pending = $elements;
		while ( $pending ) {
			$element = array_pop( $pending );
			$type = $element['elType'] ?? '';
			if ( 'widget' === $type ) {
				$widget = $element['widgetType'] ?? '';
				if ( ! in_array( $widget, array( 'schrack_product_page', 'woocommerce-product-data-tabs', 'woocommerce-breadcrumb', 'woocommerce-product-title', 'woocommerce-product-price', 'woocommerce-product-short-description', 'woocommerce-product-add-to-cart' ), true ) ) {
					return false;
				}
				$own = $own || 'schrack_product_page' === $widget;
			} elseif ( ! in_array( $type, array( 'container', 'section', 'column' ), true ) ) {
				return false;
			}
			if ( ! empty( $element['elements'] ) ) {
				array_push( $pending, ...$element['elements'] );
			}
		}
		return $own;
	}

	/** Only the inspected OneTap contract is delayed; updates retain native loading. */
	public function configure_onetap(): void {
		if ( is_admin() || ! $this->is_catalog_page() || ! defined( 'ACCESSIBILITY_ONETAP_VERSION' ) || '2.14.0' !== ACCESSIBILITY_ONETAP_VERSION
			|| ! wp_script_is( 'accessibility-onetap', 'enqueued' ) || ! wp_script_is( 'onetap-hotkeys-library', 'enqueued' )
			|| ! apply_filters( 'schrack_wc_sync_onetap_on_demand', true ) ) {
			return;
		}
		$scripts = wp_scripts();
		foreach ( array( 'accessibility-onetap' => 'script.min.js', 'onetap-hotkeys-library' => 'hotkeys.js' ) as $handle => $file ) {
			$source = $scripts->registered[ $handle ]->src ?? '';
			if ( strtok( $source, '?' ) !== plugins_url( 'accessibility-onetap/assets/js/' . $file ) ) {
				return;
			}
		}
		$this->onetap_on_demand = true;
		wp_add_inline_style( 'accessibility-onetap', '.schrack-onetap-error{position:fixed;bottom:90px;left:12px;right:12px;width:max-content;max-width:calc(100vw - 24px);margin:auto;padding:10px;background:#fff;color:#9b1c1c;border:1px solid currentColor;border-radius:6px;font:14px/1.5 system-ui,sans-serif;z-index:2147483647}' );
		wp_enqueue_script( 'schrack-wc-onetap-loader', SCHRACK_WC_SYNC_URL . 'assets/frontend-onetap.js', array( 'jquery' ), SCHRACK_WC_SYNC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/** Keep critical widget CSS in its original cascade position, including late assets. */
	public function inline_critical_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		$files = array(
			'schrack-wc-header'              => 'elementor-header.css',
			'schrack-wc-header-search'       => 'elementor-header-search.css',
			'schrack-wc-featured-categories' => 'elementor-featured-categories.css',
			'schrack-wc-product-page'        => 'elementor-product-page.css',
			'schrack-wc-support'             => 'elementor-support.css',
			'schrack-wc-product-filter'      => 'elementor-products.css',
			'schrack-wc-shop-archive'        => 'shop-archive.css',
			'schrack-wc-product-services'    => 'product-services.css',
		);
		if ( is_admin() || ! isset( $files[ $handle ] ) || ! apply_filters( 'schrack_wc_sync_inline_critical_css', true ) ) {
			return $tag;
		}
		$path = SCHRACK_WC_SYNC_PATH . 'assets/' . $files[ $handle ];
		if ( ! is_readable( $path ) ) {
			return $tag;
		}
		$css = file_get_contents( $path );
		// Relative/remote asset URLs must retain their stylesheet base URL.
		if ( ! is_string( $css ) || ! $this->can_inline_css( $css ) || strtok( $href, '?' ) !== SCHRACK_WC_SYNC_URL . 'assets/' . $files[ $handle ] ) {
			return $tag;
		}
		return '<style id="' . esc_attr( $handle . '-css' ) . '" media="' . esc_attr( $media ) . '" data-no-optimize="1">' . $css . '</style>';
	}

	/** Inline only known local layout files, at their original place in the cascade. */
	public function inline_catalog_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		if ( is_admin() || ! $this->is_catalog_page() || ! apply_filters( 'schrack_wc_sync_inline_catalog_css', true ) ) {
			return $tag;
		}
		$files = array(
			'hello-elementor'                      => 'themes/hello-elementor/assets/css/reset.css',
			'hello-elementor-theme-style'          => 'themes/hello-elementor/assets/css/theme.css',
			'hello-elementor-header-footer'        => 'themes/hello-elementor/assets/css/header-footer.css',
			'elementor-frontend'                   => 'plugins/elementor/assets/css/frontend.min.css',
			'widget-heading'                       => 'plugins/elementor/assets/css/widget-heading.min.css',
			'widget-woocommerce-product-price'     => 'plugins/elementor-pro/assets/css/widget-woocommerce-product-price.min.css',
			'widget-woocommerce-product-images'    => 'plugins/elementor-pro/assets/css/widget-woocommerce-product-images.min.css',
			'widget-woocommerce-product-data-tabs' => 'plugins/elementor-pro/assets/css/widget-woocommerce-product-data-tabs.min.css',
			'cookieadmin-style'                    => 'plugins/cookieadmin/assets/css/consent.css',
		);
		if ( ! isset( $files[ $handle ] ) || strtok( $href, '?' ) !== content_url( '/' . $files[ $handle ] ) ) {
			return $tag;
		}
		// Custom/CDN sources, conditional tags, RTL replacements and integrity
		// policies retain their original loading. Never fetch a remote stylesheet.
		if ( 1 !== preg_match( '~^\s*<link\b[^>]*>\s*$~i', $tag ) || preg_match( '~\b(integrity|onload|disabled)\b~i', $tag ) ) {
			return $tag;
		}
		$path = WP_CONTENT_DIR . '/' . $files[ $handle ];
		$size = is_readable( $path ) ? filesize( $path ) : false;
		if ( ! $size || $size > 65536 || $this->catalog_inline_bytes + $size > 131072 ) {
			return $tag;
		}
		$css = file_get_contents( $path );
		if ( ! is_string( $css ) || ! $this->can_inline_css( $css ) ) {
			return $tag;
		}
		$this->catalog_inline_bytes += strlen( $css );
		return '<style id="' . esc_attr( $handle . '-css' ) . '" media="' . esc_attr( $media ) . '" data-no-optimize="1">' . $css . '</style>';
	}

	private function is_catalog_page(): bool {
		return ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
	}

	/** Embedded image data is independent of the stylesheet's base URL. */
	private function can_inline_css( string $css ): bool {
		$without_data = preg_replace( '~url\s*\(\s*(?:"data:image/[^"\r\n]*"|\'data:image/[^\'\r\n]*\'|data:image/[^()\s]*)\s*\)~i', '', $css );
		return '' !== $css && ! preg_match( '~@import|</style~i', $css ) && is_string( $without_data ) && ! preg_match( '~url\s*\(~i', $without_data );
	}

	/** Integrate only when the existing consent manager is actually enqueued. */
	public function configure_consent(): void {
		$this->consent_enabled = wp_script_is( 'cookieadmin_js', 'enqueued' ) && function_exists( 'cookieadmin_load_policy' );
		if ( ! $this->consent_enabled ) {
			return;
		}
		wp_add_inline_style( 'cookieadmin-style', '.cookieadmin_law_container.cookieadmin_box{width:380px;max-width:calc(100vw - 24px);font-family:system-ui,sans-serif}.cookieadmin_law_container .cookieadmin_consent_inside{padding:14px}.cookieadmin_law_container #cookieadmin_notice{font-size:13px;line-height:1.45;margin:8px 0}.cookieadmin_law_container #cookieadmin_notice_title{font-size:16px;line-height:1.3;margin:0}.cookieadmin_law_container .cookieadmin_consent_btns{gap:6px;flex-wrap:wrap}.cookieadmin_law_container .cookieadmin_btn{min-height:40px;padding:8px 10px;font-size:12px}' );
	}

	/** Cache-neutral markup: every visitor's saved choice is evaluated in the browser. */
	public function consent_bridge(): void {
		if ( ! $this->consent_enabled ) {
			return;
		}
		$script = file_get_contents( SCHRACK_WC_SYNC_PATH . 'assets/frontend-consent.js' );
		if ( is_string( $script ) ) {
			wp_print_inline_script_tag( $script, array( 'id' => 'schrack-consent-bridge', 'data-no-optimize' => '1', 'data-no-defer' => '1' ) );
		}
	}

	/** Exclude consent initialization from LiteSpeed's delayed/minified script pipeline. */
	public function inline_script_attributes( array $attributes ): array {
		$id = (string) ( $attributes['id'] ?? '' );
		if ( ( $this->consent_enabled && str_starts_with( $id, 'cookieadmin_' ) )
			|| ( $this->onetap_on_demand && in_array( $id, array( 'accessibility-onetap-js-extra', 'onetap-hotkeys-library-js-extra' ), true ) ) ) {
			$attributes['data-no-optimize'] = '1';
			$attributes['data-no-defer'] = '1';
		}
		return $attributes;
	}

	public function script_tag( string $tag, string $handle ): string {
		if ( ( ! $this->consent_enabled && ! $this->onetap_on_demand ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $tag;
		}
		$processor = new WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( 'SCRIPT' ) ) {
			$src = $processor->get_attribute( 'src' );
			if ( ! is_string( $src ) ) {
				continue;
			}
			if ( $this->onetap_on_demand && in_array( $handle, array( 'accessibility-onetap', 'onetap-hotkeys-library' ), true ) ) {
				$processor->set_attribute( 'type', 'text/plain' );
				$processor->set_attribute( 'data-schrack-onetap-src', $src );
				$processor->set_attribute( 'data-no-optimize', '1' );
				$processor->set_attribute( 'data-no-defer', '1' );
				$processor->remove_attribute( 'src' );
			} elseif ( $this->onetap_on_demand && 'schrack-wc-onetap-loader' === $handle ) {
				$processor->set_attribute( 'data-no-optimize', '1' );
				$processor->set_attribute( 'data-no-defer', '1' );
			} elseif ( $this->consent_enabled && in_array( $handle, array( 'cookieadmin_js', 'cookieadmin_pro_js' ), true ) ) {
				$processor->set_attribute( 'data-no-optimize', '1' );
				$processor->set_attribute( 'data-no-defer', '1' );
				// Native ordered defer still finishes before DOMContentLoaded.
				$processor->set_attribute( 'defer', true );
			} elseif ( $this->consent_enabled && 'www.googletagmanager.com' === wp_parse_url( $src, PHP_URL_HOST ) && '/gtag/js' === wp_parse_url( $src, PHP_URL_PATH ) ) {
				$processor->set_attribute( 'type', 'text/plain' );
				$processor->set_attribute( 'data-schrack-consent-src', $src );
				$processor->set_attribute( 'data-no-optimize', '1' );
				$processor->set_attribute( 'data-no-defer', '1' );
				$processor->remove_attribute( 'src' );
			}
		}
		return $processor->get_updated_html();
	}
}
