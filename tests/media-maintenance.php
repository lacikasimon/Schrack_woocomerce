<?php
/** Run: php tests/media-maintenance.php — isolated media filter contract, no DB or HTTP. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['media_admin'] = false;
$GLOBALS['media_screen'] = 'upload';
$GLOBALS['media_ajax'] = false;
$GLOBALS['media_lookups'] = array();
$GLOBALS['media_context_calls'] = 0;
function is_admin() { ++$GLOBALS['media_context_calls']; return $GLOBALS['media_admin']; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['media_screen'] ); }
function wp_doing_ajax() { return $GLOBALS['media_ajax']; }
function wp_attachment_is_image( $id ) { $GLOBALS['media_lookups'][] = $id; return 42 === $id; }
require __DIR__ . '/../includes/class-schrack-media-maintenance.php';
$checks = 0;
function media_check( bool $condition, string $label ): void { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( 'FAILED: ' . $label ); } }
$job = new Schrack_Media_Maintenance();
$native = array( 'https://shop.example.invalid/original.jpg', 5000, 5000, false );
foreach ( array( '', 0, '0', -1, '-1', null, false, true, 1.5, '1.5', 'missing', '42px', array(), new stdClass() ) as $id ) {
	foreach ( array( false, $native ) as $image ) {
		media_check( $image === $job->list_image( $image, $id, 'thumbnail', false ), 'invalid attachment ID preserves incoming result without a TypeError: ' . get_debug_type( $id ) );
	}
}
media_check( array() === $GLOBALS['media_lookups'] && 0 === $GLOBALS['media_context_calls'], 'invalid IDs return before screen checks or attachment lookups' );
$cache = new ReflectionProperty( $job, 'preview_cache' );
$cache->setValue( $job, array( 42 => array( 'small' => array( 'url' => 'https://shop.example.invalid/preview.jpg', 'width' => 300, 'height' => 240 ) ) ) );
media_check( $native === $job->list_image( $native, 42, 'thumbnail', false ), 'frontend and email rendering retain native images' );
media_check( false === $job->list_image( false, '', 'woocommerce_thumbnail', false ), 'Woo structured email data may request an empty attachment ID' );
$GLOBALS['media_admin'] = true;
foreach ( array( 'upload', 'product', 'attachment' ) as $screen ) {
	$GLOBALS['media_screen'] = $screen;
	foreach ( array( 42, '42' ) as $id ) {
		media_check( array( 'https://shop.example.invalid/preview.jpg', 300, 240, true ) === $job->list_image( $native, $id, 'thumbnail', false ), 'valid integer/numeric-string ID retains bounded preview on ' . $screen );
	}
}
media_check( $native === $job->list_image( $native, 42, 'full', false ), 'explicit full image remains untouched' );
media_check( $native === $job->list_image( $native, 99, 'thumbnail', false ), 'non-image attachment retains native result' );
$GLOBALS['media_screen'] = 'woocommerce_page_wc-orders';
media_check( $native === $job->list_image( $native, 42, 'thumbnail', false ), 'order screens and their emails are not media previews' );
$GLOBALS['media_ajax'] = true; $_REQUEST['action'] = 'get-post-thumbnail-html';
media_check( 'https://shop.example.invalid/preview.jpg' === $job->list_image( $native, '42', 'thumbnail', false )[0], 'featured-image AJAX preview still works' );
echo 'OK: ' . $checks . " media filter assertions\n";
