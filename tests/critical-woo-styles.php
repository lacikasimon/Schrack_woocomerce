<?php
/** Actual native CSS and public HTML fixtures; no WordPress boot, database or HTTP. */
require __DIR__ . '/frontend-performance.php';
require_once __DIR__ . '/../includes/class-schrack-critical-woo-styles.php';
function current_theme_supports( string $feature, mixed ...$args ): bool { return 'html5' === $feature; }
$source = $argv[6] ?? '';
$fixtures = $argv[7] ?? '';
if ( ! is_file( $source ) ) { throw new RuntimeException( 'Pass unmodified WooCommerce CSS as argument six and public HTML fixture directory as argument seven.' ); }
$css = file_get_contents( $source );
$manifest = json_decode( file_get_contents( SCHRACK_WC_SYNC_PATH . 'assets/performance/woocommerce-critical-rules.json' ), true );
verify_image( hash( 'sha256', $css ) === $manifest['source_sha256'], 'Manifest must match the actual native WooCommerce file.' );
$critical_woo = new Schrack_Critical_Woo_Styles();
$render_rules = new ReflectionMethod( $critical_woo, 'rules' );
foreach ( array( array( null ), array( array( 'children' => false ) ), array( array( 'selectors' => array( array( '.test', array( array() ) ) ), 'body' => 'color:red' ) ) ) as $broken_rules ) {
	verify_image( null === $render_rules->invoke( $critical_woo, $broken_rules, array() ), 'Incomplete rule manifests fail closed to the full native stylesheet.' );
}
$expected_url = plugins_url( 'woocommerce/assets/css/woocommerce.css' ) . '?ver=native';
foreach ( array( 'home', 'shop', 'product', 'category' ) as $scope ) {
	$GLOBALS['front_page_test'] = 'home' === $scope;
	$GLOBALS['catalog_test'] = 'product' === $scope;
	$GLOBALS['shop_test'] = 'shop' === $scope;
	$GLOBALS['taxonomy_test'] = 'category' === $scope;
	$fixture = $fixtures . '/schrack-current-' . $scope . '-133.html';
	if ( ! is_file( $fixture ) ) { throw new RuntimeException( 'Missing anonymous HTML fixture: ' . $fixture ); }
	$html = file_get_contents( $fixture );
	$html = str_replace( 'https://shop.syshub.ro/wp-content/plugins/woocommerce/assets', plugins_url( 'woocommerce/assets' ), $html );
	preg_match( '~<style\b[^>]*id="woocommerce-general-css"[^>]*>[^<]*</style>~s', $html, $original );
	$tag = new WP_HTML_Tag_Processor( $original[0] ); $tag->next_tag( 'STYLE' );
	$tag->set_attribute( 'data-schrack-woo-source', hash( 'sha256', $css ) );
	$tag->set_attribute( 'data-schrack-woo-href', $expected_url );
	$html = str_replace( $original[0], $tag->get_updated_html(), $html );
	if ( 'product' === $scope ) {
		verify_image( $html === $critical_woo->finalize( $html ), 'Single products retain full inline native CSS after the deferred-download measurement regressed.' );
		continue;
	}
	$result = $critical_woo->finalize( $html );
	preg_match( '~<style\b[^>]*id="woocommerce-general-css"[^>]*>([^<]*)</style>~s', $result, $inlined );
	verify_image( str_contains( $result, 'data-schrack-woo-critical="1"' ), 'Actual ' . $scope . ' CSS must split after LiteSpeed font rewriting.' );
	verify_image( strlen( $original[0] ) - strlen( $inlined[0] ) > 50000, 'Actual ' . $scope . ' removes at least 50 KiB of first-paint CSS.' );
	verify_image( 1 === substr_count( $result, 'id="schrack-woo-full-css"' ) && str_contains( $result, 'data-schrack-woo-media="all"' ) && str_contains( $result, '<noscript><link rel="stylesheet"' ), 'Native full URL is preserved for delayed, print and no-JS use.' );
	$full_link = new WP_HTML_Tag_Processor( $result );
	while ( $full_link->next_tag( 'LINK' ) && 'schrack-woo-full-css' !== $full_link->get_attribute( 'id' ) ) {}
	$deferred = 'home' === $scope;
	verify_image( $expected_url === $full_link->get_attribute( $deferred ? 'data-schrack-woo-deferred-href' : 'href' ), 'Full native CSS is fetched after load only on home/product; archive scheduling is retained.' );
	verify_image( ! $deferred || null === $full_link->get_attribute( 'href' ), 'Deferred native CSS does not compete with initial hero requests.' );
	verify_image( str_contains( $inlined[1], '.woocommerce-message' ) && str_contains( $inlined[1], '@font-face' ), 'Runtime notices and native icon faces stay critical even before AJAX insertion.' );
	verify_image( $result === $critical_woo->finalize( $result ), 'Finalization is idempotent.' );
	foreach ( array(
		str_replace( hash( 'sha256', $css ), str_repeat( '0', 64 ), $html ),
		str_replace( 'data-schrack-woo-href="', 'data-schrack-woo-href="https://other.example/', $html ),
		str_replace( '</style>', 'body{color:red}</style>', $html ),
		str_replace( 'id="woocommerce-general-css"', 'id="custom-css"', $html ),
	) as $unknown ) {
		verify_image( $unknown === $critical_woo->finalize( $unknown ), 'Unknown source, URL, transformed rules and ownership retain full original CSS.' );
	}
	add_filter( 'schrack_wc_sync_critical_woo_css', '__return_false' );
	verify_image( $html === $critical_woo->finalize( $html ), 'Rollback retains the full original native CSS.' );
	remove_filter( 'schrack_wc_sync_critical_woo_css', '__return_false' );
	$GLOBALS['preview_test'] = true;
	verify_image( $html === $critical_woo->finalize( $html ), 'Editor previews remain native.' );
	$GLOBALS['preview_test'] = false; $GLOBALS['shop_test'] = false; $GLOBALS['taxonomy_test'] = false; $GLOBALS['front_page_test'] = false; $GLOBALS['catalog_test'] = false;
	verify_image( $html === $critical_woo->finalize( $html ), 'Cart, checkout and account pages remain native.' );
	$GLOBALS['shop_test'] = 'shop' === $scope; $GLOBALS['taxonomy_test'] = 'category' === $scope;
	$GLOBALS['front_page_test'] = 'home' === $scope; $GLOBALS['catalog_test'] = 'product' === $scope;
	$public_preview = str_replace( plugins_url( 'woocommerce/assets' ), 'https://shop.syshub.ro/wp-content/plugins/woocommerce/assets', $result );
	file_put_contents( $fixtures . '/schrack-critical-preview-' . $scope . '.html', $public_preview );
	file_put_contents( $fixtures . '/schrack-critical-original-' . $scope . '.html', str_replace( plugins_url( 'woocommerce/assets' ), 'https://shop.syshub.ro/wp-content/plugins/woocommerce/assets', $html ) );
}
$GLOBALS['catalog_test'] = false; $GLOBALS['shop_test'] = false; $GLOBALS['taxonomy_test'] = false;
echo "Critical WooCommerce CSS total: {$checks} checks passed.\n";
