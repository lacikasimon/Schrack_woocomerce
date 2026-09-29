<?php
/**
 * Image markup regressions using an unmodified WordPress source tree (no database).
 * Run: php tests/frontend-lazy-images.php /path/to/wordpress
 * Does not load wp-config.php, wp-load.php, plugins or any site credentials.
 */

$wordpress = $argv[1] ?? '';
if ( ! is_file( $wordpress . '/wp-includes/kses.php' ) ) {
	fwrite( STDERR, "Usage: php tests/frontend-lazy-images.php /path/to/wordpress-source\n" );
	exit( 1 );
}

define( 'ABSPATH', rtrim( $wordpress, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'SCHRACK_WC_SYNC_URL', '/plugin/' );
foreach ( array( 'compat.php', 'load.php', 'plugin.php', 'functions.php', 'formatting.php', 'kses.php' ) as $file ) {
	require_once ABSPATH . WPINC . '/' . $file;
}
// Avoid reading the charset option from a database in this source-only harness.
add_filter( 'pre_option_blog_charset', static fn() => 'UTF-8' );
if ( is_file( ABSPATH . WPINC . '/class-wp-token-map.php' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-token-map.php';
}
foreach ( array( 'html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-doctype-info.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $file ) {
	if ( is_file( ABSPATH . WPINC . '/html-api/' . $file ) ) {
		require_once ABSPATH . WPINC . '/html-api/' . $file;
	}
}
require_once __DIR__ . '/../includes/class-schrack-frontend-image-loader.php';

$checks = 0;
function verify_image( bool $condition, string $message ): void {
	++$GLOBALS['checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$original = '<img src="https://image.schrack.com/foto/test.jpg?a=1&amp;b=2" alt="Kép &amp; termék" class="wp-post-image" width="300" height="200" decoding="async" loading="lazy">';
$result = Schrack_Frontend_Image_Loader::lazy_image_html( $original );
$tag = new WP_HTML_Tag_Processor( $result );
$tag->next_tag( 'IMG' );
verify_image( '/plugin/assets/image-placeholder.svg' === $tag->get_attribute( 'src' ), 'Remote URL must be absent from the active src.' );
verify_image( 'https://image.schrack.com/foto/test.jpg?a=1&b=2' === $tag->get_attribute( 'data-schrack-image-src' ), 'Remote URL and query string must survive.' );
verify_image( '300' === $tag->get_attribute( 'width' ) && '200' === $tag->get_attribute( 'height' ), 'Dimensions must remain reserved.' );
verify_image( 'Kép & termék' === $tag->get_attribute( 'alt' ) && 'wp-post-image' === $tag->get_attribute( 'class' ), 'Accessible name and theme class must survive.' );
verify_image( '1' === $tag->get_attribute( 'data-no-lazy' ), 'LiteSpeed must not process the placeholder.' );
verify_image( 1 === substr_count( $result, '<noscript>' ), 'Original URL must stay inside a noscript fallback.' );
$fallback = substr( $result, strpos( $result, '<noscript>' ) + 10, -11 );
$fallback_tag = new WP_HTML_Tag_Processor( $fallback );
$fallback_tag->next_tag( 'IMG' );
verify_image( $fallback_tag->get_attribute( 'src' ) === $tag->get_attribute( 'data-schrack-image-src' ) && null === $fallback_tag->get_attribute( 'data-schrack-image-src' ), 'No-JS fallback must be a usable original image.' );

$local = '<img src="/uploads/product-300.jpg" srcset="/uploads/product-300.jpg 300w, /uploads/product-600.jpg 600w" sizes="(max-width: 600px) 50vw, 300px" loading="lazy">';
$local_result = Schrack_Frontend_Image_Loader::lazy_image_html( $local );
$local_tag = new WP_HTML_Tag_Processor( $local_result );
$local_tag->next_tag( 'IMG' );
verify_image( null === $local_tag->get_attribute( 'srcset' ) && null === $local_tag->get_attribute( 'sizes' ), 'Responsive sources must not trigger an early request.' );
verify_image( '/uploads/product-300.jpg 300w, /uploads/product-600.jpg 600w' === $local_tag->get_attribute( 'data-schrack-image-srcset' ), 'Responsive candidates must survive.' );
verify_image( '(max-width: 600px) 50vw, 300px' === $local_tag->get_attribute( 'data-schrack-image-sizes' ), 'Responsive sizes must survive.' );

foreach ( array(
	str_replace( 'loading="lazy"', 'loading="eager"', $original ),
	str_replace( 'loading="lazy"', 'loading="lazy" fetchpriority="high"', $original ),
	str_replace( 'loading="lazy"', 'loading="lazy" data-no-lazy="1"', $original ),
	str_replace( 'loading="lazy"', 'loading="lazy" data-src="/other-loader.jpg"', $original ),
	'<picture><source srcset="/product.webp">' . $original . '</picture>',
	$original . $original,
	'<span>No image</span>',
) as $untouched ) {
	$preserved = Schrack_Frontend_Image_Loader::lazy_image_html( $untouched );
	verify_image( ! str_contains( $preserved, 'data-schrack-image-src' ), 'Eager, third-party and multi-image markup must keep existing behavior.' );
}
$unsafe = str_replace( 'loading="lazy"', 'loading="lazy" onerror="alert(1)"', $original );
verify_image( ! str_contains( Schrack_Frontend_Image_Loader::lazy_image_html( $unsafe ), 'onerror' ), 'Both deferred and fallback markup must be sanitized.' );

$loader = ( new ReflectionClass( Schrack_Frontend_Image_Loader::class ) )->newInstanceWithoutConstructor();
$script = '<script src="/plugin/assets/frontend-lazy-images.js" defer></script>';
verify_image( str_contains( $loader->lazy_images_script_tag( $script, 'schrack-wc-lazy-images' ), 'data-no-optimize="1" data-no-defer="1"' ), 'Loader must not be delayed by LiteSpeed.' );
verify_image( $script === $loader->lazy_images_script_tag( $script, 'another-script' ), 'Unrelated scripts must be untouched.' );
ob_start();
$loader->lazy_images_noscript_style();
verify_image( str_contains( ob_get_clean(), 'img[data-schrack-image-src]{display:none!important}' ), 'No-JS fallback must not show a second placeholder.' );

echo "Frontend image markup: {$checks} checks passed.\n";
