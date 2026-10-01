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

// Three exact vendor files: preserve rules, media and asset destinations.
$vendor_fixture = sys_get_temp_dir() . '/schrack-vendor-css-' . bin2hex( random_bytes( 6 ) );
define( 'WP_PLUGIN_DIR', $vendor_fixture );
function plugins_url( string $path = '', string $plugin = '' ): string { return content_url( '/plugins/' . $path ); }
$vendor_files = array(
	'woocommerce-general' => array( 'woocommerce/assets/css/woocommerce.css', 'a{background:url("../images/icons/loader.svg")}@font-face{src:url(../fonts/WooCommerce.woff2)}', 'woocommerce/assets/images/icons/loader.svg' ),
	'accessibility-onetap' => array( 'accessibility-onetap/assets/css/accessibility-onetap-front-end.min.css', 'a{cursor:url(../images/cursor1.png),auto;background:url(data:image/svg+xml,%3Csvg%3E)}', 'accessibility-onetap/assets/images/cursor1.png' ),
	'accessibility-onetap-fonts-readable' => array( 'accessibility-onetap/assets/css/onetap-fonts-readable.min.css', '@font-face{font-family:Roboto;src:url(\'../fonts/Roboto/Roboto-Regular.woff2\')}@font-face{src:url(../fonts/Roboto/Roboto-Italic.woff2)}', 'accessibility-onetap/assets/fonts/Roboto/Roboto-Regular.woff2' ),
);
$GLOBALS['front_page_test'] = true;
try {
	foreach ( $vendor_files as $handle => [ $file, $css, $asset ] ) {
		$path = $vendor_fixture . '/' . $file;
		if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0700, true ); }
		$url = plugins_url( $file );
		$tag = '<link rel="stylesheet" href="' . $url . '" media="screen">';
		file_put_contents( $path, $css ); clearstatcache();
		$inlined = $performance->inline_vendor_asset_style( $tag, $handle, $url . '?ver=1', 'screen' );
		verify_image( str_contains( $inlined, '<style' ) && str_contains( $inlined, plugins_url( $asset ) ) && str_contains( $inlined, 'media="screen"' ), 'Vendor CSS preserves its original asset URL destination and media.' );
		verify_image( $tag === $performance->inline_vendor_asset_style( $tag, $handle, 'https://cdn.example/changed.css' ), 'Vendor source replacements retain native loading.' );
		$integrity = str_replace( '<link ', '<link integrity="sha256-test" ', $tag );
		verify_image( $integrity === $performance->inline_vendor_asset_style( $integrity, $handle, $url ), 'Integrity-tagged vendor CSS must not be rewritten.' );
		foreach ( array( $css . '@import "other.css";', $css . '</style><script>bad</script>', $css . 'a{background:url(../unknown.png)}', $css . 'a{background:url(https://other.example/image.png)}', $css . 'a{background:url(var(--image))}' ) as $changed_css ) {
			file_put_contents( $path, $changed_css ); clearstatcache();
			verify_image( $tag === $performance->inline_vendor_asset_style( $tag, $handle, $url ), 'Uninspected assets and unsafe or unfamiliar CSS retain external loading.' );
		}
		file_put_contents( $path, str_repeat( ' ', 98305 ) ); clearstatcache();
		verify_image( $tag === $performance->inline_vendor_asset_style( $tag, $handle, $url ), 'A vendor file cannot exceed the per-file limit.' );
		file_put_contents( $path, $css ); clearstatcache();
		( new ReflectionProperty( $performance, 'vendor_inline_bytes' ) )->setValue( $performance, 196608 );
		verify_image( $tag === $performance->inline_vendor_asset_style( $tag, $handle, $url ), 'The additional vendor inline budget is bounded.' );
		( new ReflectionProperty( $performance, 'vendor_inline_bytes' ) )->setValue( $performance, 0 );
	}
} finally {
	foreach ( $vendor_files as [ $file ] ) { unlink( $vendor_fixture . '/' . $file ); }
	foreach ( array( '/woocommerce/assets/css', '/woocommerce/assets', '/woocommerce', '/accessibility-onetap/assets/css', '/accessibility-onetap/assets', '/accessibility-onetap', '' ) as $dir ) { rmdir( $vendor_fixture . $dir ); }
	$GLOBALS['front_page_test'] = false;
}

