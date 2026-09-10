<?php
/**
 * DISPOSABLE local WordPress/WooCommerce integration test, never a live database.
 * home/siteurl: http://127.0.0.1:18943; disable supplier sync and WP-Cron.
 * Activate this plugin and WooCommerce, then run through wp eval-file:
 *   tests/attribute-merge-admin-wordpress.php seed
 *   tests/attribute-merge-admin-wordpress.php exercise
 *   tests/attribute-merge-admin-wordpress.php verify
 *   tests/attribute-merge-admin-wordpress.php restore
 *   tests/attribute-merge-admin-wordpress.php access
 * After restore, the same seeded data is available for the real admin UI test.
 * WP-CLI only launches this test; the production job does not call it or a shell.
 */
if ( 'http://127.0.0.1:18943' !== get_option( 'home' ) ) { throw new RuntimeException( 'Disposable local test site only.' ); }
$admin_mode = $args[0] ?? 'exercise';
$fixture_home = static fn () => 'http://merger.test';
add_filter( 'pre_option_home', $fixture_home );
$args = array( in_array( $admin_mode, array( 'seed', 'verify', 'imports' ), true ) ? $admin_mode : ( 'exercise' === $admin_mode ? 'dry' : 'noop' ) );
require __DIR__ . '/attribute-merger-wordpress.php';
remove_filter( 'pre_option_home', $fixture_home );
global $wpdb;

