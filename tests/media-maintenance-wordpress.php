<?php
/**
 * DISPOSABLE WordPress/WooCommerce test only, with no supplier synchronization.
 * home AND siteurl must be http://127.0.0.1:18944; WP-Cron must be disabled.
 * Load the media-maintenance, memory-guard and product-hero-cache classes/hooks.
 * Run: wp eval-file wp-content/plugins/schrack-woocommerce-sync/tests/media-maintenance-wordpress.php exercise
 * seed leaves fixtures for checking the real media grid, list and product picker.
 * The test creates products, attachments and private reports. Never run on production.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( 'http://127.0.0.1:18944' !== get_option( 'home' ) || 'http://127.0.0.1:18944' !== get_option( 'siteurl' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON || ! class_exists( 'WooCommerce' ) ) { throw new RuntimeException( 'Disposable local media test only.' ); }
if ( is_plugin_active( 'schrack-woocommerce-sync/schrack-woocommerce-sync.php' ) ) { throw new RuntimeException( 'Use the isolated media test bootstrap; supplier integration must be inactive.' ); }
$settings = get_option( 'schrack_wc_sync_settings', array() );
foreach ( $settings as $key => $value ) {
 if ( $value && preg_match( '/password|secret|api_key|soap_user|username/i', $key ) ) { throw new RuntimeException( 'No supplier credentials allowed.' ); }
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
foreach ( array( 'settings', 'logger', 'category-markup', 'product-mapper' ) as $part ) { require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-' . $part . '.php'; }
wp_set_current_user( get_user_by( 'login', 'media_admin' )->ID );
$GLOBALS['schrack_media_test_checks'] = 0;
function media_expect( bool $condition, string $message ): void {
 ++$GLOBALS['schrack_media_test_checks'];
 if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function media_jpeg( string $file, int $width, int $height, int $color = 0 ): void {
 $image = imagecreatetruecolor( $width, $height );
 imagefill( $image, 0, 0, $color );
 // Make the giant fixture substantial enough to measure transferred bytes.
 for ( $y = 0; $y < $height; $y += 17 ) { imageline( $image, 0, $y, $width - 1, $y, 0xffffff ); }
 imagejpeg( $image, $file, 88 ); imagedestroy( $image );
}
function media_attach( string $path, string $title, string $source = '' ): int {
 $id = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $path );
 media_expect( $id > 0, 'Fixture attachment' );
 if ( $source ) { update_post_meta( $id, '_schrack_image_source_url', $source ); }
 return $id;
}
function media_drive( Schrack_Media_Maintenance $job ): array {
 for ( $i = 0; $i < 100; ++$i ) {
  $state = $job::status();
  if ( 'running' !== $state['status'] ) { break; }
  $job->work( $state['id'] );
 }
 $state = $job::status();
 media_expect( 'complete' === $state['status'], 'Job completed: ' . wp_json_encode( $state ) );
 return $state;
}
function media_rows( Schrack_Media_Maintenance $job ): array {
 $rows = array(); $cursor = 0;
 do {
  $page = $job->view( $cursor );
  foreach ( $page['rows'] as $row ) { $rows[ $row['id'] ] = $row; }
  $cursor = $page['next'];
 } while ( $cursor );
 return $rows;
}
// Remove only attachments and products created by previous runs of THIS test.
$old = get_option( 'schrack_media_test_fixture', array() );
foreach ( $old['ids'] ?? array() as $id ) { wp_delete_attachment( $id, true ); }
foreach ( $old['posts'] ?? array() as $id ) { wp_delete_post( $id, true ); }
Schrack_Media_Maintenance::clear_schedule(); delete_option( Schrack_Media_Maintenance::STATE );
$uploads = wp_upload_dir();
$directory = $uploads['basedir'] . '/media-test'; wp_mkdir_p( $directory );
media_jpeg( $directory . '/normal.jpg', 1200, 1400, 0x367b91 );
media_jpeg( $directory . '/giant.jpg', 5000, 5000, 0x507540 );
media_jpeg( $directory . '/cdn.jpg', 1190, 1330, 0x507540 );
file_put_contents( $directory . '/corrupt.jpg', 'This is not an image.' );
copy( $directory . '/normal.jpg', $directory . '/copy.jpg' );
$normal = media_attach( $directory . '/normal.jpg', 'Media test — metadata missing', 'https://image.schrack.com/foto/f_shared.jpg' );
$copy = media_attach( $directory . '/copy.jpg', 'Media test — exact copy', 'https://image.schrack.com/foto/f_other.jpg' );
$giant = media_attach( $directory . '/giant.jpg', 'Media test — giant trusted', 'https://image.schrack.com/foto/f_shared.jpg' );
copy( $directory . '/giant.jpg', $directory . '/unknown.jpg' );
$unknown = media_attach( $directory . '/unknown.jpg', 'Media test — giant unknown' );
copy( $directory . '/giant.jpg', $directory . '/failure.jpg' );
$failure = media_attach( $directory . '/failure.jpg', 'Media test — CDN failure', 'https://image.schrack.com/foto/f_failure.jpg' );
$corrupt = media_attach( $directory . '/corrupt.jpg', 'Media test — corrupt' );
$missing = media_attach( $directory . '/missing.jpg', 'Media test — absent original' );
$ids = array( $normal, $copy, $giant, $unknown, $failure, $corrupt, $missing );
$product = wp_insert_post( array( 'post_title' => 'Media test product', 'post_type' => 'product', 'post_status' => 'publish' ) );
update_post_meta( $product, '_thumbnail_id', $normal );
update_post_meta( $product, '_product_image_gallery', "$copy,$giant" );
$content = wp_insert_post( array( 'post_title' => 'Media test content', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<img class="wp-image-' . $normal . '" src="' . $uploads['baseurl'] . '/media-test/normal-300x350.jpg">' ) );
$elementor = wp_insert_post( array( 'post_title' => 'Media test Elementor', 'post_type' => 'page', 'post_status' => 'publish' ) );
update_post_meta( $elementor, '_elementor_data', wp_slash( wp_json_encode( array( array( 'settings' => array( 'image' => array( 'id' => (string) $normal, 'url' => $uploads['baseurl'] . '/media-test/normal.jpg' ) ) ) ) ) ) );
$term = wp_insert_term( 'Media test category ' . $normal, 'product_cat' );
update_term_meta( $term['term_id'], 'thumbnail_id', $normal );
$fixture = array( 'ids' => $ids, 'posts' => array( $product, $content, $elementor ), 'normal' => $normal, 'giant' => $giant, 'unknown' => $unknown, 'failure' => $failure, 'product' => $product, 'cdn' => $directory . '/cdn.jpg' );
update_option( 'schrack_media_test_fixture', $fixture, false );
if ( 'seed' === ( $args[0] ?? '' ) ) { echo 'UI fixtures: ' . wp_json_encode( $fixture ) . "\n"; return; }
$http = static function( $pre, $request, $url ) use ( $directory ) {
 if ( ! empty( $request['filename'] ) ) { copy( $directory . '/cdn.jpg', $request['filename'] ); }
 return str_contains( $url, 'failure' ) ? new WP_Error( 'test_failure', 'CDN failure' ) : array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '', 'cookies' => array() );
};
add_filter( 'pre_http_request', $http, 100, 3 );
$job = new Schrack_Media_Maintenance();
$before = array();
foreach ( $ids as $id ) {
 $path = get_attached_file( $id );
 $before[ $id ] = array( 'file' => get_post_meta( $id, '_wp_attached_file', true ), 'meta' => get_post_meta( $id, '_wp_attachment_metadata', false ), 'hash' => is_file( $path ) ? hash_file( 'sha256', $path ) : '' );
}
$job->transition( 'scan' ); $initial = $job::status();
media_expect( wp_next_scheduled( $job::HOOK, array( $initial['id'] ) ) > 0, 'Cron watchdog queued' );
$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock_name = 'schrack_media_' . md5( $GLOBALS['wpdb']->options );
$connection->get_var( $connection->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
$job->work( $initial['id'] ); media_expect( $initial === $job::status(), 'Only one media worker may advance the cursor' );
$job->transition( 'pause', $initial['id'] );
media_expect( $job->view()['state']['pause_requested'], 'Pause is accepted while another worker holds the lock' );
$connection->get_var( $connection->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); $connection->close();
wp_cache_set( 'notoptions', array( 'schrack_media_maintenance_pause' => true ), 'options' );
$job->work( $initial['id'] );
media_expect( 'paused' === $job::status()['status'], 'Queued pause stops at the next checkpoint' );
$paused = $job::status(); $job->work( $initial['id'] );
media_expect( $paused === $job::status(), 'Paused job cannot advance' );
$job->transition( 'resume', $initial['id'] ); $scan = media_drive( $job );
$rows = media_rows( $job );
media_expect( 7 <= $scan['scanned'] && ! array_diff( $ids, array_keys( $rows ) ), 'Every fixture scanned (including WooCommerce placeholder)' );
media_expect( in_array( $copy, $rows[ $normal ]['file_hash_matches'], true ), 'Identical content detected across different sources' );
media_expect( ! in_array( $copy, $rows[ $normal ]['source_hash_matches'], true ), 'Different sources kept distinct' );
media_expect( in_array( $giant, $rows[ $normal ]['source_hash_matches'], true ), 'Shared source detected with different file contents' );
media_expect( ! in_array( $giant, $rows[ $normal ]['file_hash_matches'], true ), 'Shared source does not imply identical file' );
$contexts = array_column( $rows[ $normal ]['references']['known'], 'context' );
foreach ( array( 'featured/gallery/import', 'content (candidate)', 'Elementor (candidate)', 'category' ) as $context ) { media_expect( in_array( $context, $contexts, true ), 'Reference: ' . $context ); }
media_expect( $rows[ $unknown ]['references']['unknown'], 'Unknown usage remains explicit' );
media_expect( 'unavailable' === $rows[ $corrupt ]['repair'] && 'unavailable' === $rows[ $missing ]['repair'], 'Invalid/missing originals cannot be repaired' );
media_expect( ! get_post_meta( $normal, $job::PREVIEWS, true ), 'Scan is read-only for attachment metadata' );
media_expect( ! empty( $job->previews( $giant )['small']['placeholder'] ), 'Giant gets light placeholder before repair' );
media_expect( ! str_contains( wp_json_encode( $job->view() ), '/var/www/schrack-media-' ), 'Private filesystem paths absent from response' );
try { $job->transition( 'repair', 'stale' ); media_expect( false, 'Stale job must fail' ); } catch ( RuntimeException $error ) { media_expect( true, 'Stale job rejected' ); }
$job->transition( 'repair', $scan['id'] );
$pending = as_get_scheduled_actions( array( 'hook' => $job::HOOK, 'status' => 'pending' ), 'ids' );
// Reproduce the real scheduler: the currently executing action is no longer pending.
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->prefix . 'actionscheduler_actions', array( 'status' => 'in-progress' ), array( 'action_id' => $pending[0] ) );
$job->work( $scan['id'] );
media_expect( 1 === count( as_get_scheduled_actions( array( 'hook' => $job::HOOK, 'status' => 'pending' ), 'ids' ) ), 'In-progress scheduler action can enqueue its successor' );
ActionScheduler::store()->mark_complete( $pending[0] );
media_expect( 1 === $job::status()['repaired'], 'At most one repair per action' );
$published = get_post_meta( $normal, $job::PREVIEWS, true );
$lost_checkpoint = $job::status(); $lost_checkpoint['cursor'] = $normal - 1; $lost_checkpoint['repaired'] = 0;
update_option( $job::STATE, $lost_checkpoint, false );
$job = new Schrack_Media_Maintenance(); $job->work( $scan['id'] );
media_expect( $published === get_post_meta( $normal, $job::PREVIEWS, true ) && 1 === $job::status()['repaired'], 'Published previews survive a lost cursor checkpoint without repeated conversion' );
$private = dirname( rtrim( ABSPATH, '/' ) ) . '/schrack-media-' . $scan['id'];
$backup = json_decode( file_get_contents( "$private/backup-$normal.json" ), true );
media_expect( $before[ $normal ]['meta'] === $backup['attachment_metadata'], 'Pre-repair metadata saved privately' );
media_expect( 0600 === ( fileperms( "$private/backup-$normal.json" ) & 0777 ), 'Private backup permissions' );
$job->transition( 'pause', $scan['id'] ); $paused = $job::status(); $job->work( $scan['id'] );
media_expect( $job::status() === $paused, 'Repair pause retains checkpoint' );
$job->transition( 'resume', $scan['id'] );
$repair = media_drive( $job ); $rows = media_rows( $job );
media_expect( 3 === $repair['repaired'] && 2 === $repair['skipped'], 'Normal, exact copy and trusted giant repaired; unknown and CDN failure skipped' );
media_expect( 'skipped' === $rows[ $unknown ]['repair'] && str_contains( $rows[ $unknown ]['repair_message'], 'Schrack' ), 'Untrusted giant skipped visibly' );
media_expect( 'skipped' === $rows[ $failure ]['repair'] && str_contains( $rows[ $failure ]['repair_message'], 'CDN' ), 'CDN failure skipped visibly' );
foreach ( array( $normal, $copy, $giant ) as $id ) {
 $preview = $job->previews( $id );
 media_expect( max( $preview['small']['width'], $preview['small']['height'] ) <= 300, 'Small preview bounded' );
 media_expect( 1024 === max( $preview['detail']['width'], $preview['detail']['height'] ), 'Detail bounded to 1024' );
 media_expect( empty( $preview['small']['placeholder'] ), 'Repair publishes valid previews' );
}
foreach ( $ids as $id ) {
 media_expect( $before[ $id ]['file'] === get_post_meta( $id, '_wp_attached_file', true ) && $before[ $id ]['meta'] === get_post_meta( $id, '_wp_attachment_metadata', false ), 'Original attachment metadata preserved' );
 $path = get_attached_file( $id );
 media_expect( $before[ $id ]['hash'] === ( is_file( $path ) ? hash_file( 'sha256', $path ) : '' ), 'Original bytes preserved' );
}
media_expect( (int) get_post_meta( $product, '_thumbnail_id', true ) === $normal, 'Product relation preserved' );
media_expect( ! wp_next_scheduled( $job::HOOK, array( $scan['id'] ) ), 'Completed job clears watchdog including its arguments' );
media_expect( ! as_get_scheduled_actions( array( 'hook' => $job::HOOK, 'status' => 'pending' ), 'ids' ), 'Completed job clears pending actions' );
$decode = new ReflectionMethod( $job, 'can_decode' );
media_expect( ! $decode->invoke( $job, array( 5000, 5000 ) ), 'Giant is never decoded locally' );
$old_limit = ini_get( 'memory_limit' ); ini_set( 'memory_limit', '256M' );
media_expect( ! $decode->invoke( $job, array( 4096, 4096 ) ), 'Memory budget rejects expensive decode' );
ini_set( 'memory_limit', $old_limit );
$existing_preview = get_post_meta( $giant, $job::PREVIEWS, true );
$low_limit = (int) ceil( memory_get_usage( true ) / 1048576 ) + 8;
ini_set( 'memory_limit', $low_limit . 'M' );
try { $job->repair( $giant, $private ); media_expect( false, 'Memory shortage must skip repair' ); }
catch ( RuntimeException $error ) { media_expect( str_contains( $error->getMessage(), 'Memorie' ), 'Memory shortage produces visible error' ); }
finally { ini_set( 'memory_limit', $old_limit ); }
media_expect( $existing_preview === get_post_meta( $giant, $job::PREVIEWS, true ), 'Failed conversion preserves prior valid preview metadata' );
// Crash after one image: a new service instance resumes the saved checkpoint.
$job->transition( 'scan' ); $new = $job::status();
$broken = static function( $query ) { if ( str_contains( $query, 'SELECT DISTINCT source.meta_value' ) ) { throw new RuntimeException( 'Injected scan interruption' ); } return $query; };
add_filter( 'query', $broken ); $job->work( $new['id'] ); remove_filter( 'query', $broken );
media_expect( 'error' === $job::status()['status'], 'Interruption becomes visible error' );
$job = new Schrack_Media_Maintenance(); $job->transition( 'resume', $new['id'] ); media_drive( $job );
$stale = $job::status(); $job->work( $scan['id'] ); media_expect( $job::status() === $stale, 'Old queued worker cannot change new job' );
// Two real database connections overlap at the download critical section.
update_option( Schrack_Settings::OPTION_NAME, array( 'image_import_enabled' => 'yes', 'log_level' => 'error', 'debug_enabled' => 'no' ), false );
$settings = new Schrack_Settings(); $logger = new Schrack_Logger( $settings ); Schrack_Logger::create_table();
$mapper = new Schrack_Product_Mapper( $settings, $logger );
$second_mapper = new Schrack_Product_Mapper( $settings, $logger );
$url = 'https://image.schrack.com/foto/f_concurrent.jpg';
$lookup = new ReflectionMethod( $second_mapper, 'find_existing_image_attachment' );
media_expect( 0 === $lookup->invoke( $second_mapper, $url ), 'A waiting worker may hold a stale negative source cache' );
$p1 = wp_insert_post( array( 'post_title' => 'Concurrent one', 'post_type' => 'product', 'post_status' => 'publish' ) );
$p2 = wp_insert_post( array( 'post_title' => 'Concurrent two', 'post_type' => 'product', 'post_status' => 'publish' ) );
foreach ( array( $p1, $p2 ) as $id ) { update_post_meta( $id, '_schrack_image_url', $url ); }
$db1 = $GLOBALS['wpdb']; $db2 = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $db2->set_prefix( $db1->prefix );
$overlap = null; $downloads = 0;
$concurrent = static function( $pre, $request, $remote ) use ( $url, $db1, $db2, $p2, $second_mapper, &$overlap, &$downloads, $directory ) {
 if ( $remote !== $url ) { return $pre; }
 ++$downloads;
 $GLOBALS['wpdb'] = $db2;
 try { $overlap = $second_mapper->import_product_image_with_result( $p2 ); }
 finally { $GLOBALS['wpdb'] = $db1; }
 copy( $directory . '/cdn.jpg', $request['filename'] );
 return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '', 'cookies' => array() );
};
add_filter( 'pre_http_request', $concurrent, 200, 3 );
$first = $mapper->import_product_image_with_result( $p1 );
remove_filter( 'pre_http_request', $concurrent, 200 );
media_expect( 'imported' === $first['status'], 'First worker imports: ' . wp_json_encode( $first ) );
media_expect( 'deferred_busy' === $overlap['status'], 'Simultaneous same-source worker defers before download' );
$second = $second_mapper->import_product_image_with_result( $p2 );
media_expect( 'reused_existing' === $second['status'] && $second['attachment_id'] === $first['attachment_id'] && 1 === $downloads, 'Deferred worker reuses exactly one attachment' );
media_expect( isset( wp_get_attachment_metadata( $first['attachment_id'] )['sizes']['medium'] ), 'Future import keeps medium' );
$lock = 'schrack_image_' . substr( hash( 'sha256', $db1->posts . ':' . $url ), 0, 48 );
media_expect( '1' === (string) $db2->get_var( $db2->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ), 'Import releases its source lock' );
$db2->get_var( $db2->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
$p3 = wp_insert_post( array( 'post_title' => 'Failed download', 'post_type' => 'product', 'post_status' => 'publish' ) );
$failed_url = 'https://image.schrack.com/foto/f_import_failure.jpg'; update_post_meta( $p3, '_schrack_image_url', $failed_url );
$failed = $mapper->import_product_image_with_result( $p3 );
media_expect( 'failed' === $failed['status'], 'Download failure is visible' );
$failed_lock = 'schrack_image_' . substr( hash( 'sha256', $db1->posts . ':' . $failed_url ), 0, 48 );
media_expect( '1' === (string) $db2->get_var( $db2->prepare( 'SELECT GET_LOCK(%s,0)', $failed_lock ) ), 'Failed download releases its source lock' );
$db2->get_var( $db2->prepare( 'SELECT RELEASE_LOCK(%s)', $failed_lock ) ); $db2->close();
$fixture['ids'][] = $first['attachment_id']; $fixture['posts'][] = $p1; $fixture['posts'][] = $p2;
$fixture['posts'][] = $p3;
update_option( 'schrack_media_test_fixture', $fixture, false );
remove_filter( 'pre_http_request', $http, 100 );
echo 'Media maintenance: ' . $GLOBALS['schrack_media_test_checks'] . " integration checks passed.\n";
// Real WordPress capability and nonce checks, with only the terminating AJAX die replaced.
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
class Media_Test_Ajax_Stop extends RuntimeException {}
$die_handler = static fn() => static function( $message ) { throw new Media_Test_Ajax_Stop( (string) $message ); };
add_filter( 'wp_die_ajax_handler', $die_handler );
$ajax = static function( array $request ) use ( $job ) {
 $_POST = $request; $_REQUEST = $request;
 ob_start();
 try { $job->ajax(); } catch ( Media_Test_Ajax_Stop $stop ) { $message = $stop->getMessage(); }
 $body = ob_get_clean();
 return array( 'json' => json_decode( $body, true ), 'die' => $message ?? '' );
};
$admin_id = get_current_user_id(); wp_set_current_user( 0 );
$denied = $ajax( array( 'operation' => 'status' ) );
media_expect( false === $denied['json']['success'], 'AJAX refuses insufficient capabilities before checking nonce' );
wp_set_current_user( $admin_id );
$before = $job::status();
$invalid = $ajax( array( 'operation' => 'scan', 'nonce' => 'invalid' ) );
media_expect( '-1' === $invalid['die'] && $before === $job::status(), 'Invalid nonce cannot start work' );
$valid = $ajax( array( 'operation' => 'status', 'nonce' => wp_create_nonce( 'schrack_media_maintenance' ) ) );
media_expect( true === $valid['json']['success'] && $valid['json']['data']['state']['id'] === $before['id'], 'Authorized AJAX reads current report' );
remove_filter( 'wp_die_ajax_handler', $die_handler );
if ( ! defined( 'WP_ADMIN' ) ) { define( 'WP_ADMIN', true ); }
$_REQUEST = array( 'action' => 'get-post-thumbnail-html' );
$native = array( wp_get_attachment_url( $giant ), 5000, 5000, false );
$admin = $job->list_image( $native, $giant, array( 266, 266 ), false );
media_expect( $admin[0] !== $native[0] && max( $admin[1], $admin[2] ) <= 300, 'Selected featured image HTML stays bounded' );
media_expect( $native === $job->list_image( $native, $giant, 'full', false ), 'Explicit original full size stays available' );
$_REQUEST = array( 'action' => 'unrelated_frontend_action' );
media_expect( $native === $job->list_image( $native, $giant, 'thumbnail', false ), 'Unrelated AJAX/frontend rendering stays unchanged' );
$prepared = wp_prepare_attachment_for_js( $first['attachment_id'] );
media_expect( $prepared['url'] === wp_get_attachment_url( $first['attachment_id'] ) && $prepared['sizes']['full']['url'] === $prepared['url'], 'Prepared model keeps the actual original/insertion URL' );
echo 'Media AJAX/rendering: 7 checks passed; total ' . $GLOBALS['schrack_media_test_checks'] . ".\n";
