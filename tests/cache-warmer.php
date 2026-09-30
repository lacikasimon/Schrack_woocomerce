<?php
/** Deterministic scheduling/URL/privacy tests: no database or network. */
namespace SchrackWarmTest;
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
$options = $transients = $events = $hooks = $requests = array();
$clock = 0.0; $now = 10000; $can = true; $nonce = true; $allow_lock = true; $lock_held = false; $count = 0;
class WP_Error { public function __construct( public string $code, public string $message ) {} public function get_error_message() { return $this->message; } }
class Json extends \Exception { public function __construct( public bool $ok, public mixed $data, public int $status ) {} }
class FakeDB {
	public string $options = 'custom_options'; public string $posts = 'custom_posts'; public string $wc_product_meta_lookup = 'custom_product_lookup'; public string $term_taxonomy = 'custom_term_taxonomy'; public string $last_error = ''; public bool $save_queries = false; public array $queries = array(); public int $num_queries = 7;
	public function prepare( $sql, ...$args ) { foreach($args as $arg) { $sql=preg_replace('/%[sd]/',(string)$arg,$sql,1); } return $sql; }
	public function get_col($sql) { if(str_contains($sql,"taxonomy = 'product_cat'")) { $GLOBALS['category_sql'][]=$sql;preg_match('/term_id > (\d+)/',$sql,$m);return array_slice(array_values(array_filter($GLOBALS['category_ids'] ?? array(),static fn($id)=>$id>(int)$m[1])),0,100); } $GLOBALS['catalog_sql'][]=$sql; preg_match('/ID > (\d+)/',$sql,$m); return array_slice(array_values(array_filter($GLOBALS['catalog_ids'] ?? array(),static fn($id)=>$id>(int)$m[1] && ( !str_contains($sql, "warm_stock.stock_status = 'instock'") || ($GLOBALS['stock_statuses'][$id] ?? 'instock') === 'instock' ))),0,100); }
	public function get_var( $sql ) { global $allow_lock, $lock_held; if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $lock_held = false; return 1; } if ( ! $allow_lock || $lock_held ) { return 0; } $lock_held = true; return 1; }
}
$wpdb = new FakeDB();
function microtime($float=false) { return $GLOBALS['clock']; }
function time() { return $GLOBALS['now']; }
function get_option( $k, $d = false ) { return $GLOBALS['options'][$k] ?? $d; }
function update_option( $k, $v, $a = false ) { $GLOBALS['options'][$k] = $v; }
function get_transient( $k ) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][$k] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][$k] ); }
function add_action( $k, $v, $priority = 10 ) { $GLOBALS['hooks'][$k][] = $v; }
function remove_action( $k, $v, $priority = 10 ) { $GLOBALS['hooks'][$k] = array_filter($GLOBALS['hooks'][$k] ?? array(), static fn($callback) => $callback !== $v); }
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
function url_to_postid( $s ) { if(preg_match('~/product/p(\d+)/$~',$s,$m)) { return (int)$m[1]; } if ( !empty($GLOBALS['shop_archive_test']) && $s === home_url('/shop/') ) { return 0; } return array( home_url('/product/lamp/') => 10, home_url('/shop/') => 11, home_url('/cart/') => 12, home_url('/product/hidden/') => 13, home_url('/product/draft/') => 14, home_url('/product/password/') => 15 )[ $s ] ?? 0; }
function get_post( $id ) { if ( 11 === $id && isset($GLOBALS['shop_test_post']) ) { return $GLOBALS['shop_test_post']; } return (object) array( 'post_status' => 14 === $id ? 'draft' : 'publish', 'post_password' => 15 === $id ? 'secret' : '', 'post_type' => ( $id >= 20 || in_array( $id, array(10,13,14,15) ) ) ? 'product' : 'page' ); }
function wc_get_product( $id ) { return new class($id) { public function __construct(private int $id) {} public function get_stock_status() { return $GLOBALS['stock_statuses'][$this->id] ?? 'instock'; } public function get_catalog_visibility() { return 13 === $this->id || in_array($this->id,$GLOBALS['hidden_ids'] ?? array()) ? 'hidden' : (in_array($this->id,$GLOBALS['search_ids'] ?? array()) ? 'search' : 'visible'); } }; }
function wc_get_page_id( $p ) { return 11; }
function get_permalink( $id ) { if($id>=20) { return home_url('/product/p'.$id.'/'); } return home_url( 10 === $id ? '/product/lamp/' : '/shop/' ); }
function get_terms($args) { if(isset($args['include'])) { $GLOBALS['category_args'][]=$args; return array_map(static fn($id)=>(object)array('term_id'=>$id,'count'=>0),$args['include']); } return array((object)array('term_id'=>1)); }
function get_term_by( $field, $slug, $tax ) { if(preg_match('/^c(\d+)$/',$slug,$m) && in_array((int)$m[1],$GLOBALS['category_ids'] ?? array(),true)) { return (object)array('term_id'=>(int)$m[1]); } return 'lights' === $slug ? (object) array('term_id' => 1) : false; }
function get_term_link( $t ) { return home_url($t->term_id>=100 ? '/category/'.($t->term_id%2 ? 'parent/' : '').'c'.$t->term_id.'/' : '/category/lights/'); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_safe_remote_get( $url, $args ) { $GLOBALS['requests'][] = array($url, $args); if ( !empty($GLOBALS['http_callback']) ) { ($GLOBALS['http_callback'])(); }  $GLOBALS['clock'] += $GLOBALS['request_duration'] ?? 0.0; return isset($GLOBALS['response_factory']) ? ($GLOBALS['response_factory'])($url) : ($GLOBALS['response'] ?? array('code'=>200,'cache'=>'miss')); }
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
$GLOBALS['search_ids']=array(23);check(home_url('/product/p23/')===$warm->public_url(home_url('/product/p23/')),'Search-only published products are public and must be eligible for all-product warming.');
foreach ( array('/', '/shop/', '/product/lamp/', '/category/lights/') as $path ) { check( home_url($path) === $warm->public_url(home_url($path)), 'Public canonical URL accepted.'); }
foreach ( array('https://evil.example/', 'http://shop.example/', 'https://user:pass@shop.example/', 'https://shop.example:444/', home_url('/cart/'), home_url('/wp-admin/'), home_url('/?add-to-cart=10'), home_url('/#x'), home_url('/?'), home_url('/product/hidden/'), home_url('/product/password/'), home_url('/product/draft/'), home_url('/wrong/lights/'), home_url('/%00/')) as $url ) { check( '' === $warm->public_url($url), 'Private/noncanonical URL rejected: '.$url ); }
$can = false; check(403 === ajax('start')->status, 'Capability gate.'); $can = true;
$nonce = false; check(403 === ajax('start')->status, 'Nonce gate.'); $nonce = true;
check(!ajax('save', array('urls'=>home_url('/?add-to-cart=10'),'enabled'=>'1'))->ok && !$options, 'Invalid save has no side effects.');
$urls = array_merge(array(home_url('/'),home_url('/product/lamp/'),home_url('/category/lights/'),home_url('/shop/')),array_map(static fn($id)=>get_permalink($id),range(20,29)));
check(ajax('save', array('urls'=>implode("\n",$urls),'enabled'=>'1'))->ok, 'Save starts enabled warming.');
check(isset($events[Schrack_Cache_Warmer::CYCLE], $events[Schrack_Cache_Warmer::TICK]), 'Hourly and tick jobs scheduled.');
$warm->tick();
check(10 === count($requests) && 5 === $options[Schrack_Cache_Warmer::STATE]['cursor'], 'A cold job is bounded to five page builds and their confirmation requests.');
check(array(home_url('/'),home_url('/shop/')) === array_slice($options[Schrack_Cache_Warmer::STATE]['urls'],0,2),'Home and shop must precede manual product selections.');
check($events[Schrack_Cache_Warmer::TICK] === $now + 60, 'One-minute spacing.');
check(array() === $requests[0][1]['cookies'] && 0 === $requests[0][1]['redirection'], 'No sessions or redirects.');
check('MISS' === $options[Schrack_Cache_Warmer::STATE]['results'][0]['cache'], 'MISS not falsely reported as HIT.');
$allow_lock = false; $warm->tick(); check(10 === count($requests), 'Concurrent worker cannot request.'); $allow_lock = true;
$before = $options[Schrack_Cache_Warmer::STATE]; $warm->after_purge(); check($before === $options[Schrack_Cache_Warmer::STATE], 'Purge during run does not restart it.');
check($events[Schrack_Cache_Warmer::REWARM] === $now+300, 'Purge during a run is remembered for later.');
$warm->after_purge(); check($events[Schrack_Cache_Warmer::REWARM] === $now+300,'Import purge bursts coalesce into one delayed job.');
ajax('start'); check($before === $options[Schrack_Cache_Warmer::STATE], 'Repeated start is idempotent.');
ajax('stop'); $warm->tick(); $warm->cycle(); $warm->after_purge();
check(!$events && !$warm->config()['enabled'] && 10 === count($requests), 'Stop prevents both queued and recurring work.');
ajax('start'); $response = array('code'=>503,'cache'=>'');
for ($i=0;$i<3;$i++) { $warm->tick(); }
check('error' === $options[Schrack_Cache_Warmer::STATE]['status'] && !isset($events[Schrack_Cache_Warmer::TICK]), 'Three failures stop run.');
ajax('start'); $response = array('code'=>200,'cache'=>'hit');
for ($i=0;$i<2;$i++) { $warm->tick(); }
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
$transients[$ticket] = array('uri'=>'/product/lamp/?schrack_perf_probe=1','cold_selections'=>true);
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/cart/'; Schrack_Page_Profile::maybe_start(); check(!$wpdb->save_queries,'Ticket is bound to URI.');
check(!Schrack_Page_Profile::cold_selections(),'Mismatched cold ticket cannot bypass selection cache.');
$_SERVER['REQUEST_URI']=$transients[$ticket]['uri']; Schrack_Page_Profile::maybe_start(); check($wpdb->save_queries && !isset($transients[$ticket]),'Valid one-use ticket enables measurement.');
check(Schrack_Page_Profile::cold_selections(),'Validated cold ticket enables selection bypass.');
$wpdb->queries[] = array("SELECT 'sensitive customer data'",0.012,'Schrack_Product_Mapper->render, wpdb->get_results');
do_action('plugins_loaded'); do_action('shutdown');
$result=$transients['schrack_profile_result_'.$id];
check(12.0 === $result['measured_db_ms'] && !str_contains(json_encode($result),'sensitive'), 'Only aggregate SQL timings retained.');
check(!$wpdb->save_queries, 'Original profiler state restored.');
$GLOBALS['http_callback']=null;
$measurement=Schrack_Page_Profile::measure(home_url('/'));
check(null === $measurement['ttfb_ms'],'Missing cURL timing is unknown, never substituted with total duration.');
check(empty($hooks['http_api_curl']),'Transport observer is removed after the measurement.');
$GLOBALS['shop_archive_test'] = true;
$shop_warmer = new Schrack_Cache_Warmer();
check(home_url('/shop/') === $shop_warmer->public_url(home_url('/shop/')), 'Canonical configured shop archive is valid without a reverse post ID.');
foreach (array(array('draft',''),array('private',''),array('publish','secret')) as $fields) {
 $GLOBALS['shop_test_post']=(object)array('post_type'=>'page','post_status'=>$fields[0],'post_password'=>$fields[1]);
 check('' === $shop_warmer->public_url(home_url('/shop/')), 'Non-public configured shop pages must remain rejected.');
}
unset($GLOBALS['shop_test_post']);
check('' === $shop_warmer->public_url(home_url('/shop/?add-to-cart=10')), 'Shop query actions must remain rejected.');
check('' === $shop_warmer->public_url(home_url('/shop')), 'Noncanonical shop aliases must remain rejected.');
// Full catalogue traversal must not stop at the manual-list or result-history limit.
$GLOBALS['catalog_ids'] = range(100,329);
$GLOBALS['hidden_ids'] = array(105,205);
$GLOBALS['stock_statuses']=array(120=>'outofstock',310=>'onbackorder',320=>'outofstock');
$GLOBALS['catalog_sql'] = array();
$GLOBALS['response_factory'] = null;
$response = array('code'=>200,'cache'=>'hit');
check(ajax('save',array('urls'=>home_url('/'),'enabled'=>'1','discover'=>'1'))->ok,'Enable all-product traversal.');
$rounds=0;
while('running' === $options[Schrack_Cache_Warmer::STATE]['status'] && ++$rounds < 40) { $warm->tick(); }
$state=$options[Schrack_Cache_Warmer::STATE];
check('complete' === $state['status'] && 225 === $state['products_processed'],'All public in-stock products beyond 100 must be visited; hidden, sold-out and backordered products are excluded.');
check(100 === count($state['results']) && 329 === $state['product_after'],'Persist only bounded recent results and the durable product cursor.');
check(count($GLOBALS['catalog_sql'])>=3 && str_contains($GLOBALS['catalog_sql'][0],'custom_posts') && str_contains($GLOBALS['catalog_sql'][1],'ID > 200'),'Keyset pages use actual table names and advance after the last scanned ID.');
check($state['confirmed']===$state['processed'] && 0 === $state['unconfirmed'],'Only explicit HIT responses contribute to confirmed coverage.');
ajax('save',array('urls'=>home_url('/'),'enabled'=>'1','discover'=>'1'));$before=count($requests);$warm->tick();
check(50===count($requests)-$before,'Fast cached responses advance up to fifty pages while cold batches remain limited to five builds.');
check(str_contains($GLOBALS['catalog_sql'][0],'custom_product_lookup') && str_contains($GLOBALS['catalog_sql'][0],"warm_stock.stock_status = 'instock'"),'The keyset query filters stock in the actual WooCommerce lookup table before materializing URLs.');
check(!array_filter($requests,static fn($r)=>in_array($r[0],array(get_permalink(120),get_permalink(310),get_permalink(320)),true)),'Stock-excluded catalogue IDs never produce HTTP requests.');
$GLOBALS['stock_statuses']=array();
// A MISS gets exactly one later GET and becomes confirmed only when that GET is HIT.
$GLOBALS['catalog_ids']=array(); $GLOBALS['hidden_ids']=array(); $seen=array();
$GLOBALS['response_factory']=function($url) use (&$seen) { $n=($seen[$url] ?? 0)+1;$seen[$url]=$n;return array('code'=>200,'cache'=>$n===1?'miss':'hit'); };
ajax('save',array('urls'=>home_url('/'),'enabled'=>'1'));
$warm->tick();$state=$options[Schrack_Cache_Warmer::STATE];
check('complete'===$state['status'] && $state['results'][0]['verified']===true && 2 === $seen[home_url('/')],'MISS→HIT requires a real second request, not HTTP 200 alone.');
$GLOBALS['response_factory']=null; $response=array('code'=>200,'cache'=>'miss');
ajax('start');$warm->tick();$state=$options[Schrack_Cache_Warmer::STATE];
check(false===$state['results'][0]['verified'] && 2===$state['unconfirmed'],'A second MISS is unconfirmed and is never retried indefinitely.');
// Each batch respects wall time and slows down after a long response.
$response=array('code'=>200,'cache'=>'hit');$GLOBALS['request_duration']=4;
ajax('save',array('urls'=>implode("\n",$urls),'enabled'=>'1'));$before=count($requests);$warm->tick();
check(4===count($requests)-$before,'A 15-second budget stops a batch after four four-second requests.');
$GLOBALS['request_duration']=6; $before=count($requests);$warm->tick();
check(1===count($requests)-$before,'A slow response ends the batch immediately.');
$GLOBALS['request_duration']=0;
// Purge during a full scan reheats priority URLs without losing the catalogue cursor.
$GLOBALS['catalog_ids']=range(100,229);
ajax('save',array('urls'=>home_url('/'),'enabled'=>'1','discover'=>'1'));$warm->tick();
$state=$options[Schrack_Cache_Warmer::STATE];$cursor=$state['cursor'];$after=$state['product_after'];
$allow_lock=false;$warm->after_purge();check(isset($events[Schrack_Cache_Warmer::REWARM]),'Purge must be remembered even while another worker holds the lock.');$allow_lock=true;
$warm->rewarm();check(!empty($options[Schrack_Cache_Warmer::STATE]['priority_pending']),'A running full scan can accept a priority refresh.');
unset($events[Schrack_Cache_Warmer::REWARM]); $now += 30; $warm->after_purge();
check($events[Schrack_Cache_Warmer::REWARM] === $now + 270,'Repeated purges use the most recent priority refresh cooldown, including during long catalogue scans.');
$warm->tick();$state=$options[Schrack_Cache_Warmer::STATE];
check($state['product_after']===$after && $state['cursor']>$cursor && !empty($state['repeat_catalog']),'Priority refresh resumes the same catalogue page and schedules a full repeat after purge.');
for($i=0;$i<60 && 'running'===$options[Schrack_Cache_Warmer::STATE]['status'];$i++) { $warm->tick(); }
check('complete'===$options[Schrack_Cache_Warmer::STATE]['status'] && 260 === $options[Schrack_Cache_Warmer::STATE]['products_processed'],'Every product visited before a full purge is eventually revisited too.');
ajax('save',array('urls'=>home_url('/'),'enabled'=>'1','discover'=>'1')); $wpdb->last_error='database error'; $warm->tick();
check('error'===$options[Schrack_Cache_Warmer::STATE]['status'],'A failed catalogue query must not be reported as successful completion.'); $wpdb->last_error='';
// Process death retains the pending URL and watchdog, allowing a new worker to resume.
ajax('save',array('urls'=>home_url('/'),'enabled'=>'1','discover'=>'1'));
$GLOBALS['http_callback']=function() { throw new \RuntimeException('process ended'); };
try { $warm->tick(); } catch(\RuntimeException $e) {}
check(isset($events[Schrack_Cache_Warmer::TICK]) && 0 === $options[Schrack_Cache_Warmer::STATE]['cursor'],'Watchdog and pending URL survive interrupted workers.');
$GLOBALS['http_callback']=null;(new Schrack_Cache_Warmer())->tick();
check($options[Schrack_Cache_Warmer::STATE]['cursor']>0,'A new worker resumes saved progress.');
ajax('stop');$before=count($requests);$warm->tick();$warm->rewarm();
check($before===count($requests) && !$events,'Stop also disables full-catalogue and priority-refresh work.');

// All categories, including empty nested terms beyond 24/100, precede products.
$GLOBALS['category_ids']=range(100,329);$GLOBALS['category_sql']=$GLOBALS['category_args']=array();
$GLOBALS['catalog_ids']=range(100,499);$GLOBALS['stock_statuses']=array();$GLOBALS['response_factory']=null;$response=array('code'=>200,'cache'=>'hit');
ajax('save',array('urls'=>home_url('/'),'enabled'=>'1','discover'=>'1'));$before=count($requests);
for($i=0;$i<5;$i++) { $warm->tick(); }
$state=$options[Schrack_Cache_Warmer::STATE];
check(230===$state['categories_processed'] && 17===$state['products_processed'],'All 230 categories are warmed before the first product batch, beyond the old 24-category cap.');
$recent=array_slice($requests,$before);
check(count(array_filter($recent,static fn($r)=>str_contains($r[0],'/category/parent/')))===115,'Nested category canonical paths are preserved, including empty categories.');
check(str_contains($GLOBALS['category_sql'][0],'custom_term_taxonomy') && str_contains($GLOBALS['category_sql'][1],'term_id > 199') && false===$GLOBALS['category_args'][0]['hide_empty'],'Category keyset scans use actual table names and include empty terms.');
$product_cursor=$state['cursor'];$product_after=$state['product_after'];$warm->cycle();$warm->tick();$state=$options[Schrack_Cache_Warmer::STATE];
check('categories'===$state['phase'] && $state['category_resume']['cursor']===$product_cursor && $state['product_after']===$product_after,'An hourly cycle reheats categories while retaining the exact pending product queue.');
$category_after=$state['category_after'];$warm->cycle();$warm->rewarm();$warm->tick();$state=$options[Schrack_Cache_Warmer::STATE];
check($state['category_after']===$category_after && $state['category_resume']['cursor']===$product_cursor,'A priority purge during category refresh preserves both category and product progress.');
for($i=0;$i<50 && 'running'===$options[Schrack_Cache_Warmer::STATE]['status'];$i++) { $warm->tick(); }
$state=$options[Schrack_Cache_Warmer::STATE];
check('complete'===$state['status'] && 690===$state['categories_processed'] && 800===$state['products_processed'],'Hourly category refresh and a full purge cover every category/product without skipping paused URLs.');
$GLOBALS['category_ids']=array();$GLOBALS['catalog_ids']=array();

// Both manual queues and already-saved product batches obey current stock.
$GLOBALS['catalog_ids']=array(); $response=array('code'=>200,'cache'=>'hit');
$GLOBALS['stock_statuses']=array(20=>'outofstock',21=>'onbackorder');
check($warm->public_url(get_permalink(20))===get_permalink(20) && ''===$warm->public_url(get_permalink(20),true),'Public profiling eligibility is separate from in-stock warming eligibility.');
ajax('save',array('urls'=>implode("\n",array(get_permalink(20),get_permalink(21),get_permalink(22))),'enabled'=>'1'));
check(!in_array(get_permalink(20),$options[Schrack_Cache_Warmer::STATE]['urls'],true) && !in_array(get_permalink(21),$options[Schrack_Cache_Warmer::STATE]['urls'],true),'Unavailable manual selections are omitted without preventing settings saves.');
$GLOBALS['stock_statuses'][22]='outofstock';$before=count($requests);$warm->tick();
check('complete'===$options[Schrack_Cache_Warmer::STATE]['status'] && !array_filter(array_slice($requests,$before),static fn($r)=>str_contains($r[0],'/product/')),'Products sold out after queueing are skipped without HTTP requests or stopping the run.');
$GLOBALS['stock_statuses'][20]='instock';$warm->cycle();$before=count($requests);$warm->tick();
check((bool)array_filter(array_slice($requests,$before),static fn($r)=>$r[0]===get_permalink(20)),'Restocked products are included in the next automatic run.');
$GLOBALS['stock_statuses']=array();
$GLOBALS['response_factory']=function($url) { if($url===get_permalink(20)) { $GLOBALS['stock_statuses'][20]='outofstock'; return array('code'=>200,'cache'=>'miss'); } return array('code'=>200,'cache'=>'hit'); };
ajax('save',array('urls'=>get_permalink(20),'enabled'=>'1'));$before=count($requests);$warm->tick();
$state=$options[Schrack_Cache_Warmer::STATE];
check(1===count(array_filter(array_slice($requests,$before),static fn($r)=>$r[0]===get_permalink(20))) && 'complete'===$state['status'] && 'OMIS'===$state['results'][2]['cache'],'Stock loss between MISS and confirmation skips the second request and does not claim a confirmed HIT.');

echo "Cache warmer: $count checks passed.\n";
