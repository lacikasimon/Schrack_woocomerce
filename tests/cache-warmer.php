<?php
/** Deterministic scheduling/URL/privacy tests: no database or network. */
namespace SchrackWarmTest;
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
$options = $transients = $events = $hooks = $requests = array();
$now = 10000; $can = true; $nonce = true; $allow_lock = true; $lock_held = false; $count = 0;
class WP_Error { public function __construct( public string $code, public string $message ) {} public function get_error_message() { return $this->message; } }
class Json extends \Exception { public function __construct( public bool $ok, public mixed $data, public int $status ) {} }
class FakeDB {
	public string $options = 'custom_options'; public bool $save_queries = false; public array $queries = array(); public int $num_queries = 7;
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) { global $allow_lock, $lock_held; if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $lock_held = false; return 1; } if ( ! $allow_lock || $lock_held ) { return 0; } $lock_held = true; return 1; }
}
$wpdb = new FakeDB();
function time() { return $GLOBALS['now']; }
function get_option( $k, $d = false ) { return $GLOBALS['options'][$k] ?? $d; }
function update_option( $k, $v, $a = false ) { $GLOBALS['options'][$k] = $v; }
function get_transient( $k ) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][$k] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][$k] ); }
function add_action( $k, $v, $priority = 10 ) { $GLOBALS['hooks'][$k][] = $v; }
function do_action( $k, ...$args ) { foreach ( $GLOBALS['hooks'][$k] ?? array() as $fn ) { $fn(...$args); } }
function wp_next_scheduled( $k ) { return $GLOBALS['events'][$k] ?? false; }
function wp_schedule_event( $t, $interval, $k ) { $GLOBALS['events'][$k] = $t; }
function wp_schedule_single_event( $t, $k ) { $GLOBALS['events'][$k] = $t; }
function wp_clear_scheduled_hook( $k ) { unset( $GLOBALS['events'][$k] ); }
function current_user_can( $cap ) { return $GLOBALS['can']; }
function check_ajax_referer( $a, $b ) { if ( ! $GLOBALS['nonce'] ) { throw new Json( false, 'nonce', 403 ); } }
function wp_send_json_error( $d, $s = 200 ) { throw new Json( false, $d, $s ); }
function wp_send_json_success( $d ) { throw new Json( true, $d, 200 ); }
function wp_unslash( $s ) { return $s; }
function sanitize_key( $s ) { return $s; }
function home_url( $s = '' ) { return 'https://shop.example' . $s; }
function wp_parse_url( $s ) { return parse_url( $s ); }
function wp_http_validate_url( $s ) { return ! str_contains( $s, '%00' ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function url_to_postid( $s ) { return array( home_url('/product/lamp/') => 10, home_url('/shop/') => 11, home_url('/cart/') => 12, home_url('/product/hidden/') => 13, home_url('/product/draft/') => 14, home_url('/product/password/') => 15 )[ $s ] ?? 0; }
function get_post( $id ) { return (object) array( 'post_status' => 14 === $id ? 'draft' : 'publish', 'post_password' => 15 === $id ? 'secret' : '', 'post_type' => in_array( $id, array(10,13,14,15) ) ? 'product' : 'page' ); }
function wc_get_product( $id ) { return new class($id) { public function __construct(private int $id) {} public function get_catalog_visibility() { return 13 === $this->id ? 'hidden' : 'visible'; } }; }
function wc_get_page_id( $p ) { return 11; }
function get_permalink( $id ) { return home_url( 10 === $id ? '/product/lamp/' : '/shop/' ); }
function get_term_by( $field, $slug, $tax ) { return 'lights' === $slug ? (object) array('term_id' => 1) : false; }
function get_term_link( $t ) { return home_url('/category/lights/'); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_safe_remote_get( $url, $args ) { $GLOBALS['requests'][] = array($url, $args); if ( !empty($GLOBALS['http_callback']) ) { ($GLOBALS['http_callback'])(); } return $GLOBALS['response'] ?? array('code'=>200,'cache'=>'miss'); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_header( $r, $h ) { return $r['cache']; }
function wp_generate_uuid4() { return 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; }
function add_query_arg( $k, $v, $url ) { return $url . '?' . $k . '=' . $v; }
foreach ( array('cache-warmer', 'page-profile') as $file ) {
	$source = file_get_contents( __DIR__ . '/../includes/class-schrack-' . $file . '.php' );
	eval( 'namespace ' . __NAMESPACE__ . ';' . substr( $source, 5 ) );
}
function check( $ok, $message ) { if (!$ok) { throw new \RuntimeException($message); } $GLOBALS['count']++; }
function ajax( $command, $args = array() ): Json {
	$_POST = array_merge( array('command'=>$command), $args );
	try { (new Schrack_Cache_Warmer())->ajax(); } catch (Json $j) { return $j; }
	throw new \RuntimeException('Missing JSON');
}
$warm = new Schrack_Cache_Warmer();
check( !$warm->config()['enabled'], 'Default must be disabled.' );
foreach ( array('/', '/shop/', '/product/lamp/', '/category/lights/') as $path ) { check( home_url($path) === $warm->public_url(home_url($path)), 'Public canonical URL accepted.'); }
foreach ( array('https://evil.example/', 'http://shop.example/', 'https://user:pass@shop.example/', 'https://shop.example:444/', home_url('/cart/'), home_url('/wp-admin/'), home_url('/?add-to-cart=10'), home_url('/#x'), home_url('/?'), home_url('/product/hidden/'), home_url('/product/password/'), home_url('/product/draft/'), home_url('/wrong/lights/'), home_url('/%00/')) as $url ) { check( '' === $warm->public_url($url), 'Private/noncanonical URL rejected: '.$url ); }
$can = false; check(403 === ajax('start')->status, 'Capability gate.'); $can = true;
$nonce = false; check(403 === ajax('start')->status, 'Nonce gate.'); $nonce = true;
check(!ajax('save', array('urls'=>home_url('/?add-to-cart=10'),'enabled'=>'1'))->ok && !$options, 'Invalid save has no side effects.');
$urls = array(home_url('/'),home_url('/product/lamp/'),home_url('/category/lights/'),home_url('/shop/'));
check(ajax('save', array('urls'=>implode("\n",$urls),'enabled'=>'1'))->ok, 'Save starts enabled warming.');
check(isset($events[Schrack_Cache_Warmer::CYCLE], $events[Schrack_Cache_Warmer::TICK]), 'Hourly and tick jobs scheduled.');
$warm->tick();
check(1 === count($requests) && 1 === $options[Schrack_Cache_Warmer::STATE]['cursor'], 'Exactly one URL per job.');
check($events[Schrack_Cache_Warmer::TICK] === $now + 60, 'One-minute spacing.');
check(array() === $requests[0][1]['cookies'] && 0 === $requests[0][1]['redirection'], 'No sessions or redirects.');
check('MISS' === $options[Schrack_Cache_Warmer::STATE]['results'][0]['cache'], 'MISS not falsely reported as HIT.');
$allow_lock = false; $warm->tick(); check(1 === count($requests), 'Concurrent worker cannot request.'); $allow_lock = true;
$before = $options[Schrack_Cache_Warmer::STATE]; $warm->after_purge(); check($before === $options[Schrack_Cache_Warmer::STATE], 'Purge during run does not restart it.');
check($events[Schrack_Cache_Warmer::REWARM] === $now+900, 'Purge during a run is remembered for later.');
$warm->after_purge(); check($events[Schrack_Cache_Warmer::REWARM] === $now+900,'Import purge bursts coalesce into one delayed job.');
ajax('start'); check($before === $options[Schrack_Cache_Warmer::STATE], 'Repeated start is idempotent.');
ajax('stop'); $warm->tick(); $warm->cycle(); $warm->after_purge();
check(!$events && !$warm->config()['enabled'] && 1 === count($requests), 'Stop prevents both queued and recurring work.');
ajax('start'); $response = array('code'=>503,'cache'=>'');
for ($i=0;$i<3;$i++) { $warm->tick(); }
check('error' === $options[Schrack_Cache_Warmer::STATE]['status'] && !isset($events[Schrack_Cache_Warmer::TICK]), 'Three failures stop run.');
ajax('start'); $response = array('code'=>200,'cache'=>'hit');
for ($i=0;$i<4;$i++) { $warm->tick(); }
check('complete' === $options[Schrack_Cache_Warmer::STATE]['status'] && !isset($events[Schrack_Cache_Warmer::TICK]), 'Completed queue stops.');
$before = count($requests); $warm->tick(); check($before === count($requests), 'Stale tick cannot restart completion.');
ajax('start'); $options[Schrack_Cache_Warmer::STATE]['attempts'] = 3; $warm->tick();
check('error' === $options[Schrack_Cache_Warmer::STATE]['status'] && $before === count($requests), 'Repeated process failures are bounded.');
check(!ajax('profile',array('url'=>home_url('/cart/')))->ok,'Cannot profile private URL.');
check(!ajax('profile_cold',array('url'=>home_url('/cart/')))->ok,'Cold profile also rejects private URLs.');
check(!Schrack_Page_Profile::cold_selections(),'Cold profiling is off on ordinary requests.');
$_SERVER = array(); Schrack_Page_Profile::maybe_start(); check(!$wpdb->save_queries && !$hooks, 'Normal visitors have no profiling cost.');
$_SERVER['HTTP_X_SCHRACK_PROFILE'] = str_repeat('a',64); Schrack_Page_Profile::maybe_start(); check(!$wpdb->save_queries, 'Forged header cannot profile.');
$id = hash('sha256',str_repeat('a',64)); $ticket = 'schrack_profile_ticket_'.$id;
$transients[$ticket] = array('uri'=>'/product/lamp/?schrack_perf_probe=1');
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/cart/'; Schrack_Page_Profile::maybe_start(); check(!$wpdb->save_queries,'Ticket is bound to URI.');
$_SERVER['REQUEST_URI']=$transients[$ticket]['uri']; Schrack_Page_Profile::maybe_start(); check($wpdb->save_queries && !isset($transients[$ticket]),'Valid one-use ticket enables measurement.');
$wpdb->queries[] = array("SELECT 'sensitive customer data'",0.012,'Schrack_Product_Mapper->render, wpdb->get_results');
do_action('plugins_loaded'); do_action('shutdown');
$result=$transients['schrack_profile_result_'.$id];
check(12.0 === $result['measured_db_ms'] && !str_contains(json_encode($result),'sensitive'), 'Only aggregate SQL timings retained.');
check(!$wpdb->save_queries, 'Original profiler state restored.');
echo "Cache warmer: $count checks passed.\n";
