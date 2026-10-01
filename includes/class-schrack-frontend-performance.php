<?php
/** Targeted first-paint optimizations and CookieAdmin/Google tag integration. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Frontend_Performance {
	private bool $consent_enabled = false;
	private int $catalog_inline_bytes = 0;
	private int $vendor_inline_bytes = 0;
	private bool $onetap_on_demand = false;
	private string $onetap_languages_url = '';
	private bool $preload_product_image = false;
	private array $onetap_renderer = array();

	public function init(): void {
		add_filter( 'style_loader_tag', array( $this, 'inline_critical_style' ), 20, 4 );
		add_filter( 'style_loader_tag', array( $this, 'inline_core_common_style' ), 21, 4 );
		add_filter( 'style_loader_tag', array( $this, 'inline_catalog_style' ), 21, 4 );
		add_filter( 'style_loader_tag', array( $this, 'inline_elementor_style' ), 22, 4 );
		add_filter( 'style_loader_tag', array( $this, 'inline_local_font_style' ), 23, 4 );
		add_filter( 'litespeed_buffer_finalize', array( $this, 'restore_optional_font_display' ), 30 );
		add_filter( 'style_loader_tag', array( $this, 'inline_vendor_asset_style' ), 24, 4 );
		add_filter( 'style_loader_tag', array( $this, 'delay_onetap_panel_style' ), 23, 4 );
		add_filter( 'style_loader_tag', array( $this, 'delay_onetap_font_style' ), 25, 4 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_ordered_scripts' ), 110 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_consent' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_onetap' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_product_gallery' ), 9 );
		add_filter( 'should_load_block_assets_on_demand', array( $this, 'catalog_block_assets' ) );
		add_filter( 'should_load_separate_core_block_assets', array( $this, 'separate_core_block_assets' ) );
		add_action( 'wp_head', array( $this, 'consent_bridge' ), 2 );
		add_filter( 'wp_preload_resources', array( $this, 'preload_consent_scripts' ) );
		add_action( 'wp_head', array( $this, 'preload_product_image' ), 2 );
		add_filter( 'script_loader_tag', array( $this, 'script_tag' ), 20, 2 );
		add_filter( 'wp_inline_script_attributes', array( $this, 'inline_script_attributes' ) );
	}

	/** Native WordPress groups and strategies preserve dependency execution order. */
	public function configure_ordered_scripts(): void {
		if ( is_admin() || ! $this->is_catalog_page() || is_preview() || ! apply_filters( 'schrack_wc_sync_ordered_frontend_scripts', true ) ) { return; }
		$scripts = wp_scripts();
		foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-jquery-blockui', 'wc-js-cookie', 'woocommerce', 'wc-add-to-cart', 'wc-cart-fragments', 'wc-single-product' ) as $handle ) {
			if ( ! isset( $scripts->registered[ $handle ] ) ) {
				continue;
			}
			// The source-less jquery alias otherwise pulls its libraries into the
			// head. WordPress can still promote any dependency of a head script.
			$scripts->add_data( $handle, 'group', 1 );
			if ( $scripts->registered[ $handle ]->src && ! $scripts->get_data( $handle, 'strategy' ) ) {
				$scripts->add_data( $handle, 'strategy', 'defer' );
			}
		}
	}

	/** Let rendered blocks enqueue their own assets, including forms/audio/video. */
	public function catalog_block_assets( bool $on_demand ): bool {
		return $on_demand || ( ! is_admin() && $this->is_catalog_page() && apply_filters( 'schrack_wc_sync_catalog_block_assets', true ) );
	}

	/** Core block handles are registered before the main query knows the page type. */
	public function separate_core_block_assets( bool $separate ): bool {
		return $separate || ( ! is_admin() && apply_filters( 'schrack_wc_sync_separate_core_block_assets', true ) );
	}

	/** The small shared core rules still apply; individual blocks load natively. */
	public function inline_core_common_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		if ( is_admin() || ! $this->is_catalog_page() || is_preview() || 'wp-block-library' !== $handle
			|| ! apply_filters( 'schrack_wc_sync_inline_catalog_css', true )
			|| 1 !== preg_match( '~^\s*<link\b[^>]*>\s*$~i', $tag ) || preg_match( '~\b(integrity|onload|disabled)\b~i', $tag ) ) {
			return $tag;
		}
		$source = strtok( $href, '?' );
		$file = null;
		foreach ( array( 'common.min.css', 'common.css' ) as $name ) {
			if ( $source === includes_url( 'css/dist/block-library/' . $name ) ) {
				$file = ABSPATH . WPINC . '/css/dist/block-library/' . $name;
				break;
			}
		}
		if ( null === $file || ! is_readable( $file ) ) {
			return $tag;
		}
		$size = filesize( $file );
		if ( ! $size || $size > 16384 || $this->catalog_inline_bytes + $size > 131072 ) {
			return $tag;
		}
		$css = file_get_contents( $file );
		if ( ! is_string( $css ) || ! $this->can_inline_css( $css ) ) {
			return $tag;
		}
		$this->catalog_inline_bytes += strlen( $css );
		return '<style id="' . esc_attr( $handle . '-css' ) . '" media="' . esc_attr( $media ) . '" data-no-optimize="1">' . $css . '</style>';
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
		$this->configure_onetap_markup();
		$data = $scripts->get_data( 'accessibility-onetap', 'data' );
		$prepared = is_string( $data ) ? $this->prepare_onetap_languages( $data ) : null;
		if ( null !== $prepared ) {
			$scripts->add_data( 'accessibility-onetap', 'data', $prepared['data'] );
			$this->onetap_languages_url = $prepared['url'];
		}
		wp_add_inline_style( 'accessibility-onetap', '.schrack-onetap-error{position:fixed;bottom:90px;left:12px;right:12px;width:max-content;max-width:calc(100vw - 24px);margin:auto;padding:10px;background:#fff;color:#9b1c1c;border:1px solid currentColor;border-radius:6px;font:14px/1.5 system-ui,sans-serif;z-index:2147483647}' );
		wp_enqueue_script( 'schrack-wc-onetap-loader', SCHRACK_WC_SYNC_URL . 'assets/frontend-onetap.js', array( 'jquery' ), SCHRACK_WC_SYNC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/** Cache only public translations, never the nonce or visitor/site configuration. */
	private function prepare_onetap_languages( string $data ): ?array {
		if ( ! apply_filters( 'schrack_wc_sync_onetap_languages_on_demand', true )
			|| ! preg_match( '~^\s*var onetapAjaxObject\s*=\s*(\{.*\});\s*$~sD', $data, $match ) ) {
			return null;
		}
		$config = json_decode( $match[1], true, 64 );
		if ( ! is_array( $config ) || empty( $config['languages'] ) || ! is_array( $config['languages'] ) ) {
			return null;
		}
		$json = wp_json_encode( $config['languages'] );
		$url = is_string( $json ) ? $this->cache_onetap_asset( $json, 'languages', 16384, 786432 ) : null;
		if ( null === $url ) { return null; }
		$config['languages'] = new stdClass();
		$small = wp_json_encode( $config );
		return is_string( $small ) ? array( 'data' => 'var onetapAjaxObject = ' . $small . ';', 'url' => $url ) : null;
	}

	/** Only immutable public translations or native panel HTML may use this cache. */
	private function cache_onetap_asset( string $json, string $prefix, int $minimum, int $maximum ): ?string {
		if ( strlen( $json ) < $minimum || strlen( $json ) > $maximum ) { return null; }
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] )
			|| ! in_array( wp_parse_url( $uploads['baseurl'], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$root = realpath( $uploads['basedir'] );
		$directory = $uploads['basedir'] . '/schrack-frontend-cache/onetap';
		if ( ! $root || is_link( $uploads['basedir'] . '/schrack-frontend-cache' ) || is_link( $directory ) || ! wp_mkdir_p( $directory ) ) {
			return null;
		}
		$directory = realpath( $directory );
		if ( ! $directory || ! str_starts_with( $directory, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		$name = $prefix . '-' . hash( 'sha256', $json ) . '.json';
		$path = $directory . '/' . $name;
		if ( is_link( $path ) ) {
			return null;
		}
		if ( ! is_file( $path ) || filesize( $path ) !== strlen( $json ) ) {
			if ( ! is_writable( $directory ) ) {
				return null;
			}
			// Publish atomically; simultaneous uncached visits produce the same file.
			$temp = tempnam( $directory, '.onetap-' );
			if ( ! $temp ) {
				return null;
			}
			$written = @file_put_contents( $temp, $json, LOCK_EX );
			// tempnam creates mode 0600; set serving permissions before publication.
			$published = strlen( $json ) === $written && @chmod( $temp, 0644 ) && @rename( $temp, $path );
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
			if ( ! $published ) {
				return null;
			}
		}
		return trailingslashit( $uploads['baseurl'] ) . 'schrack-frontend-cache/onetap/' . $name;
	}

	/** Wrap just the inspected native footer callback; other footer output is untouched. */
	private function configure_onetap_markup(): void {
		global $wp_filter;
		if ( is_preview() || ! apply_filters( 'schrack_wc_sync_onetap_markup_on_demand', true )
			|| ! isset( $wp_filter['wp_footer']->callbacks[10] ) || $this->onetap_renderer ) { return; }
		foreach ( $wp_filter['wp_footer']->callbacks[10] as $registered ) {
			$callback = $registered['function'];
			if ( ! is_array( $callback ) || ! is_object( $callback[0] )
				|| 'Accessibility_Onetap_Public' !== get_class( $callback[0] ) || 'render_accessibility_template' !== $callback[1] ) { continue; }
			$method = new ReflectionMethod( $callback[0], $callback[1] );
			$path = realpath( WP_PLUGIN_DIR . '/accessibility-onetap/public/class-accessibility-onetap-public.php' );
			if ( ! $path || $path !== realpath( $method->getFileName() ) ) { return; }
			$this->onetap_renderer = $callback;
			remove_action( 'wp_footer', $callback, 10 );
			add_action( 'wp_footer', array( $this, 'render_onetap_markup' ), 10 );
			return;
		}
	}

	public function render_onetap_markup(): void {
		if ( ! $this->onetap_renderer ) { return; }
		ob_start();
		try {
			call_user_func( $this->onetap_renderer );
			$html = (string) ob_get_clean();
		} catch ( Throwable $error ) {
			ob_end_flush();
			throw $error;
		}
		// Already escaped native vendor output, transformed only at its known nav.
		echo $this->prepare_onetap_markup( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function prepare_onetap_markup( string $html ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || strlen( $html ) > 262144
			|| 1 !== preg_match_all( '~(<nav\b[^>]*\bclass="onetap-accessibility onetap-plugin-onetap"[^>]*>)(.*?)</nav>~s', $html, $matches, PREG_SET_ORDER ) ) { return $html; }
		$match = $matches[0];
		// No request config, executable code or unfamiliar nested templates in a public file.
		if ( ! preg_match( '~^\s*<section class="onetap-container">~', $match[2] )
			|| preg_match( '~<(?:nav|script|style|iframe)\b|<form\b(?!>)|\b(?:nonce|on\w+)\s*=~i', $match[2] )
			|| substr_count( $match[2], '<form>' ) > 1 ) { return $html; }
		$inputs = new WP_HTML_Tag_Processor( $match[2] );
		while ( $inputs->next_tag( 'INPUT' ) ) {
			if ( ! in_array( $inputs->get_attribute( 'type' ), array( 'checkbox', 'radio' ), true ) ) { return $html; }
		}
		$json = wp_json_encode( array( 'html' => $match[2] ) );
		$url = is_string( $json ) ? $this->cache_onetap_asset( $json, 'panel', 256, 262144 ) : null;
		if ( null === $url ) { return $html; }
		$shell = new WP_HTML_Tag_Processor( $match[1] . '</nav>' );
		if ( ! $shell->next_tag( 'NAV' ) ) { return $html; }
		$shell->set_attribute( 'data-schrack-onetap-markup', $url );
		$shell->set_attribute( 'inert', true );
		return str_replace( $match[0], $shell->get_updated_html(), $html );
	}

	/** Closed toolbar needs only its shell; load the full vendor CSS before activation. */
	public function delay_onetap_panel_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		if ( ! $this->onetap_on_demand || 'accessibility-onetap' !== $handle
			|| strtok( $href, '?' ) !== plugins_url( 'accessibility-onetap/assets/css/accessibility-onetap-front-end.min.css' )
			|| ! apply_filters( 'schrack_wc_sync_onetap_styles_on_demand', true )
			|| 1 !== preg_match( '~^\s*<link\b[^>]*>\s*$~i', $tag ) || preg_match( '~\b(integrity|onload|disabled)\b~i', $tag )
			|| ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $tag; }
		$path = SCHRACK_WC_SYNC_PATH . 'assets/frontend-onetap-bootstrap.css';
		$css = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( ! is_string( $css ) || strlen( $css ) > 8192 || ! $this->can_inline_css( $css ) ) { return $tag; }
		$processor = new WP_HTML_Tag_Processor( $tag );
		if ( ! $processor->next_tag( 'LINK' ) || $processor->get_attribute( 'href' ) !== $href ) { return $tag; }
		$processor->remove_attribute( 'href' );
		$processor->set_attribute( 'data-schrack-onetap-style-src', $href );
		$processor->set_attribute( 'data-no-optimize', '1' );
		return '<style id="schrack-onetap-bootstrap-css" data-no-optimize="1">' . $css . '</style>' . $processor->get_updated_html();
	}

	/** Readable fonts must not replace the theme's Roboto fallback before OneTap use. */
	public function delay_onetap_font_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		if ( ! $this->onetap_on_demand || 'accessibility-onetap-fonts-readable' !== $handle
			|| strtok( $href, '?' ) !== plugins_url( 'accessibility-onetap/assets/css/onetap-fonts-readable.min.css' )
			|| ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $tag;
		}
		$processor = new WP_HTML_Tag_Processor( $tag );
		if ( ! $processor->next_tag() || ! in_array( $processor->get_tag(), array( 'STYLE', 'LINK' ), true ) ) {
			return $tag;
		}
		$processor->set_attribute( 'data-schrack-onetap-font-media', $media );
		$processor->set_attribute( 'media', 'not all' );
		$processor->set_attribute( 'data-no-optimize', '1' );
		return $processor->get_updated_html();
	}

	/** Keep critical widget CSS in its original cascade position, including late assets. */
	public function inline_critical_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		$files = array(
			'schrack-wc-header'              => 'elementor-header.css',
			'schrack-wc-header-search'       => 'elementor-header-search.css',
			'schrack-wc-footer'              => 'elementor-footer.css',
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
		// Only these inspected storefront handles may resolve their own local path.
		// The file still has to be self-contained and within the shared size budget.
		if ( ! isset( $files[ $handle ] ) && in_array( $handle, array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general', 'accessibility-onetap', 'accessibility-onetap-fonts-readable', 'base-desktop', 'base-mobile' ), true ) ) {
			$base = content_url( '/' );
			$source = strtok( $href, '?' );
			if ( ! str_starts_with( $source, $base ) ) { return $tag; }
			$relative = substr( $source, strlen( $base ) );
			if ( ! preg_match( '~^[A-Za-z0-9_./-]+\.css$~D', $relative ) || preg_match( '~(?:^|/)\.\.?(?:/|$)~', $relative ) ) { return $tag; }
			$root = realpath( WP_CONTENT_DIR );
			$resolved = realpath( WP_CONTENT_DIR . '/' . $relative );
			if ( ! $root || ! $resolved || ! str_starts_with( $resolved, $root . DIRECTORY_SEPARATOR ) ) { return $tag; }
			$files[ $handle ] = $relative;
		}
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
		return is_front_page()
			|| ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
	}

	/** Inline self-contained generated layout CSS; fonts/relative URLs stay external. */
	public function inline_elementor_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		if ( is_admin() || ! $this->is_catalog_page() || is_preview()
			|| ! apply_filters( 'schrack_wc_sync_inline_elementor_css', true )
			|| ! preg_match( '/^elementor-post-([1-9][0-9]*)$/D', $handle, $match )
			|| 1 !== preg_match( '~^\s*<link\b[^>]*>\s*$~i', $tag )
			|| preg_match( '~\b(integrity|onload|disabled)\b~i', $tag ) ) { return $tag; }
		$uploads = wp_upload_dir( null, false );
		$relative = '/elementor/css/post-' . $match[1] . '.css';
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] )
			|| strtok( $href, '?' ) !== $uploads['baseurl'] . $relative ) { return $tag; }
		$path = $uploads['basedir'] . $relative;
		$size = is_readable( $path ) ? filesize( $path ) : false;
		if ( ! $size || $size > 65536 || $this->catalog_inline_bytes + $size > 131072 ) { return $tag; }
		$css = file_get_contents( $path );
		if ( ! is_string( $css ) || ! $this->can_inline_css( $css ) ) { return $tag; }
		$this->catalog_inline_bytes += strlen( $css );
		return '<style id="' . esc_attr( $handle . '-css' ) . '" media="' . esc_attr( $media ) . '" data-no-optimize="1">' . $css . '</style>';
	}

	/** Local Elementor font URLs are absolute, so their CSS base does not change. */
	public function inline_local_font_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		if ( is_admin() || ! $this->is_catalog_page() || is_preview()
			|| ! apply_filters( 'schrack_wc_sync_inline_local_font_css', true )
			|| ! preg_match( '/^elementor-gf-local-(poppins|figtree)$/D', $handle, $match )
			|| 1 !== preg_match( '~^\s*<link\b[^>]*>\s*$~i', $tag )
			|| preg_match( '~\b(integrity|onload|disabled)\b~i', $tag ) ) {
			return $tag;
		}
		$uploads = wp_upload_dir( null, false );
		$relative = '/elementor/google-fonts/css/' . $match[1] . '.css';
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] )
			|| strtok( $href, '?' ) !== $uploads['baseurl'] . $relative ) {
			return $tag;
		}
		$root = realpath( $uploads['basedir'] . '/elementor/google-fonts/css' );
		$path = realpath( $uploads['basedir'] . $relative );
		if ( ! $root || ! $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $path ) ) {
			return $tag;
		}
		$size = filesize( $path );
		if ( ! $size || $size > 65536 || $this->catalog_inline_bytes + $size > 196608 ) {
			return $tag;
		}
		$css = file_get_contents( $path );
		if ( ! is_string( $css ) || preg_match( '~@import|</style~i', $css ) ) {
			return $tag;
		}
		$prefix = preg_quote( $uploads['baseurl'] . '/elementor/google-fonts/fonts/' . $match[1] . '-', '~' );
		$without_fonts = preg_replace( '~url\s*\(\s*(["\']?)' . $prefix . '[a-f0-9]{8}\.woff2\1\s*\)~', '', $css, -1, $font_count );
		if ( ! $font_count || ! is_string( $without_fonts ) || preg_match( '~url\s*\(~i', $without_fonts ) ) {
			return $tag;
		}
		if ( 'figtree' === $match[1] && apply_filters( 'schrack_wc_sync_compact_figtree_fonts', true ) ) {
			$css = $this->compact_figtree_font_faces( $css, $uploads );
		}
		// Avoid late font swaps and competing high-priority font downloads on a
		// cold, slow connection. Warm fonts still retain the original typefaces.
		if ( apply_filters( 'schrack_wc_sync_optional_catalog_fonts', true ) ) {
			$css = preg_replace( '~\bfont-display\s*:\s*(?:swap|block|auto|fallback)\s*(?=;)~i', 'font-display:optional', $css );
			$marker = ' data-schrack-optional-font="' . esc_attr( $match[1] ) . '"';
		}
		$this->catalog_inline_bytes += strlen( $css );
		return '<style id="' . esc_attr( $handle . '-css' ) . '" media="' . esc_attr( $media ) . '" data-no-optimize="1"' . ( $marker ?? '' ) . '>' . $css . '</style>';
	}

	/** Replace duplicate static declarations for four inspected variable font files. */
	private function compact_figtree_font_faces( string $css, array $uploads ): string {
		// These actual WOFF2 files have a single wght axis spanning 300 through 900.
		// Replaced files keep Elementor's original CSS, including its matching rules.
		$hashes = array(
			'figtree-70ed8904.woff2' => 'a0cc105b3241fe952fd58ba226633f8ba733b16fd5419affbda6c8cc5c627a0b',
			'figtree-3530fccd.woff2' => '7242fa62d13d46172816b266f03c027999faa3755a52c2124bc2c440f6f4b983',
			'figtree-467cc915.woff2' => 'bf7828e2c258cffcfa50a048ee388a36b95bc16b452e8d36fa797635dbe15965',
			'figtree-3c512d8e.woff2' => '4ba7d3d096695818fe0686be4f1e82c6b05134e18a22260336130335027462dd',
		);
		$clean = preg_replace( '~/\*.*?\*/~s', '', $css );
		if ( ! is_string( $clean ) || 28 !== preg_match_all( '~@font-face\s*\{([^{}]+)\}~', $clean, $faces )
			|| '' !== trim( preg_replace( '~@font-face\s*\{[^{}]+\}~', '', $clean ) ?? $clean ) ) { return $css; }
		$groups = array();
		$prefix = preg_quote( $uploads['baseurl'] . '/elementor/google-fonts/fonts/', '~' );
		foreach ( $faces[1] as $body ) {
			if ( 1 !== preg_match_all( '~\bfont-weight\s*:\s*([3-9]00)\s*;~', $body, $weights )
				|| 1 !== preg_match_all( '~url\(\s*["\']?' . $prefix . '(figtree-[a-f0-9]{8}\.woff2)["\']?\s*\)~', $body, $sources )
				|| ! isset( $hashes[ $sources[1][0] ] ) ) { return $css; }
			$signature = preg_replace( '~\bfont-weight\s*:\s*[3-9]00\s*;~', 'font-weight:300 900;', $body );
			if ( ! is_string( $signature ) ) { return $css; }
			$groups[ $signature ]['weights'][] = (int) $weights[1][0];
		}
		if ( 4 !== count( $groups ) ) { return $css; }
		foreach ( $groups as $group ) {
			sort( $group['weights'] );
			if ( array( 300, 400, 500, 600, 700, 800, 900 ) !== $group['weights'] ) { return $css; }
		}
		$root = realpath( $uploads['basedir'] . '/elementor/google-fonts/fonts' );
		if ( ! $root ) { return $css; }
		foreach ( $hashes as $file => $hash ) {
			$path = realpath( $root . '/' . $file );
			if ( ! $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $path )
				|| filesize( $path ) > 32768 || hash_file( 'sha256', $path ) !== $hash ) { return $css; }
		}
		return implode( "\n", array_map( static fn( $body ) => '@font-face{' . $body . '}', array_keys( $groups ) ) );
	}

	/** LiteSpeed 7.9 rewrites every inline font face, even data-no-optimize styles. */
	public function restore_optional_font_display( string $html ): string {
		if ( is_admin() || ! $this->is_catalog_page() || is_preview()
			|| ! apply_filters( 'schrack_wc_sync_optional_catalog_fonts', true )
			|| ! str_contains( $html, 'data-schrack-optional-font=' ) ) { return $html; }
		return preg_replace_callback( '~(<style\b[^>]*>)([^<]{1,65535}+)(</style>)~i', static function( array $match ): string {
			if ( ! str_contains( $match[1], 'data-schrack-optional-font=' ) ) { return $match[0]; }
			$tag = new WP_HTML_Tag_Processor( $match[0] );
			if ( ! $tag->next_tag( 'STYLE' ) ) { return $match[0]; }
			$family = $tag->get_attribute( 'data-schrack-optional-font' );
			if ( ! in_array( $family, array( 'poppins', 'figtree' ), true )
				|| 'elementor-gf-local-' . $family . '-css' !== $tag->get_attribute( 'id' )
				|| '1' !== $tag->get_attribute( 'data-no-optimize' ) ) { return $match[0]; }
			$css = preg_replace( '~\bfont-display\s*:\s*(?:swap|block|auto|fallback)\s*(?=;)~i', 'font-display:optional', $match[2] );
			return $match[1] . $css . $match[3];
		}, $html ) ?? $html;
	}

	/** Preserve inspected vendor rules and resolve only their known local assets. */
	public function inline_vendor_asset_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		if ( str_contains( $tag, 'data-schrack-onetap-style-src=' ) ) { return $tag; }
		$files = array(
			'woocommerce-general'                => 'woocommerce/assets/css/woocommerce.css',
			'accessibility-onetap'               => 'accessibility-onetap/assets/css/accessibility-onetap-front-end.min.css',
			'accessibility-onetap-fonts-readable' => 'accessibility-onetap/assets/css/onetap-fonts-readable.min.css',
		);
		if ( is_admin() || ! $this->is_catalog_page() || is_preview() || ! isset( $files[ $handle ] )
			|| ! apply_filters( 'schrack_wc_sync_inline_vendor_asset_css', true )
			|| strtok( $href, '?' ) !== plugins_url( $files[ $handle ] )
			|| 1 !== preg_match( '~^\s*<link\b[^>]*>\s*$~i', $tag )
			|| preg_match( '~\b(integrity|onload|disabled)\b~i', $tag ) ) {
			return $tag;
		}
		$root = realpath( WP_PLUGIN_DIR );
		$path = realpath( WP_PLUGIN_DIR . '/' . $files[ $handle ] );
		if ( ! $root || ! $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $path ) ) {
			return $tag;
		}
		$size = filesize( $path );
		// This separate 192 KiB budget admits the three inspected files only.
		if ( ! $size || $size > 98304 || $this->vendor_inline_bytes + $size > 196608 ) {
			return $tag;
		}
		$css = file_get_contents( $path );
		$woo_source = 'woocommerce-general' === $handle && is_string( $css ) ? hash( 'sha256', $css ) : '';
		$css = is_string( $css ) ? $this->vendor_css_assets( $css, $handle ) : null;
		if ( null === $css || $this->vendor_inline_bytes + strlen( $css ) > 196608 ) {
			return $tag;
		}
		$this->vendor_inline_bytes += strlen( $css );
		$marker = '' !== $woo_source ? ' data-schrack-woo-source="' . esc_attr( $woo_source ) . '" data-schrack-woo-href="' . esc_url( $href ) . '"' : '';
		return '<style id="' . esc_attr( $handle . '-css' ) . '" media="' . esc_attr( $media ) . '" data-no-optimize="1"' . $marker . '>' . $css . '</style>';
	}

	/** Unfamiliar URL syntax or new assets retain the original stylesheet. */
	private function vendor_css_assets( string $css, string $handle ): ?string {
		if ( '' === $css || preg_match( '~@import|</style~i', $css ) ) {
			return null;
		}
		$allowed = array(
			'woocommerce-general' => '~^\.\./(?:fonts/WooCommerce\.(?:woff2?|ttf)|images/icons/(?:loader\.svg|credit-cards/(?:visa|mastercard|laser|diners|maestro|jcb|amex|discover)\.svg))$~D',
			'accessibility-onetap' => '~^\.\./images/cursor[123]\.png$~D',
			'accessibility-onetap-fonts-readable' => '~^\.\./fonts/Roboto/Roboto-(?:(?:Thin|Light|Regular|Medium|Bold|Black)(?:-Italic)?|Italic)\.woff2$~D',
		);
		if ( ! isset( $allowed[ $handle ] ) ) {
			return null;
		}
		$plugin = 'woocommerce-general' === $handle ? 'woocommerce' : 'accessibility-onetap';
		$valid = true;
		$resolved = preg_replace_callback( '~url\s*\(\s*("[^"\r\n]*"|\'[^\'\r\n]*\'|[^()\s]*)\s*\)~i', static function( array $match ) use ( $handle, $allowed, $plugin, &$valid ): string {
			$url = trim( $match[1], "\"'" );
			if ( str_starts_with( $url, 'data:image/' ) ) {
				return $match[0];
			}
			if ( ! preg_match( $allowed[ $handle ], $url ) ) {
				$valid = false;
				return $match[0];
			}
			return 'url("' . plugins_url( $plugin . '/assets/' . substr( $url, 3 ) ) . '")';
		}, $css );
		// Check the original too: malformed URL tokens must not evade validation.
		$probe = preg_replace( '~url\s*\(\s*("[^"\r\n]*"|\'[^\'\r\n]*\'|[^()\s]*)\s*\)~i', '', $css );
		return $valid && is_string( $resolved ) && is_string( $probe ) && ! preg_match( '~url\s*\(~i', $probe ) ? $resolved : null;
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

	/** Fetch the existing small consent scripts early; retain native execution order. */
	public function preload_consent_scripts( array $resources ): array {
		if ( ! $this->consent_enabled || is_admin() || ! $this->is_catalog_page() || is_preview()
			|| ! apply_filters( 'schrack_wc_sync_preload_consent_scripts', true ) ) { return $resources; }
		$scripts = wp_scripts();
		foreach ( array( 'cookieadmin_pro_js' => 'cookieadmin-pro/assets/js/consent.js', 'cookieadmin_js' => 'cookieadmin/assets/js/consent.js' ) as $handle => $path ) {
			$script = $scripts->registered[ $handle ] ?? null;
			if ( ! $script || ! $scripts->query( $handle, 'enqueued' ) || $script->src !== plugins_url( $path ) ) { continue; }
			$version = null === $script->ver ? null : ( $script->ver ?: $scripts->default_version );
			$src = $version ? add_query_arg( 'ver', $version, $script->src ) : $script->src;
			// A CDN/security filter can replace the actual source. Avoid a duplicate fetch.
			if ( apply_filters( 'script_loader_src', $src, $handle ) !== $src || isset( $scripts->args[ $handle ] ) ) { continue; }
			if ( null !== $this->native_consent_source( $handle ) ) { continue; }
			$resources[] = array( 'href' => $src, 'as' => 'script', 'fetchpriority' => 'high' );
		}
		return $resources;
	}

	/** Execute the same small native header assets without two extra network round trips. */
	private function native_consent_source( string $handle ): ?string {
		if ( ! $this->consent_enabled || is_admin() || ! $this->is_catalog_page() || is_preview()
			|| ! apply_filters( 'schrack_wc_sync_inline_native_consent', true ) ) { return null; }
		$files = array( 'cookieadmin_js' => 'cookieadmin/assets/js/consent.js', 'cookieadmin_pro_js' => 'cookieadmin-pro/assets/js/consent.js' );
		$script = wp_scripts()->registered[ $handle ] ?? null;
		if ( ! isset( $files[ $handle ] ) || ! $script || '1.2.2' !== (string) $script->ver
			|| $script->src !== plugins_url( $files[ $handle ] ) ) { return null; }
		$root = realpath( WP_PLUGIN_DIR );
		$path = realpath( WP_PLUGIN_DIR . '/' . $files[ $handle ] );
		if ( ! $root || ! $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) || ! is_readable( $path )
			|| filesize( $path ) < 512 || filesize( $path ) > 32768 ) { return null; }
		$code = file_get_contents( $path );
		return is_string( $code ) && ! preg_match( '~</script|<\?php|\x00~i', $code ) ? $code : null;
	}

	private function inline_native_consent_tag( string $tag, string $handle ): ?string {
		$code = $this->native_consent_source( $handle );
		if ( null === $code || ! preg_match( '~^\s*<script\b[^>]*>\s*</script>\s*$~i', $tag ) ) { return null; }
		$script = new WP_HTML_Tag_Processor( $tag );
		if ( ! $script->next_tag( 'SCRIPT' ) || null !== $script->get_attribute( 'integrity' )
			|| ! in_array( $script->get_attribute( 'type' ), array( null, 'text/javascript' ), true ) ) { return null; }
		$registered = wp_scripts()->registered[ $handle ];
		if ( $script->get_attribute( 'src' ) !== $registered->src . '?ver=1.2.2' ) { return null; }
		$script->remove_attribute( 'src' );
		$script->remove_attribute( 'defer' );
		$script->remove_attribute( 'async' );
		$script->remove_attribute( 'data-wp-strategy' );
		$script->set_attribute( 'data-no-optimize', '1' );
		$script->set_attribute( 'data-no-defer', '1' );
		// Retain original id, nonce and native dependency/localization order.
		return preg_replace_callback( '~>\s*</script>\s*$~', static fn() => '>' . $code . '</script>', $script->get_updated_html() );
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
		if ( in_array( $handle, array( 'cookieadmin_js', 'cookieadmin_pro_js' ), true ) ) {
			$inline = $this->inline_native_consent_tag( $tag, $handle );
			if ( null !== $inline ) { return $inline; }
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
				if ( '' !== $this->onetap_languages_url ) {
					$processor->set_attribute( 'data-schrack-onetap-languages', $this->onetap_languages_url );
				}
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