// OneTap's font faces are inactive until its existing preference-aware loader runs.
$readable_url = plugins_url( 'accessibility-onetap/assets/css/onetap-fonts-readable.min.css' );
$readable_tag = '<link rel="stylesheet" href="' . $readable_url . '" media="screen">';
verify_image( $readable_tag === $performance->delay_onetap_font_style( $readable_tag, 'accessibility-onetap-fonts-readable', $readable_url, 'screen' ), 'Native OneTap loading must keep readable fonts active.' );
( new ReflectionProperty( $performance, 'onetap_on_demand' ) )->setValue( $performance, true );
foreach ( array( $readable_tag, '<style id="accessibility-onetap-fonts-readable-css" media="screen">@font-face{font-family:Roboto}</style>' ) as $font_tag ) {
	$delayed = $performance->delay_onetap_font_style( $font_tag, 'accessibility-onetap-fonts-readable', $readable_url . '?ver=2', 'screen' );
	verify_image( str_contains( $delayed, 'media="not all"' ) && str_contains( $delayed, 'data-schrack-onetap-font-media="screen"' ), 'Inline and external readable fonts preserve their media for activation on use.' );
}
verify_image( $readable_tag === $performance->delay_onetap_font_style( $readable_tag, 'unrelated', $readable_url ), 'Other font stylesheets must remain active.' );
verify_image( $readable_tag === $performance->delay_onetap_font_style( $readable_tag, 'accessibility-onetap-fonts-readable', 'https://cdn.example/fonts.css' ), 'Replaced OneTap font sources retain native loading.' );
( new ReflectionProperty( $performance, 'onetap_on_demand' ) )->setValue( $performance, false );

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
	mkdir( $generated . '/elementor/google-fonts/css', 0700, true );
	$font_path = $generated . '/elementor/google-fonts/css/poppins.css';
	$font_url = 'https://shop.example/uploads/elementor/google-fonts/css/poppins.css';
	$font_tag = '<link rel="stylesheet" href="' . $font_url . '" media="screen">';
	$font_css = '@font-face{font-family:Poppins;font-display:swap;src:url(https://shop.example/uploads/elementor/google-fonts/fonts/poppins-1234abcd.woff2) format("woff2")}';
	try {
		( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 0 );
		file_put_contents( $font_path, $font_css );
		$font_inline = $performance->inline_local_font_style( $font_tag, 'elementor-gf-local-poppins', $font_url . '?ver=1', 'screen' );
		verify_image( str_contains( $font_inline, '<style' ) && str_contains( $font_inline, $font_css ) && str_contains( $font_inline, 'media="screen"' ), 'Absolute local font URLs, swap behavior and cascade media are preserved verbatim.' );
		verify_image( $font_tag === $performance->inline_local_font_style( $font_tag, 'elementor-gf-local-poppins', 'https://cdn.example/poppins.css' ), 'Replaced font stylesheets retain native loading.' );
		foreach ( array( str_replace( 'https://shop.example/uploads/', '../', $font_css ), str_replace( 'shop.example', 'other.example', $font_css ), $font_css . '@import "other.css";', $font_css . '</style>' ) as $changed_css ) {
			file_put_contents( $font_path, $changed_css );
			clearstatcache();
			verify_image( $font_tag === $performance->inline_local_font_style( $font_tag, 'elementor-gf-local-poppins', $font_url ), 'Relative, external, imported or unsafe font CSS must fall back unchanged.' );
		}
		file_put_contents( $font_path, $font_css );
		clearstatcache();
		( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 196608 );
		verify_image( $font_tag === $performance->inline_local_font_style( $font_tag, 'elementor-gf-local-poppins', $font_url ), 'Font CSS shares the total inline budget.' );
	} finally {
		unlink( $font_path ); rmdir( $generated . '/elementor/google-fonts/css' ); rmdir( $generated . '/elementor/google-fonts' );
	}
} finally {
	unlink( $generated_path ); rmdir( $generated . '/elementor/css' ); rmdir( $generated . '/elementor' ); rmdir( $generated );
	$GLOBALS['front_page_test'] = false;
}

