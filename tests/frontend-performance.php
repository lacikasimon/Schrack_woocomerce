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
$archive = $performance->inline_critical_style( $link, 'schrack-wc-shop-archive', '/plugin/assets/shop-archive.css' );
verify_image( str_contains( $archive, '<style' ) && str_contains( $archive, 'data:image/svg+xml' ), 'Self-contained archive CSS must retain its embedded checkbox icon.' );
$css_check = new ReflectionMethod( $performance, 'can_inline_css' );
foreach ( array( 'a{background:url(../image.png)}', '@import "theme.css";', 'a{content:"</style>"}', 'a{background:url("data:image/svg+xml,</style>")}' ) as $unsafe_css ) {
	verify_image( ! $css_check->invoke( $performance, $unsafe_css ), 'Imports, relative assets and closing tags must retain external loading.' );
}

// Installed-file fixtures only: no HTTP requests or WordPress/database bootstrap.
$fixture = sys_get_temp_dir() . '/schrack-css-' . bin2hex( random_bytes( 6 ) );
define( 'WP_CONTENT_DIR', $fixture );
function content_url( string $path = '' ): string { return 'https://shop.example/content' . $path; }
function is_product(): bool { return $GLOBALS['catalog_test'] ?? false; }
function is_shop(): bool { return false; }
function is_product_taxonomy(): bool { return false; }
function is_front_page(): bool { return $GLOBALS['front_page_test'] ?? false; }
function is_preview(): bool { return $GLOBALS['preview_test'] ?? false; }
$directory = $fixture . '/themes/hello-elementor/assets/css';
mkdir( $directory, 0700, true );
$path = $directory . '/reset.css';
$url = content_url( '/themes/hello-elementor/assets/css/reset.css' );
$external = '<link rel="stylesheet" href="' . $url . '?ver=3" media="screen">';
try {
	file_put_contents( $path, 'body{margin:0}' );
	verify_image( $external === $performance->inline_catalog_style( $external, 'hello-elementor', $url, 'screen' ), 'Non-catalog pages must retain normal theme loading.' );
	$GLOBALS['front_page_test'] = true;
	verify_image( str_contains( $performance->inline_catalog_style( $external, 'hello-elementor', $url, 'screen' ), '<style' ) && $performance->catalog_block_assets( false ), 'The storefront home reuses safe critical CSS and on-demand block assets.' );
	$GLOBALS['front_page_test'] = false;
$GLOBALS['catalog_test'] = true;
	verify_image( str_contains( $performance->inline_catalog_style( $external, 'woocommerce-layout', $url . '?ver=3', 'screen' ), '<style' ), 'Inspected local vendor handles can reuse self-contained rules.' );
	verify_image( $external === $performance->inline_catalog_style( $external, 'woocommerce-layout', content_url( '/themes/../themes/hello-elementor/assets/css/reset.css' ) ), 'Traversal paths must never be resolved for inlining.' );
	verify_image( $external === $performance->inline_catalog_style( $external, 'woocommerce-layout', 'https://cdn.example/reset.css' ), 'Inspected handles still preserve replaced external sources.' );
	verify_image( $performance->catalog_block_assets( false ), 'Catalog blocks must load their own assets on rendering.' );
	$inlined = $performance->inline_catalog_style( $external, 'hello-elementor', $url . '?ver=3', 'screen' );
	verify_image( str_contains( $inlined, '<style' ) && str_contains( $inlined, 'media="screen"' ) && str_contains( $inlined, 'body{margin:0}' ), 'Installed catalog layout rules and media must be preserved.' );
	verify_image( $external === $performance->inline_catalog_style( $external, 'hello-elementor', 'https://cdn.example/reset.css' ), 'A CDN replacement must not be replaced with a different local file.' );
	$conditional = '<!--[if IE]>' . $external . '<![endif]-->';
	verify_image( $conditional === $performance->inline_catalog_style( $conditional, 'hello-elementor', $url ), 'Conditional styles must be untouched.' );
	file_put_contents( $path, 'a{background:url(../image.svg)}' );
	clearstatcache();
	verify_image( $external === $performance->inline_catalog_style( $external, 'hello-elementor', $url ), 'Updated vendor CSS with relative assets must fall back to its external URL.' );
	file_put_contents( $path, str_repeat( ' ', 65537 ) );
	clearstatcache();
	verify_image( $external === $performance->inline_catalog_style( $external, 'hello-elementor', $url ), 'Vendor file size must remain bounded.' );
	file_put_contents( $path, 'body{margin:0}' );
	clearstatcache();
	( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 131072 );
	verify_image( $external === $performance->inline_catalog_style( $external, 'hello-elementor', $url ), 'The total inline budget must remain bounded.' );
} finally {
	unlink( $path );
	while ( str_starts_with( $directory, $fixture ) ) {
		rmdir( $directory );
		$directory = dirname( $directory );
	}
}
$GLOBALS['catalog_test'] = false;

