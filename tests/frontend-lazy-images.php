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
define( 'SCHRACK_WC_SYNC_PATH', dirname( __DIR__ ) . '/' );
foreach ( array( 'compat.php', 'load.php', 'plugin.php', 'functions.php', 'formatting.php', 'kses.php', 'shortcodes.php', 'media.php' ) as $file ) {
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
$noscript_open = '<noscript class="schrack-image-fallback">';
verify_image( 1 === substr_count( $result, $noscript_open ), 'Original URL must stay inside a noscript fallback.' );
$fallback = substr( $result, strpos( $result, $noscript_open ) + strlen( $noscript_open ), -11 );
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
$noscript_style = ob_get_clean();
verify_image( str_contains( $noscript_style, 'img[data-schrack-image-src]{display:none!important}' ), 'No-JS fallback must not show a second placeholder.' );
verify_image( str_contains( $noscript_style, 'noscript.schrack-image-fallback{display:contents!important}' ), 'No-JS images must participate in the card flex/grid layout without a collapsing wrapper.' );

// Exercise the remote-image attributes and the card helper together. No WooCommerce
// bootstrap, image import queue or database is needed for these pure markup paths.
class WC_Product {
	public string $image_url = '';
	public int $image_id = 0;
	public function get_name(): string { return 'KARO II LED'; }
	public function get_image_id(): int { return $this->image_id; }
	public function get_meta( string $key, bool $single = true ): string { return '_schrack_image_url' === $key ? $this->image_url : ''; }
}
function get_queried_object_id(): int { return 123; }
function wc_get_product( int $id ): mixed { return $GLOBALS['preload_test_product'] ?? false; }
function get_post_type( int $id ): string { return 'attachment'; }
function wp_attachment_is_image( int $id ): bool { return true; }
$remote_attributes = new ReflectionMethod( $loader, 'remote_image_attributes' );
$serialize = new ReflectionMethod( $loader, 'image_attributes_html' );
$product = new WC_Product();
$supplier_url = 'https://image.schrack.com/foto/f_liim0030-a.jpg';
$cdn_url = 'https://image.schrackcdn.com/260x145/f_liim0030-a.jpg';
$attributes = $remote_attributes->invoke( $loader, $product, 'woocommerce_thumbnail', array(), $supplier_url );
$card = Schrack_Frontend_Image_Loader::lazy_image_html( '<img ' . $serialize->invoke( $loader, $attributes ) . '>' );
$card_tag = new WP_HTML_Tag_Processor( $card );
$card_tag->next_tag( 'IMG' );
verify_image( $cdn_url === $card_tag->get_attribute( 'data-schrack-image-src' ), 'Remote cards must defer the official thumbnail.' );
verify_image( $supplier_url === $card_tag->get_attribute( 'data-schrack-image-fallback' ), 'The original must be available on CDN failure.' );
verify_image( null === $card_tag->get_attribute( 'data-schrack-image-thumbnail' ), 'The opt-in marker must be consumed by the card helper.' );
$card_fallback = new WP_HTML_Tag_Processor( substr( $card, strpos( $card, $noscript_open ) + strlen( $noscript_open ), -11 ) );
$card_fallback->next_tag( 'IMG' );
verify_image( $supplier_url === $card_fallback->get_attribute( 'src' ) && null === $card_fallback->get_attribute( 'data-schrack-image-fallback' ), 'No-JS cards must use the original, not an unchecked CDN URL.' );
verify_image( $supplier_url === $attributes['src'], 'Renderers without our loader must retain their existing source.' );
foreach ( array( 'full', 'custom-large', array( 800, 800 ) ) as $size ) {
	$large = $remote_attributes->invoke( $loader, $product, $size, array(), $supplier_url );
	verify_image( ! isset( $large['data-schrack-image-thumbnail'] ) && $supplier_url === $large['src'], 'Full images and unknown sizes must not be downsized.' );
}
$main = $remote_attributes->invoke( $loader, $product, 'woocommerce_single', array(), $supplier_url );
$GLOBALS['preload_test_product'] = $product;
$product->image_url = $supplier_url;
ob_start();
$loader->preload_current_product_image();
$preload_html = ob_get_clean();
$preload = new WP_HTML_Tag_Processor( $preload_html );
$preload->next_tag( 'LINK' );
verify_image( $main['srcset'] === $preload->get_attribute( 'imagesrcset' ) && $main['sizes'] === $preload->get_attribute( 'imagesizes' ), 'Head preload must select exactly the same responsive image as the gallery.' );
verify_image( 'preload' === $preload->get_attribute( 'rel' ) && 'image' === $preload->get_attribute( 'as' ) && 'high' === $preload->get_attribute( 'fetchpriority' ), 'Hero preload must have image type and high priority.' );
verify_image( null === $preload->get_attribute( 'href' ), 'Browsers without responsive preload support must not download an extra small image.' );
foreach ( array( 'local', 'missing', 'invalid-product' ) as $case ) {
	$product->image_id = 'local' === $case ? 99 : 0;
	$product->image_url = 'missing' === $case ? '' : $supplier_url;
	$GLOBALS['preload_test_product'] = 'invalid-product' === $case ? false : $product;
	ob_start();
	$loader->preload_current_product_image();
	verify_image( '' === ob_get_clean(), 'Local/missing images and invalid products must not preload a supplier fallback.' );
}
$product->image_id = 0;
$product->image_url = 'https://supplier.example/photo.jpg?a=1&b=2';
$GLOBALS['preload_test_product'] = $product;
ob_start();
$loader->preload_current_product_image();
$plain_preload = new WP_HTML_Tag_Processor( ob_get_clean() );
$plain_preload->next_tag( 'LINK' );
verify_image( $product->image_url === $plain_preload->get_attribute( 'href' ) && null === $plain_preload->get_attribute( 'imagesrcset' ), 'Other supplier images must retain the escaped original URL without invented variants.' );
verify_image( 'https://image.schrackcdn.com/340x380/f_liim0030-a.jpg' === $main['src'], 'Main image must use the verified gallery preview.' );
verify_image( str_contains( $main['srcset'], '/1190x1330/f_liim0030-a.jpg 1190w' ), 'Retina main images need the verified larger candidate.' );
verify_image( 'eager' === $main['loading'] && 'high' === $main['fetchpriority'] && '1' === $main['data-no-lazy'], 'LCP must be immediately discoverable, with no second lazy loader.' );
verify_image( 340 === $main['width'] && 380 === $main['height'] && $supplier_url === $main['data-schrack-image-fallback'], 'Gallery dimensions and one-time original fallback must match the CDN preset.' );
$eager = $remote_attributes->invoke( $loader, $product, 'woocommerce_thumbnail', array( 'loading' => 'eager' ), $supplier_url );
verify_image( ! str_contains( Schrack_Frontend_Image_Loader::lazy_image_html( '<img ' . $serialize->invoke( $loader, $eager ) . '>' ), 'data-schrack-image-src=' ), 'High-priority images must keep their eager behaviour.' );
foreach ( array(
	'https://telesystem.example/foto/f_liim0030-a.jpg',
	'https://image.schrack.com.evil.example/foto/f_liim0030-a.jpg',
	'https://image.schrack.com@evil.example/foto/f_liim0030-a.jpg',
	'https://image.schrack.com/foto/f_liim0030-a.jpg?signature=abc',
	'https://image.schrack.com/foto/f_liim0030-a.jpg#fragment',
	'https://image.schrack.com/foto/nested/f_liim0030-a.jpg',
	'https://image.schrack.com/foto/f_liim0030-a.png',
	'https://image.schrack.com/foto/f_..%2Fsecret.jpg',
	'https://image.schrackcdn.com/260x145/f_liim0030-a.jpg',
	'/uploads/f_liim0030-a.jpg',
) as $unsupported ) {
	$unchanged = $remote_attributes->invoke( $loader, $product, 'woocommerce_thumbnail', array(), $unsupported );
	verify_image( ! isset( $unchanged['data-schrack-image-thumbnail'] ) && $unsupported === $unchanged['src'], 'Only recognised supplier originals may be mapped: ' . $unsupported );
	$main_unchanged = $remote_attributes->invoke( $loader, $product, 'woocommerce_single', array(), $unsupported );
	verify_image( $unsupported === $main_unchanged['src'] && ! isset( $main_unchanged['srcset'] ), 'Main image mapping must reject unsupported URLs too.' );
}
$cdn_original = $remote_attributes->invoke( $loader, $product, 'woocommerce_thumbnail', array(), 'https://image.schrackcdn.com/foto/f_liim0030-a.jpg' );
verify_image( $cdn_url === $cdn_original['data-schrack-image-thumbnail'], 'The supplier CDN original host is also supported.' );
$forged = str_replace( $cdn_url, 'https://other.example/image.jpg', '<img ' . $serialize->invoke( $loader, $attributes ) . '>' );
verify_image( ! str_contains( Schrack_Frontend_Image_Loader::lazy_image_html( $forged ), 'https://other.example' ), 'An unrelated thumbnail marker must never override the original.' );

$banner = Schrack_Frontend_Image_Loader::category_image_attributes( '/plugin/assets/home-category-banners/benzi-led-si-accesorii-2.webp' );
verify_image( str_contains( $banner, '-480.webp"' ) && str_contains( $banner, ' 720w' ), 'Bundled banners must advertise existing responsive files.' );
verify_image( 'sync' === Schrack_Frontend_Image_Loader::category_image_decoding( '/plugin/assets/home-category-banners/corpuri-de-iluminat-pentru-interior-2.webp' ), 'All existing small first-category WebP candidates support atomic first paint.' );
add_filter( 'schrack_wc_sync_sync_category_hero', '__return_false' );
verify_image( 'async' === Schrack_Frontend_Image_Loader::category_image_decoding( '/plugin/assets/home-category-banners/corpuri-de-iluminat-pentru-interior-2.webp' ), 'Rollback restores the native asynchronous image hint.' );
remove_filter( 'schrack_wc_sync_sync_category_hero', '__return_false' );
$deferred_banner = Schrack_Frontend_Image_Loader::lazy_image_html( '<img ' . $banner . ' loading="lazy">' );
verify_image( str_contains( $deferred_banner, 'data-schrack-image-srcset=' ), 'Lazy banner srcset must be deferred along with src.' );
foreach ( array( 'https://custom.example/photo.webp', '/plugin/assets/home-category-banners/../../private.webp', '/plugin/assets/home-category-banners/missing.webp' ) as $custom ) {
	verify_image( 'src="' . esc_url( $custom ) . '"' === Schrack_Frontend_Image_Loader::category_image_attributes( $custom ), 'Custom/missing banners must preserve their source.' );
	verify_image( 'async' === Schrack_Frontend_Image_Loader::category_image_decoding( $custom ), 'Remote, missing and traversal paths cannot request synchronous decoding.' );
}

echo "Frontend image markup: {$checks} checks passed.\n";

// Cache boundary fixture: source-only attribute integration, no file/HTTP access.

if ( ! class_exists( 'Schrack_Product_Hero_Cache' ) ) {
	class Schrack_Product_Hero_Cache {
		public static function attributes( WC_Product $product, string $url ): array {
			return array( 'src' => 'https://shop.example/uploads/hero.webp', 'srcset' => 'https://shop.example/uploads/hero.webp 340w', 'decoding' => 'sync' );
		}
	}
}
$cached = $remote_attributes->invoke( $loader, $product, 'woocommerce_single', array(), $supplier_url );
verify_image( 'sync' === $cached['decoding'] && 'eager' === $cached['loading'] && 'high' === $cached['fetchpriority'], 'The small hero hint is combined with existing eager/high priority loading.' );
$explicit = $remote_attributes->invoke( $loader, $product, 'woocommerce_single', array( 'decoding' => 'async' ), $supplier_url );
verify_image( 'async' === $explicit['decoding'], 'Caller-specified decoding must override the cache hint.' );
$cached_card = $remote_attributes->invoke( $loader, $product, 'woocommerce_thumbnail', array(), $supplier_url );
verify_image( 'async' === $cached_card['decoding'] && 'lazy' === $cached_card['loading'], 'Cards retain asynchronous decoding and lazy loading.' );
echo "Hero decoding boundary: {$checks} checks passed.\n";
