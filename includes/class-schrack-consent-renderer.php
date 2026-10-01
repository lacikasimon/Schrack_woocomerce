<?php
/** Earlier native CookieAdmin banner text; consent still belongs to CookieAdmin. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Schrack_Consent_Renderer {
	private array $policy = array();
	private bool $rendered = false;
	private bool $bootstrapped = false;

	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'configure' ), 115 );
		add_filter( 'cookieadmin_after_banner', array( $this, 'render' ), 20 );
		add_action( 'wp_footer', array( $this, 'bootstrap' ), 11 );
		add_action( 'wp_body_open', array( $this, 'body_banner' ), 1 );
	}

	/** Only the inspected worldwide box banner without a GPC override is eligible. */
	public function configure(): void {
		if ( is_admin() || is_preview() || ! defined( 'COOKIEADMIN_VERSION' ) || '1.2.2' !== COOKIEADMIN_VERSION
			|| ! apply_filters( 'schrack_wc_sync_early_consent_banner', true ) ) { return; }
		$scripts = wp_scripts();
		$script = $scripts->registered['cookieadmin_js'] ?? null;
		if ( ! $script || ! $scripts->query( 'cookieadmin_js', 'enqueued' )
			|| $script->src !== plugins_url( 'cookieadmin/assets/js/consent.js' ) ) { return; }
		$policy = $this->configuration( $scripts->get_data( 'cookieadmin_js', 'data' ), 'cookieadmin_policy' );
		if ( $scripts->query( 'cookieadmin_pro_js', 'enqueued' ) ) {
			$pro_script = $scripts->registered['cookieadmin_pro_js'] ?? null;
			$pro = $this->configuration( $scripts->get_data( 'cookieadmin_pro_js', 'data' ), 'cookieadmin_pro_vars' );
			if ( ! $pro_script || $pro_script->src !== plugins_url( 'cookieadmin-pro/assets/js/consent.js' )
				|| '1.2.2' !== (string) $pro_script->ver || ! is_array( $pro )
				|| ! array_key_exists( 'respect_gpc', $pro ) || ! in_array( $pro['respect_gpc'], array( '', false, null, 0 ), true ) ) { return; }
		}
		if ( ! empty( $policy['is_pro'] ) && ! $scripts->query( 'cookieadmin_pro_js', 'enqueued' ) ) { return; }
		if ( ! is_array( $policy ) || 'box' !== ( $policy['cookieadmin_layout'] ?? '' )
			|| 'www' !== ( $policy['cookieadmin_geo_tgt'] ?? '' )
			|| ! in_array( $policy['cookieadmin_position'] ?? '', array( 'bottom_left', 'bottom_right', 'top_left', 'top_right' ), true ) ) { return; }
		foreach ( array( 'cookieadmin_notice_title', 'cookieadmin_notice', 'cookieadmin_customize_btn', 'cookieadmin_reject_btn', 'cookieadmin_accept_btn' ) as $key ) {
			if ( ! is_string( $policy[ $key ] ?? null ) || '' === trim( $policy[ $key ] ) || strlen( $policy[ $key ] ) > 4096 ) { return; }
		}
		$this->policy = $policy;
	}

	/** Themes without wp_body_open retain the native footer callback unchanged. */
	public function body_banner(): void {
		$callback = '\\CookieAdmin\\Enduser::cookieadmin_show_banner';
		if ( ! $this->policy || $this->rendered || is_admin() || is_preview()
			|| ( function_exists( 'is_product' ) && is_product() )
			|| ! apply_filters( 'schrack_wc_sync_body_consent_banner', true )
			|| 10 !== has_action( 'wp_footer', $callback ) || ! is_callable( $callback )
			|| ! defined( 'WP_PLUGIN_DIR' ) ) { return; }
		$expected = realpath( WP_PLUGIN_DIR . '/cookieadmin/includes/enduser.php' );
		$method = new ReflectionMethod( 'CookieAdmin\\Enduser', 'cookieadmin_show_banner' );
		if ( ! $expected || realpath( $method->getFileName() ) !== $expected ) { return; }
		// Call the original renderer once, including its sanitization and filters.
		remove_action( 'wp_footer', $callback, 10 );
		$callback();
		$this->bootstrap();
	}

	private function configuration( mixed $data, string $name ): ?array {
		if ( ! is_string( $data ) || ! preg_match( '~^\s*var ' . $name . '\s*=\s*(\{.*\});\s*$~sD', $data, $match ) ) { return null; }
		$config = json_decode( $match[1], true, 64 );
		return is_array( $config ) ? $config : null;
	}

	/** Same localized fields and native elements; no visitor-specific HTML cache. */
	public function render( string $html ): string {
		if ( ! $this->policy || $this->rendered || ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $html; }
		$changed = $html;
		foreach ( array( 'cookieadmin_notice_title', 'cookieadmin_notice' ) as $id ) {
			$changed = preg_replace_callback( '~(<p\b[^>]*\sid="' . $id . '"[^>]*>)\s*(</p>)~',
				fn( $m ) => $m[1] . wp_kses_post( $this->policy[ $id ] ) . $m[2], $changed, -1, $count );
			if ( ! is_string( $changed ) || 1 !== $count ) { return $html; }
		}
		foreach ( array( 'customize', 'reject', 'accept' ) as $button ) {
			$changed = preg_replace_callback( '~(<button\b[^>]*\sid="cookieadmin_' . $button . '_button"[^>]*>)[^<]*(</button>)~',
				fn( $m ) => $m[1] . esc_html( $this->policy[ 'cookieadmin_' . $button . '_btn' ] ) . $m[2], $changed, -1, $count );
			if ( ! is_string( $changed ) || 1 !== $count ) { return $html; }
		}
		$tags = new WP_HTML_Tag_Processor( $changed );
		while ( $tags->next_tag( 'DIV' ) ) {
			if ( $tags->has_class( 'cookieadmin_law_container' ) ) {
				$tags->add_class( 'cookieadmin_box' );
				// Native JS adds the two position classes separately, not one combined class.
				foreach ( explode( '_', $this->policy['cookieadmin_position'] ) as $position ) {
					$tags->add_class( 'cookieadmin_' . $position );
				}
				$this->rendered = true;
				return $tags->get_updated_html();
			}
		}
		return $html;
	}

	/** Runs just after the native footer markup, before deferred scripts finish. */
	public function bootstrap(): void {
		if ( ! $this->rendered || $this->bootstrapped ) { return; }
		$path = SCHRACK_WC_SYNC_PATH . 'assets/frontend-consent-banner.js';
		$js = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( is_string( $js ) && strlen( $js ) <= 8192 ) {
			$this->bootstrapped = true;
			wp_print_inline_script_tag( $js, array( 'id' => 'schrack-early-consent-banner', 'data-no-optimize' => '1', 'data-no-defer' => '1' ) );
		}
	}
}
