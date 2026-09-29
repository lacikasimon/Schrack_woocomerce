<?php
/** Source-only WordPress markup tests. No WordPress bootstrap or database. */
require __DIR__ . '/frontend-lazy-images.php';
require_once ABSPATH . WPINC . '/script-loader.php';
require_once ABSPATH . WPINC . '/http.php';
require_once __DIR__ . '/../includes/class-schrack-frontend-performance.php';

$performance = new Schrack_Frontend_Performance();
$enabled = new ReflectionProperty( $performance, 'consent_enabled' );
$google = '<script id="google_gtagjs-js" src="https://www.googletagmanager.com/gtag/js?id=GT-TEST" async></script>';
verify_image( $google === $performance->script_tag( $google, 'google_gtagjs' ), 'Sites without CookieAdmin must be unaffected.' );
$enabled->setValue( $performance, true );
$gated = $performance->script_tag( $google, 'google_gtagjs' );
$tag = new WP_HTML_Tag_Processor( $gated );
$tag->next_tag( 'SCRIPT' );
verify_image( null === $tag->get_attribute( 'src' ) && 'text/plain' === $tag->get_attribute( 'type' ), 'Google must be absent from active src, not just delayed until interaction.' );
verify_image( 'https://www.googletagmanager.com/gtag/js?id=GT-TEST' === $tag->get_attribute( 'data-schrack-consent-src' ), 'Google URL must be preserved for actual consent.' );
verify_image( $gated === $performance->script_tag( $gated, 'google_gtagjs' ), 'Script processing must be idempotent.' );
foreach ( array( 'https://www.googletagmanager.com.evil.example/gtag/js', 'https://shop.example/cart.js', 'https://www.googletagmanager.com/gtm.js?id=GTM-OTHER' ) as $other_url ) {
	$other = '<script src="' . esc_url( $other_url ) . '"></script>';
	verify_image( $other === $performance->script_tag( $other, 'other' ), 'Unrelated integrations must not be intercepted.' );
}
$cookie_tag = $performance->script_tag( '<script src="/cookieadmin/consent.js"></script>', 'cookieadmin_js' );
verify_image( str_contains( $cookie_tag, 'data-no-optimize="1"' ) && str_contains( $cookie_tag, 'defer' ), 'Consent scripts must use ordered native defer and bypass LiteSpeed delay.' );
$inline = $performance->inline_script_attributes( array( 'id' => 'cookieadmin_js-js-extra' ) );
verify_image( '1' === $inline['data-no-optimize'], 'Localized consent configuration must stay before deferred consent scripts.' );
$link = '<link rel="stylesheet" href="/plugin/assets/elementor-header.css?ver=1" media="all">';
$style = $performance->inline_critical_style( $link, 'schrack-wc-header', '/plugin/assets/elementor-header.css?ver=1', 'all' );
verify_image( str_contains( $style, '<style' ) && str_contains( $style, '.schrack-header' ) && ! str_contains( $style, '<link' ), 'Header CSS must retain all rules without a blocking network request.' );
verify_image( $link === $performance->inline_critical_style( $link, 'unrelated', '/theme.css' ), 'Theme CSS must retain its original tag.' );
verify_image( $link === $performance->inline_critical_style( $link, 'schrack-wc-header', 'https://custom.example/override.css' ), 'Replaced stylesheets must not be silently overridden.' );
add_filter( 'schrack_wc_sync_inline_critical_css', '__return_false' );
verify_image( $link === $performance->inline_critical_style( $link, 'schrack-wc-header', '/plugin/assets/elementor-header.css' ), 'Rollback filter must restore external loading.' );
echo "Frontend performance total: {$checks} checks passed.\n";
