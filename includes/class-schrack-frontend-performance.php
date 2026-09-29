<?php
/** Targeted first-paint optimizations and CookieAdmin/Google tag integration. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Frontend_Performance {
	private bool $consent_enabled = false;

	public function init(): void {
		add_filter( 'style_loader_tag', array( $this, 'inline_critical_style' ), 20, 4 );
		add_action( 'wp_enqueue_scripts', array( $this, 'configure_consent' ), 100 );
		add_action( 'wp_head', array( $this, 'consent_bridge' ), 2 );
		add_filter( 'script_loader_tag', array( $this, 'script_tag' ), 20, 2 );
		add_filter( 'wp_inline_script_attributes', array( $this, 'inline_script_attributes' ) );
	}

	/** Keep critical widget CSS in its original cascade position, including late assets. */
	public function inline_critical_style( string $tag, string $handle, string $href = '', string $media = 'all' ): string {
		$files = array(
			'schrack-wc-header'              => 'elementor-header.css',
			'schrack-wc-header-search'       => 'elementor-header-search.css',
			'schrack-wc-featured-categories' => 'elementor-featured-categories.css',
			'schrack-wc-product-page'        => 'elementor-product-page.css',
			'schrack-wc-support'             => 'elementor-support.css',
		);
		if ( is_admin() || ! isset( $files[ $handle ] ) || ! apply_filters( 'schrack_wc_sync_inline_critical_css', true ) ) {
			return $tag;
		}
		$path = SCHRACK_WC_SYNC_PATH . 'assets/' . $files[ $handle ];
		if ( ! is_readable( $path ) ) {
			return $tag;
		}
		$css = file_get_contents( $path );
		// These styles contain no imports or asset URLs. Retain external loading if
		// a future stylesheet introduces either, or if another plugin changed src.
		if ( ! is_string( $css ) || '' === $css || preg_match( '~url\s*\(|@import|</style~i', $css ) || strtok( $href, '?' ) !== SCHRACK_WC_SYNC_URL . 'assets/' . $files[ $handle ] ) {
			return $tag;
		}
		return '<style id="' . esc_attr( $handle . '-css' ) . '" media="' . esc_attr( $media ) . '" data-no-optimize="1">' . $css . '</style>';
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
		if ( $this->consent_enabled && str_starts_with( (string) ( $attributes['id'] ?? '' ), 'cookieadmin_' ) ) {
			$attributes['data-no-optimize'] = '1';
			$attributes['data-no-defer'] = '1';
		}
		return $attributes;
	}

	public function script_tag( string $tag, string $handle ): string {
		if ( ! $this->consent_enabled || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $tag;
		}
		$processor = new WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( 'SCRIPT' ) ) {
			$src = $processor->get_attribute( 'src' );
			if ( ! is_string( $src ) ) {
				continue;
			}
			if ( in_array( $handle, array( 'cookieadmin_js', 'cookieadmin_pro_js' ), true ) ) {
				$processor->set_attribute( 'data-no-optimize', '1' );
				$processor->set_attribute( 'data-no-defer', '1' );
				// Native ordered defer still finishes before DOMContentLoaded.
				$processor->set_attribute( 'defer', true );
			} elseif ( 'www.googletagmanager.com' === wp_parse_url( $src, PHP_URL_HOST ) && '/gtag/js' === wp_parse_url( $src, PHP_URL_PATH ) ) {
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
