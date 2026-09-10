<?php
/**
 * Transfer AJAX contracts with in-memory WP/WooCommerce services.
 * Run: php tests/product-transfer.php [--fixtures=/private/tmp/transfer-preview]
 */
define( 'ABSPATH', __DIR__ );
define( 'SCHRACK_WC_SYNC_PATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$checks = 0;
$allowed = true;
$hooks = array();

class JsonResponse extends RuntimeException {
	public function __construct( public bool $success, public array $data, public int $status = 200 ) { parent::__construct(); }
}
function wp_send_json_success( array $data, int $status = 200 ): never { throw new JsonResponse( true, $data, $status ); }
function wp_send_json_error( array $data, int $status = 200 ): never { throw new JsonResponse( false, $data, $status ); }
function current_user_can( string $capability ): bool { return $GLOBALS['allowed']; }
function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }
function check_ajax_referer( string $action, string $field ): void {
	if ( ( $_POST[ $field ] ?? '' ) !== wp_create_nonce( $action ) ) { wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 ); }
}
function check_admin_referer( string $action ): void { check_ajax_referer( $action, '_wpnonce' ); }
function add_action( string $hook, mixed $callback, mixed ...$args ): void { $GLOBALS['hooks'][ $hook ] = $callback; }
function wp_die( string $message ): never { throw new RuntimeException( $message ); }
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( string $value ): string { return strip_tags( $value ); }
function sanitize_textarea_field( string $value ): string { return strip_tags( $value ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function __( string $text, string $domain = '' ): string { return schrack_wc_sync_romanian_text( $text ); }
function schrack_wc_sync_romanian_text( string $text ): string {
	static $translations;
	$translations ??= require SCHRACK_WC_SYNC_PATH . 'includes/schrack-romanian-ui.php';
	return $translations[ $text ] ?? $text;
}
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( string $text ): string { return esc_html( $text ); }
function esc_url( string $text ): string { return esc_attr( $text ); }
function esc_html_e( string $text, string $domain = '' ): void { echo esc_html( __( $text, $domain ) ); }
function esc_attr_e( string $text, string $domain = '' ): void { echo esc_attr( __( $text, $domain ) ); }
function number_format_i18n( mixed $value ): string { return number_format( (float) $value, 0, ',', '.' ); }
function size_format( int $bytes ): string { return $bytes . ' B'; }
function admin_url( string $path ): string { return '/' . $path; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function wp_nonce_url( string $url, string $action ): string { return $url . '&_wpnonce=' . wp_create_nonce( $action ); }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">'; }
function disabled( mixed $value ): void { if ( $value ) { echo 'disabled'; } }
function selected( mixed $value, mixed $expected ): void { if ( $value === $expected ) { echo 'selected'; } }
function checked( mixed $value, mixed $expected = true ): void { if ( $value === $expected ) { echo 'checked'; } }
function wp_json_encode( mixed $value ): string { return json_encode( $value ); }
function wp_max_upload_size(): int { return 1024 * 1024; }
function apply_filters( string $filter, mixed $value ): mixed { return $value; }
function wp_dropdown_categories( array $args ): void { echo '<select id="export-category-id" name="export_category_id"><option value="0">Toate categoriile</option></select>'; }
function get_term( int $id, string $taxonomy ): mixed { return null; }
function is_wp_error( mixed $value ): bool { return false; }
function set_transient( mixed ...$args ): never { throw new RuntimeException( 'AJAX must not use shared redirect notices' ); }

class Schrack_Settings {
	public array $data = array();
	public function get_status(): array { return $this->data; }
}
class Schrack_Logger {}
class Schrack_Cron {
	public function queue_category_csv_import( string $id ): array { return array( 'queued' => true ); }
}
class Schrack_Category_Markup { public function __construct( mixed $settings ) {} }
class Schrack_Category_CSV_Importer { public const STATUS_KEY = 'category_import'; }
class Schrack_Product_Exporter {
	public array $data = array();
	public int $queued = 0;
	public int $reads = 0;
	public function status(): array { ++$this->reads; return $this->data; }
	public function column_catalog(): never { throw new RuntimeException( 'Polling must not scan the column catalog' ); }
	public function queue( array $filters, array $columns ): array {
		++$this->queued;
		return $this->data = array( 'state' => 'queued', 'export_id' => 'fixture-export', 'total' => 10, 'processed' => 0, 'filters' => $filters, 'column_config' => $columns );
	}
	public function reset(): void { $this->data = array(); }
	public function resume(): array { return array( 'state' => 'error', 'message' => 'Checkpoint unavailable' ); }
}
class Schrack_Product_Importer {
	public array $data = array();
	public function status(): array { return $this->data; }
	public function reset(): void { $this->data = array(); }
}

require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-admin.php';
class PreviewAdmin extends Schrack_Admin {
	public function render_tabs( string $active ): void {}
	public function preview(): string {
		$notice = null;
		$product_export = array();
		$product_import = array();
		$category_import = array();
		$export_column_catalog = array( 'standard' => array( 'id' => 'ID', 'sku' => 'SKU', 'name' => 'Nume' ) );
		ob_start();
		include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-export.php';
		return ob_get_clean();
	}
}
$settings = new Schrack_Settings();
$exporter = new Schrack_Product_Exporter();
$importer = new Schrack_Product_Importer();
$admin = new PreviewAdmin( $settings, new Schrack_Logger(), new Schrack_Cron(), $exporter, $importer );
$admin->init();

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$GLOBALS['checks'];
}
function poll(): JsonResponse {
	$_POST = array( 'nonce' => wp_create_nonce( 'schrack_wc_sync_transfer_status' ) );
	try { $GLOBALS['admin']->ajax_transfer_status(); } catch ( JsonResponse $result ) { return $result; }
	throw new RuntimeException( 'Expected a JSON response' );
}
function action( string $action, array $fields = array() ): JsonResponse {
	$_POST = $fields + array( 'action' => 'schrack_wc_sync_' . $action, '_wpnonce' => wp_create_nonce( 'schrack_wc_sync_' . $action ) );
	try { $GLOBALS['admin']->ajax_transfer_action(); } catch ( JsonResponse $result ) { return $result; }
	throw new RuntimeException( 'Expected a JSON action response' );
}

$allowed = false;
check( poll()->status === 403 && $exporter->reads === 0, 'Unauthorized reads must stop before accessing job data' );
check( action( 'product_export_start' )->status === 403 && $exporter->queued === 0, 'Unauthorized mutations must not queue a job' );
$allowed = true;
$_POST = array( 'nonce' => 'invalid' );
try { $admin->ajax_transfer_status(); } catch ( JsonResponse $result ) { check( $result->status === 403, 'Status requests require a valid nonce' ); }
check( action( 'product_export_start', array( '_wpnonce' => 'invalid' ) )->status === 403 && $exporter->queued === 0, 'Mutation handlers must retain nonce checks' );
check( action( 'delete_everything' )->status === 400, 'Dispatch must reject unknown methods' );

$idle = poll()->data;
check( !$idle['active'] && !$idle['locked'], 'Idle transfers stop polling and unlock controls' );
check( array_keys( $idle['fragments'] ) === array( 'export', 'import', 'category' ), 'Refresh must contain all three status panels' );
check( !str_contains( implode( '', $idle['fragments'] ), 'type="file"' ), 'Status fragments must not replace uploaded file inputs' );

$queued = action( 'product_export_start', array( 'export_search' => 'solar', 'export_scope' => 'filtered', 'export_column_mode' => 'custom', 'export_columns' => array( 'sku', 'name' ) ) );
check( $queued->success && $exporter->queued === 1 && $queued->data['active'] && $queued->data['locked'], 'Start must queue once and return locked active status' );
check( str_contains( $queued->data['fragments']['export'], 'solar' ), 'The applied filters must update immediately' );
check( $queued->data['notice']['type'] === 'success', 'Mutation feedback must be returned inline' );
poll();
check( $exporter->queued === 1, 'Polling must never queue another batch' );

$exporter->data += array( 'last_progress_at' => time(), 'file_path' => '/private/secret.csv' );
$exporter->data['state'] = 'finalizing';
$exporter->data['finalize_position'] = 50;
$exporter->data['finalize_total_bytes'] = 100;
$finalizing = poll()->data;
check( $finalizing['active'] && str_contains( $finalizing['fragments']['export'], '50 B / 100 B (50%)' ), 'Finalization progress must remain live' );
check( !str_contains( json_encode( $finalizing ), '/private/secret.csv' ), 'Private filesystem paths must not enter the response' );

$exporter->data['state'] = 'done';
$exporter->data['processed'] = 10;
$exporter->data['file_name'] = 'fixture.csv';
$done = poll()->data;
check( !$done['active'] && !$done['locked'] && $done['completed_export_id'] === 'fixture-export', 'Completion stops polling, unlocks forms, and enables direct import' );
check( str_contains( $done['fragments']['export'], 'product_export_download' ), 'The completed download URL must appear without navigation' );

$exporter->data['state'] = 'running';
$exporter->data['last_progress_at'] = time() - 601;
$stale = poll()->data;
check( !$stale['active'] && str_contains( $stale['fragments']['export'], 'product_export_resume' ), 'Stale exports must expose resume without endless polling' );
$resume = action( 'product_export_resume' );
check( !$resume->success && $resume->data['notice']['message'] === 'Checkpoint unavailable', 'Rejected mutations must return an error notice and current status' );
check( action( 'product_export_reset' )->data['locked'] === false, 'Reset must clear the status in place' );

$importer->data = array( 'state' => 'running', 'percentage' => 35, 'warnings' => array( '<script>bad()</script>' ) );
$import = poll()->data;
check( $import['active'] && str_contains( $import['fragments']['import'], '35%' ), 'Product import counters must update through the same endpoint' );
check( !str_contains( $import['fragments']['import'], '<script>' ) && str_contains( $import['fragments']['import'], '&lt;script&gt;' ), 'Worker messages must remain escaped' );
action( 'product_import_reset' );
$settings->data['category_import'] = array( 'state' => 'running', 'processed' => 2, 'total_rows' => 4, 'updated_at' => time() );
$category = poll()->data;
check( $category['active'] && $category['locked'] && !$category['product_locked'], 'Category work must lock starts without reporting a product job' );
check( str_contains( $category['fragments']['category'], '2 / 4 (50%)' ), 'Category import progress must update asynchronously' );
check( !isset( $hooks['wp_ajax_nopriv_schrack_wc_sync_transfer_status'] ), 'No anonymous status endpoint may be registered' );

$page = $admin->preview();
check( !str_contains( $page, 'location.reload' ) && substr_count( $page, 'data-transfer-fragment=' ) === 3, 'The page must mount fragments and omit reload scripts' );
check( substr_count( $page, '<form ' ) === substr_count( $page, '</form>' ), 'All forms must remain complete' );
check( substr_count( $page, '<fieldset' ) === substr_count( $page, '</fieldset>' ), 'Transfer lock fieldsets must remain balanced' );

foreach ( $argv as $argument ) {
	if ( str_starts_with( $argument, '--fixtures=' ) ) {
		$dir = substr( $argument, strlen( '--fixtures=' ) );
		if ( !is_dir( $dir ) ) { mkdir( $dir, 0700, true ); }
		file_put_contents( $dir . '/page.html', $page );
		file_put_contents( $dir . '/states.json', json_encode( compact( 'idle', 'finalizing', 'done', 'stale', 'import', 'category' ) + array( 'queued' => $queued->data ), JSON_UNESCAPED_UNICODE ) );
	}
}
echo "Passed $checks transfer AJAX checks.\n";