function admin_tables_snapshot(): array {
 global $wpdb;
 $result = array();
 foreach ( array( $wpdb->prefix . 'woocommerce_attribute_taxonomies', $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->prefix . 'wc_product_attributes_lookup' ) as $table ) {
  $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
  usort( $rows, static fn( $a, $b ) => strcmp( serialize( $a ), serialize( $b ) ) );
  $result[$table] = $rows;
 }
 $result['attributes'] = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE meta_key='_product_attributes' ORDER BY meta_id", ARRAY_A );
 $result['registries'] = $wpdb->get_results( "SELECT * FROM {$wpdb->options} WHERE option_name IN ('schrack_wc_sync_merged_attributes','schrack_wc_sync_dynamic_attributes') ORDER BY option_id", ARRAY_A );
 return $result;
}
function drive_admin_job( $job, string $expected ): array {
 for ( $i = 0; $i < 100; ++$i ) {
  $state = Schrack_Attribute_Merge_Job::status();
  if ( 'running' !== $state['state'] ) { break; }
  $job->work( $state['id'] );
 }
 $state = Schrack_Attribute_Merge_Job::status();
 expect_same( $expected, $state['state'], 'Unexpected job result: ' . wp_json_encode( $job->view( $state ) ) );
 return $state;
}
if ( 'seed' === $admin_mode ) {
 for ( $i = 0; $i < 121; ++$i ) { wp_insert_term( sprintf( 'bulk-%03d', $i ), 'pa_tip_2' ); }
 $term = get_term_by( 'name', 'unused', 'pa_tip_2' );
 add_term_meta( $term->term_id, 'repeated', 'one' ); add_term_meta( $term->term_id, 'repeated', 'two' );
 update_option( 'admin_merger_before', admin_tables_snapshot(), false );
 echo "Seeded enough terms to span backup and migration checkpoints.\n";
} elseif ( 'exercise' === $admin_mode ) {
 // Mirror the admin-only WooCommerce hook that adds default ordering to new terms.
 add_action( 'created_term', static function( $id, $tt_id, $taxonomy ) {
  if ( 'pa_tip' === $taxonomy ) { add_term_meta( $id, 'order', 0, true ); }
 }, 10, 3 );
 $job = new Schrack_Attribute_Merge_Job();
 $job->transition( 'preview' );
 $state = drive_admin_job( $job, 'ready' );
 expect_same( 2, count( $state['groups'] ), 'Duplicate groups' );
 expect_same( false, Schrack_Attribute_Merge_Job::blocks_imports(), 'Preview permits imports' );
 expect_same( get_option( 'admin_merger_before' ), admin_tables_snapshot(), 'Preview preserves data' );
 // A simultaneous category transfer must be refused before changing job mode.
 $sync_status = get_option( Schrack_Settings::STATUS_OPTION_NAME, array() );
 update_option( Schrack_Settings::STATUS_OPTION_NAME, array( 'category_import' => array( 'state' => 'running' ) ) );
 try { $job->transition( 'apply', $state['id'] ); throw new LogicException( 'Category transfer accepted' ); }
 catch ( RuntimeException $error ) { expect_same( true, str_contains( $error->getMessage(), 'categorii' ), 'CSV category guard' ); }
 update_option( Schrack_Settings::STATUS_OPTION_NAME, $sync_status );
 $job->transition( 'apply', $state['id'] );
 expect_same( true, Schrack_Attribute_Merge_Job::blocks_imports(), 'Apply pauses imports' );
 $product_importer = (new ReflectionClass( Schrack_Product_Importer::class ))->newInstanceWithoutConstructor();
 $category_importer = (new ReflectionClass( Schrack_Category_CSV_Importer::class ))->newInstanceWithoutConstructor();
 $exporter = (new ReflectionClass( Schrack_Product_Exporter::class ))->newInstanceWithoutConstructor();
 expect_same( true, is_wp_error( $product_importer->prepare_upload( '/not-opened.csv', 'test.csv', true, 1 ) ), 'Product import start blocked' );
 expect_same( true, is_wp_error( $category_importer->prepare_upload( '/not-opened.csv', 'test.csv' ) ), 'Category import start blocked' );
 expect_same( 'error', $exporter->queue()['state'], 'Product export start blocked' );
 expect_same( 'error', $product_importer->resume()['state'], 'Product import resume blocked' );
 expect_same( true, is_wp_error( $category_importer->resume() ), 'Category import resume blocked' );
 expect_same( 'error', $exporter->resume()['state'], 'Product export resume blocked' );
 $job->transition( 'pause', $state['id'] );
 $job->work( $state['id'] );
 expect_same( 'paused', Schrack_Attribute_Merge_Job::status()['state'], 'Queued worker cannot restart paused job' );
 $job->transition( 'resume', $state['id'] );
 $state = Schrack_Attribute_Merge_Job::status();
 $state['not_before'] = time() - 1; // Avoid waiting for the production settle timer in this test.
 update_option( Schrack_Attribute_Merge_Job::OPTION, $state, false );
 // Interrupt the second SQL snapshot page; a partial append must be truncated on retry.
 $reads = 0;
 $backup_failure = static function( $query ) use ( &$reads, $wpdb ) {
  if ( str_starts_with( $query, 'SELECT * FROM `' . $wpdb->terms . '`' ) && 2 === ++$reads ) { throw new RuntimeException( 'Injected backup interruption' ); }
  return $query;
 };
 add_filter( 'query', $backup_failure );
 $state = drive_admin_job( $job, 'error' );
 remove_filter( 'query', $backup_failure );
 expect_same( 'backup', $state['phase'], 'Interrupted during backup' );
 expect_same( false, $state['mutation_started'], 'No writes before complete backup' );
 file_put_contents( $state['backup'], "INVALID INCOMPLETE TAIL\n", FILE_APPEND );
 $job->transition( 'resume', $state['id'] );
 $failure = static function( $value, $id, $key ) {
  if ( '_product_attributes' === $key ) { throw new RuntimeException( 'Injected product interruption' ); }
  return $value;
 };
 add_filter( 'update_post_metadata', $failure, 10, 3 );
 $state = drive_admin_job( $job, 'error' );
 remove_filter( 'update_post_metadata', $failure, 10 );
 expect_same( 'products', $state['phase'], 'Product interruption checkpoint' );
 expect_same( true, $state['backup_complete'], 'Backup completed before catalog writes' );
 expect_same( true, Schrack_Attribute_Merge_Job::blocks_imports(), 'Partial migration keeps imports paused' );
 expect_same( false, str_starts_with( realpath( $state['backup'] ), realpath( ABSPATH ) . '/' ), 'Backup is private' );
 expect_same( false, str_contains( file_get_contents( $state['backup'] ), 'INVALID INCOMPLETE TAIL' ), 'Uncheckpointed backup tail removed' );
 expect_same( get_option( 'merger_snapshot' ), snapshot(), 'Failed product transaction rolls back' );
 try { $job->transition( 'preview' ); throw new LogicException( 'Partial migration overwritten' ); }
 catch ( RuntimeException $error ) { expect_same( true, str_contains( $error->getMessage(), 'activ' ), 'Cannot replace partial migration' ); }
 $job->transition( 'resume', $state['id'] );
 $state = drive_admin_job( $job, 'complete' );
 expect_same( false, Schrack_Attribute_Merge_Job::blocks_imports(), 'Completed job releases imports' );
 $unused = get_term_by( 'name', 'unused', 'pa_tip' );
 expect_same( array( 'one', 'two' ), get_term_meta( $unused->term_id, 'repeated', false ), 'Repeated term metadata retained' );
 expect_same( 121, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING(term_id) WHERE tt.taxonomy='pa_tip' AND t.name LIKE 'bulk-%'" ), 'All paginated terms copied' );
 update_option( 'admin_merger_completed', $state, false );
 echo "PASS: preview, CSV guards, pause, backup interruption, retry, transactional rollback, resume, term pagination and import release.\n";
} elseif ( 'restore' === $admin_mode ) {
 $state = get_option( 'admin_merger_completed' );
 if ( ! $state ) { throw new RuntimeException( 'Run exercise first.' ); }
 // Each generated statement occupies one line; UNHEX protects embedded newlines and quotes.
 $file = fopen( $state['backup'], 'rb' );
 while ( false !== ( $line = fgets( $file ) ) ) {
  if ( '' === trim( $line ) || str_starts_with( $line, '--' ) ) { continue; }
  if ( false === $wpdb->query( $line ) ) { throw new RuntimeException( $wpdb->last_error ); }
 }
 fclose( $file ); wp_cache_flush(); delete_transient( 'wc_attribute_taxonomies' ); WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
 expect_same( get_option( 'admin_merger_before' ), admin_tables_snapshot(), 'SQL restoration must exactly match all original backed-up rows' );
 expect_same( array(), Schrack_Attribute_Merge_Job::status(), 'Restore invalidates old worker' );
 (new Schrack_Attribute_Merge_Job())->work( $state['id'] );
 expect_same( get_option( 'admin_merger_before' ), admin_tables_snapshot(), 'Stale queued worker cannot modify restored data' );
 echo "PASS: PHP-generated SQL restores every backed-up row exactly and invalidates the previous job.\n";
} elseif ( 'access' === $admin_mode ) {
 if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
 $job = new Schrack_Attribute_Merge_Job();
 $die_handler = static fn() => static function( $message, $title, $arguments ) { throw new RuntimeException( 'test-wp-die' ); };
 add_filter( 'wp_die_handler', $die_handler );
 add_filter( 'wp_die_ajax_handler', $die_handler );
 $before = Schrack_Attribute_Merge_Job::status();
 foreach ( array( 0, get_user_by( 'login', 'testadmin' )->ID ) as $user ) {
  wp_set_current_user( $user );
  $_REQUEST['_wpnonce'] = 'invalid'; $_REQUEST['nonce'] = 'invalid';
  foreach ( array( 'handle_action', 'ajax_tick', 'download' ) as $method ) {
   $denied = false;
   try { $job->$method(); } catch ( RuntimeException $error ) { $denied = 'test-wp-die' === $error->getMessage(); }
   expect_same( true, $denied, 'Endpoint must reject anonymous user or invalid nonce: ' . $method );
  }
 }
 remove_filter( 'wp_die_handler', $die_handler );
 remove_filter( 'wp_die_ajax_handler', $die_handler );
 expect_same( $before, Schrack_Attribute_Merge_Job::status(), 'Rejected requests cannot change job state' );
 echo "PASS: action, polling and download reject missing authorization and invalid nonces.\n";
}
