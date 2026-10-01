<?php
/** Native WordPress GD conversion + deterministic queue/HTTP fixtures; no database or network. */
$source = $argv[1] ?? '';
if ( ! is_file( $source . '/wp-includes/media.php' ) ) { throw new RuntimeException( 'Pass an unmodified WordPress source tree.' ); }
define( 'ABSPATH', rtrim( $source, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
require_once ABSPATH . 'wp-includes/compat.php';
require_once ABSPATH . 'wp-includes/plugin.php';
require_once ABSPATH . 'wp-includes/shortcodes.php';
require_once ABSPATH . 'wp-includes/class-wp-error.php';
require_once ABSPATH . 'wp-includes/media.php';
require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
require_once ABSPATH . 'wp-includes/class-wp-image-editor-gd.php';
require_once __DIR__ . '/../includes/class-schrack-product-hero-cache.php';
$root = sys_get_temp_dir() . '/schrack-hero-test-' . bin2hex( random_bytes( 5 ) );
mkdir( $root, 0700 );
$products = $meta = $requests = $jobs = $purges = array();
$count = 0; $response_code = 200; $wrong_dimensions = false; $editor_error = false; $scheduled = false; $allow = true;
function __( $s ) { return $s; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function wp_raise_memory_limit( $s ) { return false; }
function wp_fuzzy_number_match( $expected, $actual, $precision = 1 ) { return abs( (float) $expected - (float) $actual ) <= $precision; }
function wp_cache_get( $key, $group = '' ) { return false; }
function wp_cache_set( $key, $value, $group = '' ) { return true; }
function wp_is_stream( $s ) { return str_contains( $s, '://' ); }
function wp_get_image_mime( $file ) { return getimagesize( $file )['mime'] ?? false; }
function wp_filesize( $file ) { return filesize( $file ); }
function wp_check_filetype( $s ) { return array( 'type' => false ); }
function wp_get_default_extension_for_mime_type( $type ) { return array( 'image/webp' => 'webp', 'image/jpeg' => 'jpg' )[$type] ?? false; }
function wp_get_mime_types() { return array( 'webp' => 'image/webp', 'jpg|jpeg' => 'image/jpeg' ); }
function wp_basename( $s, $suffix = '' ) { return basename( $s, $suffix ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_mkdir_p( $s ) { return is_dir( $s ) || mkdir( $s, 0700, true ); }
function wp_upload_dir( $time = null, $create = false ) { return array( 'basedir' => $GLOBALS['root'], 'baseurl' => 'https://shop.example/uploads', 'error' => false ); }
function get_post_field( $field, $id ) { return $GLOBALS['products'][$id]->password; }
function wc_get_product( $id ) { return $GLOBALS['products'][$id] ?? false; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$id][$key] ); }
function as_has_scheduled_action( $hook, $args, $group ) { return $GLOBALS['scheduled']; }
function as_enqueue_async_action( $hook, $args, $group, $unique ) { $GLOBALS['jobs'][] = compact( 'hook', 'args', 'group', 'unique' ); return count( $GLOBALS['jobs'] ); }
function wp_next_scheduled( $hook, $args ) { return false; }
function wp_schedule_single_event( $time, $hook, $args ) { throw new RuntimeException( 'Unexpected fallback.' ); }
function wp_safe_remote_get( $url, $options ) {
 $GLOBALS['requests'][] = compact( 'url', 'options' );
 $image = imagecreatetruecolor( $GLOBALS['wrong_dimensions'] ? 1 : 1190, $GLOBALS['wrong_dimensions'] ? 1 : 1330 );
 imagefill( $image, 0, 0, imagecolorallocate( $image, 245, 245, 245 ) ); imagejpeg( $image, $options['filename'], 85 );
 return array( 'code' => $GLOBALS['response_code'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
class WC_Product {
 public function __construct( public int $id, public string $status = 'publish', public string $stock = 'instock', public string $visibility = 'visible', public string $password = '', public int $image_id = 0 ) { $GLOBALS['products'][$id] = $this; }
 public function get_id() { return $this->id; }
 public function get_status() { return $this->status; }
 public function get_stock_status() { return $this->stock; }
 public function get_catalog_visibility() { return $this->visibility; }
 public function get_image_id() { return $this->image_id; }
 public function get_meta( $key, $single ) { return $GLOBALS['meta'][$this->id][$key] ?? ( '_schrack_image_url' === $key ? 'https://image.schrack.com/foto/f_liim0030-a.jpg' : '' ); }
}
add_filter( 'wp_image_editors', static fn() => $GLOBALS['editor_error'] ? array() : array( 'WP_Image_Editor_GD' ) );
add_filter( 'schrack_wc_sync_product_hero_cache', static fn() => $GLOBALS['allow'] );
add_action( 'litespeed_purge_post', static function( $id ) { $GLOBALS['purges'][] = $id; } );
function check_hero( $ok, $message ) { ++$GLOBALS['count']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
$cache = new Schrack_Product_Hero_Cache();
$url = 'https://image.schrack.com/foto/f_liim0030-a.jpg';
try {
 foreach ( array( 'https://evil.example/foto/f_test.jpg', 'https://image.schrack.com@evil.example/foto/f_test.jpg', 'https://image.schrack.com/foto/f_test.jpg?a=1', 'https://image.schrack.com/foto/../f_test.jpg' ) as $bad ) { check_hero( '' === $cache::source( $bad ), 'Untrusted sources must never be fetched.' ); }
 $p = new WC_Product( 1 );
 check_hero( array() === $cache::attributes( $p, $url ), 'No cache must keep native CDN fallback.' );
 $cache->queue( $p ); $cache->queue( $p );
 check_hero( 1 === count( $jobs ) && ! $requests, 'HTML path only queues once, never downloads.' );
 check_hero( $jobs[0]['unique'] && 'schrack-product-heroes' === $jobs[0]['group'], 'Separate, deduplicated native action group.' );
 $cache->generate( 1 );
 $attributes = $cache::attributes( $p, $url );
 check_hero( count( $requests ) === 1 && count( $purges ) === 1, 'One JPEG request publishes all variants and purges only its product.' );
 check_hero( str_contains( $attributes['srcset'] ?? '', ' 680w' ) && 340 === $attributes['width'] && 380 === $attributes['height'], 'Responsive intermediate candidate and original aspect ratio.' );
 check_hero( 'sync' === ( $attributes['decoding'] ?? '' ), 'Small genuine WebP heroes request atomic painting with the page.' );
 $sync_rollback = static fn() => false;
 add_filter( 'schrack_wc_sync_sync_product_hero', $sync_rollback );
 check_hero( ! isset( $cache::attributes( $p, $url )['decoding'] ), 'Decoding rollback preserves all cached responsive candidates.' );
 remove_filter( 'schrack_wc_sync_sync_product_hero', $sync_rollback );
 $large_file = $root . '/schrack-frontend-cache/product-heroes/' . $meta[1]['_schrack_product_hero_webp']['key'] . '-1190.webp';
 $small_bytes = file_get_contents( $large_file );
 file_put_contents( $large_file, str_repeat( "\0", 32769 - strlen( $small_bytes ) ), FILE_APPEND ); clearstatcache( true, $large_file );
 check_hero( ! isset( $cache::attributes( $p, $url )['decoding'] ), 'A large retina candidate must retain default async decoding even when its small preview is tiny.' );
 file_put_contents( $large_file, $small_bytes ); clearstatcache( true, $large_file );
 foreach ( array( 340, 680, 1190 ) as $w ) {
  $file = $root . '/schrack-frontend-cache/product-heroes/' . $meta[1]['_schrack_product_hero_webp']['key'] . '-' . $w . '.webp';
  $size = getimagesize( $file ); check_hero( $size[0] === $w && IMAGETYPE_WEBP === $size[2], 'Native GD must produce genuine WebP at every width.' );
 }
 check_hero( ! glob( $root . '/schrack-frontend-cache/product-heroes/input-*' ) && ! glob( $root . '/schrack-frontend-cache/product-heroes/webp-*' ), 'All temporary files are removed.' );
 check_hero( ! isset( $meta[1]['_schrack_product_hero_queued'] ), 'Completed job clears its lease.' );
 $cache->generate( 1 ); check_hero( 1 === count( $requests ), 'Ready images are reused without HTTP.' );
 check_hero( !$cache::attributes( $p, str_replace( '0030', '0031', $url ) ), 'A changed supplier source invalidates cached metadata.' );
 $options = $requests[0]['options'];
 check_hero( $options['stream'] && 15 === $options['timeout'] && 0 === $options['redirection'] && 1048576 === $options['limit_response_size'] && array() === $options['cookies'], 'Bounded unauthenticated HTTP request with no redirects.' );
 $w680 = $root . '/schrack-frontend-cache/product-heroes/' . $meta[1]['_schrack_product_hero_webp']['key'] . '-680.webp';
 unlink( $w680 ); check_hero( !$cache::attributes( $p, $url ), 'Missing files must fall back, never create broken srcsets.' );
 $cache->generate( 1 ); check_hero( (bool) $cache::attributes( $p, $url ), 'Missing cache assets can be regenerated.' );
 foreach ( array( new WC_Product( 2, 'draft' ), new WC_Product( 3, 'publish', 'outofstock' ), new WC_Product( 4, 'publish', 'onbackorder' ), new WC_Product( 5, 'publish', 'instock', 'hidden' ), new WC_Product( 6, 'publish', 'instock', 'visible', 'secret' ), new WC_Product( 7, 'publish', 'instock', 'visible', '', 99 ) ) as $excluded ) {
  $before = count( $jobs ); $cache->queue( $excluded ); $before_http = count( $requests ); $cache->generate( $excluded->id );
  check_hero( $before === count( $jobs ) && $before_http === count( $requests ), 'Draft, unavailable, hidden, protected and local images are excluded.' );
 }
 $response_code = 503; $failed = new WC_Product( 8 ); $cache->generate( 8 ); $before = count( $jobs ); $cache->queue( $failed );
 check_hero( !$cache::attributes( $failed, $url ) && isset( $meta[8]['_schrack_product_hero_failed'] ) && $before === count( $jobs ), 'HTTP error keeps fallback and applies retry cooldown.' );
 $response_code = 200; $wrong_dimensions = true; $cache->generate( 9 ); // Missing product must never fetch.
 $invalid = new WC_Product( 9 ); $cache->generate( 9 );
 check_hero( !isset( $meta[9]['_schrack_product_hero_webp'] ), 'Invalid 1x1 preset must never replace a product image.' );
 $wrong_dimensions = false; $allow = false; $rollback = new WC_Product( 10 ); $before = count( $jobs ); $cache->queue( $rollback );
 check_hero( $before === count( $jobs ) && !$cache::attributes( $p, $url ), 'Rollback filter restores native image path and stops scheduling.' );
 $allow = true; $editor_error = true; $codec = new WC_Product( 12 ); $cache->generate( 12 );
 check_hero( !isset( $meta[12]['_schrack_product_hero_webp'] ) && isset( $meta[12]['_schrack_product_hero_failed'] ), 'Unavailable native image codec keeps fallback and records cooldown.' );
 $editor_error = false; $scheduled = true; $duplicate = new WC_Product( 11 ); $cache->queue( $duplicate ); check_hero( $before === count( $jobs ), 'An existing pending/running action suppresses duplicates.' );
 echo "Product hero cache: {$count} checks passed using native WordPress GD.\n";
} finally {
 foreach ( glob( $root . '/schrack-frontend-cache/product-heroes/*' ) ?: array() as $file ) { unlink( $file ); }
 if ( is_dir( $root . '/schrack-frontend-cache/product-heroes' ) ) { rmdir( $root . '/schrack-frontend-cache/product-heroes' ); rmdir( $root . '/schrack-frontend-cache' ); }
 rmdir( $root );
}