// Generated Elementor CSS must match the current local upload directory exactly.
$generated = sys_get_temp_dir() . '/schrack-elementor-css-' . bin2hex( random_bytes( 6 ) );
$GLOBALS['uploads_test'] = array( 'basedir' => $generated, 'baseurl' => 'https://shop.example/uploads' );
define( 'WP_CONTENT_URL', 'https://shop.example/content' );
add_filter( 'pre_option_siteurl', static fn() => 'https://shop.example' );
add_filter( 'pre_option_upload_path', static fn() => '' );
add_filter( 'pre_option_upload_url_path', static fn() => '' );
add_filter( 'pre_option_uploads_use_yearmonth_folders', static fn() => 0 );
add_filter( 'upload_dir', static fn() => $GLOBALS['uploads_test'] );
mkdir( $generated . '/elementor/css', 0700, true );
$generated_path = $generated . '/elementor/css/post-123.css';
$generated_url = 'https://shop.example/uploads/elementor/css/post-123.css';
$generated_tag = '<link rel="stylesheet" href="' . $generated_url . '?ver=9" media="screen">';
$GLOBALS['front_page_test'] = true;
( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 0 );
try {
	file_put_contents( $generated_path, '.elementor-123{display:grid}' );
	verify_image( str_contains( $performance->inline_elementor_style( $generated_tag, 'elementor-post-123', $generated_url . '?ver=9', 'screen' ), '<style' ), 'Generated local layout CSS avoids an extra render-blocking request.' );
	verify_image( $generated_tag === $performance->inline_elementor_style( $generated_tag, 'elementor-post-123', 'https://cdn.example/post-123.css' ), 'External or replaced Elementor CSS remains untouched.' );
	$GLOBALS['preview_test'] = true;
	verify_image( $generated_tag === $performance->inline_elementor_style( $generated_tag, 'elementor-post-123', $generated_url ), 'Elementor previews retain external CSS.' );
	$GLOBALS['preview_test'] = false;
	file_put_contents( $generated_path, 'a{background:url(../asset.png)}' );
	clearstatcache();
	verify_image( $generated_tag === $performance->inline_elementor_style( $generated_tag, 'elementor-post-123', $generated_url ), 'Relative URLs preserve the original CSS base.' );
	file_put_contents( $generated_path, '</style><script>bad</script>' );
	clearstatcache();
	verify_image( $generated_tag === $performance->inline_elementor_style( $generated_tag, 'elementor-post-123', $generated_url ), 'Generated closing tags cannot become executable markup.' );
	file_put_contents( $generated_path, '.elementor-123{display:grid}' );
	clearstatcache();
	( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 131072 );
	verify_image( $generated_tag === $performance->inline_elementor_style( $generated_tag, 'elementor-post-123', $generated_url ), 'Generated styles share the bounded inline budget.' );
} finally {
	unlink( $generated_path ); rmdir( $generated . '/elementor/css' ); rmdir( $generated . '/elementor' ); rmdir( $generated );
	$GLOBALS['front_page_test'] = false;
}

