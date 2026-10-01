<?php
/** Native WooCommerce rules needed by the actual HTML, followed by the full CSS. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Schrack_Critical_Woo_Styles {
	public function init(): void {
		add_filter( 'litespeed_buffer_finalize', array( $this, 'finalize' ), 32 );
	}

	public function finalize( string $html ): string {
		if ( is_admin() || is_preview() || isset( $_GET['elementor-preview'] )
			|| ! ( is_front_page() || ( function_exists( 'is_product' ) && is_product() )
				|| ( function_exists( 'is_shop' ) && is_shop() )
				|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) )
			|| ! apply_filters( 'schrack_wc_sync_critical_woo_css', true )
			|| ! class_exists( 'WP_HTML_Tag_Processor' ) || strlen( $html ) > 4194304
			|| str_contains( $html, 'data-schrack-woo-critical="1"' ) ) { return $html; }
		$manifest_path = SCHRACK_WC_SYNC_PATH . 'assets/performance/woocommerce-critical-rules.json';
		if ( ! is_readable( $manifest_path ) || filesize( $manifest_path ) > 262144 ) { return $html; }
		$manifest = json_decode( file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || 1 !== ( $manifest['format'] ?? null )
			|| ! is_string( $manifest['full'] ?? null ) || ! is_array( $manifest['rules'] ?? null ) ) { return $html; }
		if ( 1 !== preg_match_all( '~<style\b[^>]*\bdata-schrack-woo-source="[a-f0-9]{64}"[^>]*>([^<]*)</style>~s', $html, $styles ) ) { return $html; }
		$style = new WP_HTML_Tag_Processor( $styles[0][0] );
		if ( ! $style->next_tag( 'STYLE' ) || 'woocommerce-general-css' !== $style->get_attribute( 'id' )
			|| '1' !== $style->get_attribute( 'data-no-optimize' )
			|| ( $manifest['source_sha256'] ?? '' ) !== $style->get_attribute( 'data-schrack-woo-source' ) ) { return $html; }
		$href = $style->get_attribute( 'data-schrack-woo-href' );
		$media = $style->get_attribute( 'media' );
		if ( ! is_string( $href ) || strtok( $href, '?' ) !== plugins_url( 'woocommerce/assets/css/woocommerce.css' )
			|| ! in_array( $media, array( 'all', 'screen' ), true ) ) { return $html; }
		$base = plugins_url( 'woocommerce/assets' );
		$full = str_replace( '__SCHRACK_WOO_ASSETS__', $base, $manifest['full'] );
		if ( $this->without_font_display( $full ) !== $this->without_font_display( $styles[1][0] ) ) { return $html; }
		$classes = array();
		$document = new WP_HTML_Tag_Processor( $html );
		while ( $document->next_tag() ) {
			foreach ( preg_split( '/[\t\n\f\r ]+/', (string) $document->get_attribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
				$classes[ $class ] = true;
			}
		}
		$critical = $this->rules( $manifest['rules'], $classes );
		if ( null === $critical ) { return $html; }
		$critical = str_replace( '__SCHRACK_WOO_ASSETS__', $base, $critical );
		// Preserve LiteSpeed's actual font-display setting in the critical face.
		preg_match_all( '~@font-face\s*\{[^{}]*\}~', $styles[1][0], $fonts );
		$index = 0;
		$critical = preg_replace_callback( '~@font-face\s*\{[^{}]*\}~', static function() use ( $fonts, &$index ) {
			return $fonts[0][ $index++ ] ?? '';
		}, $critical );
		if ( ! is_string( $critical ) || $index !== count( $fonts[0] ) || str_contains( $critical, '</style' )
			|| strlen( $styles[1][0] ) - strlen( $critical ) < 8192 ) { return $html; }
		$loader = file_get_contents( SCHRACK_WC_SYNC_PATH . 'assets/frontend-woo-styles.js' );
		if ( ! is_string( $loader ) || strlen( $loader ) > 8192 || str_contains( $loader, '</script' ) ) { return $html; }
		$style->set_attribute( 'data-schrack-woo-critical', '1' );
		$opening = strstr( $style->get_updated_html(), '>', true ) . '>';
		// These routes already carry all rules matching their initial markup.
		// Fetch the remaining native CSS at idle after load, or on early input.
		$deferred = is_front_page() || ( function_exists( 'is_product' ) && is_product() );
		$link = '<link id="schrack-woo-full-css" rel="stylesheet" ' . ( $deferred ? 'data-schrack-woo-deferred-href' : 'href' ) . '="' . esc_url( $href )
			. '" media="not all" data-schrack-woo-media="' . esc_attr( $media ) . '" data-no-optimize="1">';
		$nojs = '<noscript><link rel="stylesheet" href="' . esc_url( $href ) . '" media="' . esc_attr( $media ) . '"></noscript>';
		$script = wp_get_inline_script_tag( $loader, array( 'id' => 'schrack-woo-styles-loader', 'data-no-optimize' => '1', 'data-no-defer' => '1' ) );
		return str_replace( $styles[0][0], $opening . $critical . '</style>' . $link . $nojs . $script, $html );
	}

	private function without_font_display( string $css ): string {
		return preg_replace_callback( '~@font-face\s*\{([^{}]*)\}~', static fn( $match ) =>
			'@font-face{' . trim( preg_replace( '~font-display\s*:\s*[^;}]+;?~', '', $match[1] ) ) . '}', $css ) ?? $css;
	}

	/** Positive class requirements are conservative; unknown selectors always stay. */
	private function rules( array $rules, array $classes ): ?string {
		$output = '';
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) { return null; }
			if ( isset( $rule['raw'] ) ) {
				if ( ! is_string( $rule['raw'] ) ) { return null; }
				$output .= $rule['raw']; continue;
			}
			if ( isset( $rule['children'] ) ) {
				if ( ! is_array( $rule['children'] ) || ! is_string( $rule['prefix'] ?? null ) ) { return null; }
				$inner = $this->rules( $rule['children'], $classes );
				if ( null === $inner ) { return null; }
				if ( '' !== $inner ) { $output .= $rule['prefix'] . '{' . $inner . '}'; }
				continue;
			}
			if ( ! is_array( $rule['selectors'] ?? null ) || ! is_string( $rule['body'] ?? null ) ) { return null; }
			$selectors = array();
			foreach ( $rule['selectors'] as $entry ) {
				if ( ! is_array( $entry ) || ! is_string( $entry[0] ?? null ) || ! is_array( $entry[1] ?? null ) ) { return null; }
				[ $selector, $required ] = $entry;
				$present = true;
				foreach ( $required as $class ) {
					if ( ! is_string( $class ) ) { return null; }
					if ( ! isset( $classes[ $class ] ) ) { $present = false; break; }
				}
				if ( $present ) { $selectors[] = $selector; }
			}
			if ( $selectors ) { $output .= implode( ',', $selectors ) . '{' . $rule['body'] . '}'; }
		}
		return $output;
	}
}