// Public translations are immutable assets; nonce/configuration stay request-local.
$language_directory = sys_get_temp_dir() . '/schrack-onetap-languages-' . bin2hex( random_bytes( 6 ) );
mkdir( $language_directory, 0700 );
$previous_uploads = $GLOBALS['uploads_test'];
$GLOBALS['uploads_test'] = array( 'basedir' => $language_directory, 'baseurl' => 'https://shop.example/uploads' );
$prepare = new ReflectionMethod( $performance, 'prepare_onetap_languages' );
$labels = array( 'en' => array( 'header' => array( 'title' => str_repeat( 'Accessible ', 1800 ) ) ), 'ro' => array( 'header' => array( 'title' => 'Accesibilitate & </script>' ) ) );
$configuration = array( 'ajaxUrl' => 'https://shop.example/admin-ajax.php', 'nonce' => 'not-for-public-file', 'activeLanguage' => 'ro', 'languages' => $labels, 'showModules' => array( 'readable-font' => 'on' ) );
$localized = 'var onetapAjaxObject = ' . wp_json_encode( $configuration ) . ';';
try {
	$prepared = $prepare->invoke( $performance, $localized );
	verify_image( is_array( $prepared ), 'The inspected standalone configuration can externalize its large public translations.' );
	$asset = $language_directory . '/schrack-frontend-cache/onetap/' . basename( $prepared['url'] );
	$public_json = file_get_contents( $asset );
	verify_image( $labels === json_decode( $public_json, true ), 'Every language and label must survive serialization exactly.' );
	verify_image( ! str_contains( $public_json, 'not-for-public-file' ) && ! str_contains( $public_json, 'admin-ajax.php' ) && ! str_contains( $public_json, 'showModules' ), 'Public cache files must exclude nonce, AJAX URL and site/visitor settings.' );
	$small_config = json_decode( substr( $prepared['data'], strlen( 'var onetapAjaxObject = ' ), -1 ), true );
	verify_image( $small_config['nonce'] === $configuration['nonce'] && $small_config['activeLanguage'] === 'ro' && $small_config['showModules'] === $configuration['showModules'] && empty( $small_config['languages'] ), 'Request-local settings must remain intact before asynchronous activation.' );
	verify_image( ! str_contains( $prepared['data'], '</script>' ), 'Localized inline configuration must retain safe JSON encoding.' );
	verify_image( $prepared === $prepare->invoke( $performance, $localized ) && 1 === count( glob( dirname( $asset ) . '/*.json' ) ), 'Identical requests must reuse one immutable translation file.' );
	$configuration['nonce'] = 'new-request-nonce';
	$updated = $prepare->invoke( $performance, 'var onetapAjaxObject = ' . wp_json_encode( $configuration ) . ';' );
	verify_image( $updated['url'] === $prepared['url'] && str_contains( $updated['data'], 'new-request-nonce' ), 'Nonce rotation must not alter or enter the public language asset.' );
	$configuration['languages']['ro']['header']['title'] = 'Traducere nouă';
	$updated = $prepare->invoke( $performance, 'var onetapAjaxObject = ' . wp_json_encode( $configuration ) . ';' );
	verify_image( $updated['url'] !== $prepared['url'], 'Changed translations must produce a fresh cache URL.' );
	foreach ( array( $localized . 'var other = true;', 'var other = {};', 'var onetapAjaxObject = {broken};', 'var onetapAjaxObject = {"languages":[]};', 'var onetapAjaxObject = {"languages":{"en":"small"}};', 'var onetapAjaxObject = ' . wp_json_encode( array( 'languages' => array( 'en' => str_repeat( 'x', 786433 ) ) ) ) . ';' ) as $other_data ) {
		verify_image( null === $prepare->invoke( $performance, $other_data ), 'Changed vendor contracts, invalid, small and oversized payloads must retain native inline loading.' );
	}
	$GLOBALS['uploads_test']['error'] = 'Unavailable';
	verify_image( null === $prepare->invoke( $performance, $localized ), 'Unavailable uploads must retain the complete original inline data.' );
	unset( $GLOBALS['uploads_test']['error'] );
	( new ReflectionProperty( $performance, 'onetap_languages_url' ) )->setValue( $performance, $prepared['url'] );
	( new ReflectionProperty( $performance, 'onetap_on_demand' ) )->setValue( $performance, true );
	$loader_tag = $performance->script_tag( '<script src="/loader.js" defer></script>', 'schrack-wc-onetap-loader' );
	verify_image( str_contains( $loader_tag, 'data-schrack-onetap-languages="' . $prepared['url'] . '"' ), 'The normal loader must receive the public asset URL.' );
	add_filter( 'schrack_wc_sync_onetap_languages_on_demand', '__return_false' );
	verify_image( null === $prepare->invoke( $performance, $localized ), 'The language rollback filter must restore native inline data.' );
	remove_filter( 'schrack_wc_sync_onetap_languages_on_demand', '__return_false' );
} finally {
	foreach ( glob( $language_directory . '/schrack-frontend-cache/onetap/*.json' ) as $file ) { unlink( $file ); }
	rmdir( $language_directory . '/schrack-frontend-cache/onetap' ); rmdir( $language_directory . '/schrack-frontend-cache' ); rmdir( $language_directory );
	$GLOBALS['uploads_test'] = $previous_uploads;
	( new ReflectionProperty( $performance, 'onetap_languages_url' ) )->setValue( $performance, '' );
	( new ReflectionProperty( $performance, 'onetap_on_demand' ) )->setValue( $performance, false );
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

// Exercise native grouping, including the source-less core jQuery alias.
$GLOBALS['wp_scripts'] = ( new ReflectionClass( WP_Scripts::class ) )->newInstanceWithoutConstructor();
$scripts = wp_scripts();
$scripts->add( 'jquery-core', '/jquery.js' );
$scripts->add( 'jquery-migrate', '/migrate.js', array( 'jquery-core' ) );
$scripts->add( 'jquery', false, array( 'jquery-core', 'jquery-migrate' ) );
$scripts->add( 'woocommerce', '/woocommerce.js', array( 'jquery' ) );
$scripts->add( 'wc-single-product', '/single-product.js', array( 'jquery' ) );
$scripts->add_inline_script( 'woocommerce', 'window.example = true;', 'after' );
$scripts->enqueue( 'woocommerce' );
$scripts->enqueue( 'wc-single-product' );
$performance->configure_ordered_scripts();
$scripts->all_deps( $scripts->queue );
verify_image( 1 === $scripts->groups['jquery'] && 1 === $scripts->groups['jquery-core'] && 1 === $scripts->groups['woocommerce'], 'The full native chain remains in the footer even when after-inline code prevents defer.' );
verify_image( 1 === $scripts->groups['wc-single-product'], 'The native product tabs/reviews script must not pull the full jQuery chain into the head.' );
$scripts->add( 'head-dependent', '/head.js', array( 'jquery' ) );
$scripts->enqueue( 'head-dependent' );
$scripts->all_deps( $scripts->queue );
verify_image( 0 === $scripts->groups['jquery-core'] && 0 === $scripts->groups['jquery-migrate'], 'A real header dependent still promotes both jQuery libraries before itself.' );
$GLOBALS['front_page_test'] = false;
verify_image( $performance->separate_core_block_assets( false ), 'Separate core block styles must be enabled before the main query exists.' );
add_filter( 'schrack_wc_sync_separate_core_block_assets', '__return_false' );
verify_image( ! $performance->separate_core_block_assets( false ) && $performance->separate_core_block_assets( true ), 'Rollback must preserve an existing native separate-style policy.' );
remove_filter( 'schrack_wc_sync_separate_core_block_assets', '__return_false' );
function includes_url( string $path = '' ): string { return 'https://shop.example/wp-includes/' . $path; }
$GLOBALS['front_page_test'] = true;
$common_url = includes_url( 'css/dist/block-library/common.min.css' );
$common_tag = '<link rel="stylesheet" href="' . $common_url . '" media="screen">';
( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 0 );
$common_inline = $performance->inline_core_common_style( $common_tag, 'wp-block-library', $common_url . '?ver=7', 'screen' );
verify_image( str_contains( $common_inline, '<style' ) && str_contains( $common_inline, 'media="screen"' ) && str_contains( $common_inline, file_get_contents( ABSPATH . WPINC . '/css/dist/block-library/common.min.css' ) ), 'Shared native core block rules must remain intact at their cascade position.' );
verify_image( $common_tag === $performance->inline_core_common_style( $common_tag, 'wp-block-library', includes_url( 'css/dist/block-library/style.min.css' ) ), 'The full core block stylesheet must never be silently replaced by common rules.' );
verify_image( $common_tag === $performance->inline_core_common_style( $common_tag, 'wp-block-library', 'https://cdn.example/common.min.css' ), 'Replaced shared core sources keep native loading.' );
( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 131072 );
verify_image( $common_tag === $performance->inline_core_common_style( $common_tag, 'wp-block-library', $common_url ), 'Shared core rules must obey the existing catalog inline budget.' );
$GLOBALS['front_page_test'] = false;
$GLOBALS['preview_test'] = false;
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
// Preload only the native consent sources, with exactly matching versioned URLs.
$enabled->setValue( $performance, true );
$GLOBALS['front_page_test'] = true;
$scripts = wp_scripts();
foreach ( array( 'cookieadmin_js' => 'cookieadmin/assets/js/consent.js', 'cookieadmin_pro_js' => 'cookieadmin-pro/assets/js/consent.js' ) as $handle => $path ) {
 $scripts->remove( $handle ); $scripts->add( $handle, plugins_url( $path ), array(), '1.2.2', 1 ); $scripts->enqueue( $handle );
}
$preserved_resources = array( array( 'href' => 'https://shop.example/hero.webp', 'as' => 'image' ) );
$consent_resources = $performance->preload_consent_scripts( $preserved_resources );
verify_image( 3 === count( $consent_resources ) && $preserved_resources[0] === $consent_resources[0], 'Consent preloads preserve other resources and add only two native scripts.' );
verify_image( plugins_url( 'cookieadmin/assets/js/consent.js' ) . '?ver=1.2.2' === $consent_resources[2]['href'] && 'script' === $consent_resources[2]['as'], 'Preload URL must exactly match the executed script and version.' );
$scripts->registered['cookieadmin_js']->src = 'https://cdn.example/consent.js';
verify_image( 1 === count( $performance->preload_consent_scripts( array() ) ), 'A replaced vendor source must not fetch an unused native file.' );
$scripts->dequeue( 'cookieadmin_pro_js' );
verify_image( array() === $performance->preload_consent_scripts( array() ), 'Inactive consent scripts must not preload.' );
$GLOBALS['front_page_test'] = false;
verify_image( $preserved_resources === $performance->preload_consent_scripts( $preserved_resources ), 'Non-catalog pages keep native resource selection.' );
$GLOBALS['front_page_test'] = true;
add_filter( 'schrack_wc_sync_preload_consent_scripts', '__return_false' );
verify_image( $preserved_resources === $performance->preload_consent_scripts( $preserved_resources ), 'Rollback filter preserves native resources.' );
$GLOBALS['front_page_test'] = false;
// The toolbar shell can be styled without downloading its full CSS.
$onetap->setValue( $performance, true );
$panel_css_url = plugins_url( 'accessibility-onetap/assets/css/accessibility-onetap-front-end.min.css' ) . '?ver=2.14.0';
$panel_css_tag = '<link rel="stylesheet" id="accessibility-onetap-css" nonce="style-nonce" href="' . $panel_css_url . '" media="screen">';
$panel_delayed = $performance->delay_onetap_panel_style( $panel_css_tag, 'accessibility-onetap', $panel_css_url, 'screen' );
$panel_tag = new WP_HTML_Tag_Processor( $panel_delayed ); $panel_tag->next_tag( 'LINK' );
verify_image( null === $panel_tag->get_attribute( 'href' ) && $panel_css_url === $panel_tag->get_attribute( 'data-schrack-onetap-style-src' ), 'Full toolbar CSS must be absent from active href until activation.' );
verify_image( 'style-nonce' === $panel_tag->get_attribute( 'nonce' ) && 'screen' === $panel_tag->get_attribute( 'media' ), 'CSS CSP nonce and original media must survive.' );
verify_image( str_contains( $panel_delayed, 'schrack-onetap-bootstrap-css' ) && str_contains( $panel_delayed, 'data-schrack-onetap-css-ready' ), 'Closed native panel must remain hidden while the toolbar shell is styled.' );
verify_image( $panel_delayed === $performance->inline_vendor_asset_style( $panel_delayed, 'accessibility-onetap', $panel_css_url ), 'Later inlining must not restore the deferred full stylesheet.' );
verify_image( $panel_css_tag === $performance->delay_onetap_panel_style( $panel_css_tag, 'other', $panel_css_url ), 'Unrelated handles keep native CSS loading.' );
verify_image( $panel_css_tag === $performance->delay_onetap_panel_style( $panel_css_tag, 'accessibility-onetap', 'https://cdn.example/onetap.css' ), 'Custom/CDN sources must keep native CSS loading.' );
add_filter( 'schrack_wc_sync_onetap_styles_on_demand', '__return_false' );
verify_image( $panel_css_tag === $performance->delay_onetap_panel_style( $panel_css_tag, 'accessibility-onetap', $panel_css_url ), 'Toolbar CSS rollback restores the complete native stylesheet.' );
remove_filter( 'schrack_wc_sync_onetap_styles_on_demand', '__return_false' );
$onetap->setValue( $performance, false );
verify_image( $panel_css_tag === $performance->delay_onetap_panel_style( $panel_css_tag, 'accessibility-onetap', $panel_css_url ), 'Without the inspected on-demand loader CSS is untouched.' );
echo "Frontend performance total: {$checks} checks passed.\n";