// Exercise WordPress's actual strategy eligibility, including dependent fallbacks.
foreach ( array( 'class-wp-dependency.php', 'class-wp-dependencies.php', 'class-wp-scripts.php' ) as $file ) { require_once ABSPATH . WPINC . '/' . $file; }
$GLOBALS['wp_scripts'] = ( new ReflectionClass( WP_Scripts::class ) )->newInstanceWithoutConstructor();
$scripts = wp_scripts();
$scripts->add( 'jquery-core', '/jquery.js' );
$scripts->add( 'woocommerce', '/woocommerce.js', array( 'jquery-core' ) );
$scripts->enqueue( 'woocommerce' );
$GLOBALS['front_page_test'] = true;
$performance->configure_ordered_scripts();
$eligible = new ReflectionMethod( $scripts, 'get_eligible_loading_strategy' );
verify_image( 'defer' === $eligible->invoke( $scripts, 'jquery-core' ), 'WordPress can defer the complete known dependency chain in order.' );
$scripts->add_inline_script( 'woocommerce', 'window.example = true;', 'after' );
verify_image( '' === $eligible->invoke( $scripts, 'jquery-core' ), 'An after-inline dependent forces the entire chain back to blocking safely.' );
$scripts->add_data( 'woocommerce', 'after', array() );
$scripts->add( 'custom-dependent', '/custom.js', array( 'jquery-core' ) );
$scripts->enqueue( 'custom-dependent' );
( new ReflectionProperty( $scripts, 'dependents_map' ) )->setValue( $scripts, array() );
verify_image( '' === $eligible->invoke( $scripts, 'jquery-core' ), 'Unknown blocking dependents retain their required execution order.' );
$GLOBALS['front_page_test'] = false;
$gallery_check = new ReflectionMethod( $performance, 'uses_only_our_gallery' );
$own_widget = array( 'elType' => 'widget', 'widgetType' => 'schrack_product_page' );
$tabs_widget = array( 'elType' => 'widget', 'widgetType' => 'woocommerce-product-data-tabs' );
$hero_check = new ReflectionMethod( $performance, 'uses_current_product_image' );
verify_image( $hero_check->invoke( $performance, array( array( 'elType' => 'container', 'elements' => array( $own_widget, $tabs_widget ) ) ) ), 'One current product gallery may preload its hero.' );
foreach ( array( array( 'product_source' => 'custom' ), array( 'show_gallery' => '' ), array( 'hide_mobile' => 'hidden-mobile' ), array( '__dynamic__' => array( 'product_source' => 'tag' ) ), array( 'e_display_conditions' => array( 'condition' ) ) ) as $settings ) {
	$changed = $own_widget;
	$changed['settings'] = $settings;
	verify_image( ! $hero_check->invoke( $performance, array( $changed ) ), 'Custom, disabled, hidden or dynamic galleries must not preload a guessed hero.' );
}
verify_image( ! $hero_check->invoke( $performance, array( $own_widget, $own_widget ) ) && ! $hero_check->invoke( $performance, array( $tabs_widget ) ), 'Duplicate or missing galleries must not preload.' );
verify_image( ! $hero_check->invoke( $performance, array( array( 'settings' => array( 'hide_desktop' => 'hidden-desktop' ), 'elements' => array( $own_widget ) ) ) ), 'Hidden containers must prevent unnecessary hero downloads too.' );
$preload_calls = 0;
add_action( 'schrack_wc_sync_product_image_preload', static function () use ( &$preload_calls ): void { ++$preload_calls; } );
$hero_flag = new ReflectionProperty( $performance, 'preload_product_image' );
$performance->preload_product_image();
verify_image( 0 === $preload_calls, 'Unverified templates must not request a hero preload.' );
$hero_flag->setValue( $performance, true );
$performance->preload_product_image();
$performance->preload_product_image();
verify_image( 1 === $preload_calls, 'A verified gallery must preload at most once.' );
$hero_flag->setValue( $performance, true );
add_filter( 'schrack_wc_sync_preload_product_image', '__return_false' );
$performance->preload_product_image();
verify_image( 1 === $preload_calls, 'The rollback filter must suppress hero preloading.' );
verify_image( $gallery_check->invoke( $performance, array( array( 'elType' => 'container', 'elements' => array( $own_widget, $tabs_widget ) ) ) ), 'The custom product and native tabs do not require a native image gallery.' );
foreach ( array( 'woocommerce-product-images', 'template', 'global', 'shortcode', 'third-party-gallery' ) as $other_widget ) {
	verify_image( ! $gallery_check->invoke( $performance, array( $own_widget, array( 'elType' => 'widget', 'widgetType' => $other_widget ) ) ), 'Native galleries and unknown/nested content must retain all gallery libraries.' );
}
verify_image( ! $gallery_check->invoke( $performance, array( $tabs_widget ) ), 'Templates without the custom product renderer must retain gallery support.' );
verify_image( ! $performance->catalog_block_assets( false ), 'Non-catalog pages must keep the theme block-loading policy.' );
verify_image( $performance->catalog_block_assets( true ), 'An existing on-demand policy must remain enabled.' );
$onetap = new ReflectionProperty( $performance, 'onetap_on_demand' );
$onetap->setValue( $performance, true );
$enabled->setValue( $performance, false );
foreach ( array( 'accessibility-onetap', 'onetap-hotkeys-library' ) as $handle ) {
	$original = '<script id="' . $handle . '-js" nonce="test-nonce" src="/onetap.js" defer></script>';
	$inert = $performance->script_tag( $original, $handle );
	$parsed = new WP_HTML_Tag_Processor( $inert );
	$parsed->next_tag( 'SCRIPT' );
	verify_image( null === $parsed->get_attribute( 'src' ) && 'text/plain' === $parsed->get_attribute( 'type' ), 'OneTap libraries must not download or execute before activation.' );
	verify_image( '/onetap.js' === $parsed->get_attribute( 'data-schrack-onetap-src' ) && 'test-nonce' === $parsed->get_attribute( 'nonce' ), 'Original source and CSP nonce must survive.' );
	verify_image( $inert === $performance->script_tag( $inert, $handle ), 'Repeated OneTap processing must be idempotent.' );
	$config = $performance->inline_script_attributes( array( 'id' => $handle . '-js-extra' ) );
	verify_image( '1' === $config['data-no-defer'], 'Vendor configuration must remain ahead of activation.' );
}
verify_image( $google === $performance->script_tag( $google, 'google_gtagjs' ), 'OneTap optimization alone must not change Google consent behavior.' );
$loader = $performance->script_tag( '<script src="/loader.js" defer></script>', 'schrack-wc-onetap-loader' );
verify_image( str_contains( $loader, 'data-no-optimize="1"' ) && ! str_contains( $loader, 'text/plain' ), 'Small accessibility loader must execute normally and bypass LiteSpeed delay.' );
add_filter( 'schrack_wc_sync_inline_critical_css', '__return_false' );
verify_image( $link === $performance->inline_critical_style( $link, 'schrack-wc-header', '/plugin/assets/elementor-header.css' ), 'Rollback filter must restore external loading.' );
echo "Frontend performance total: {$checks} checks passed.\n";
