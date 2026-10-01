<?php
/** Uses WordPress hooks from a source tree; never bootstraps a database. */
namespace LiteSpeed {
	class Core { const VER = '7.9.1'; }
	class Purge {
		public static int $full = 0;
		public static array $tags = array();
		public static function purge_all() { self::$full++; }
		public static function add( $tag ) { self::$tags[] = $tag; }
	}
}
namespace {
	$wordpress = $argv[1] ?? '';
	if ( ! is_file( $wordpress . '/wp-includes/plugin.php' ) ) { fwrite( STDERR, "Usage: php tests/cache-invalidation.php /path/to/wordpress-source\n" ); exit(1); }
	define( 'ABSPATH', $wordpress . '/' );
	require ABSPATH . 'wp-includes/plugin.php';
	require __DIR__ . '/../includes/class-schrack-cache-invalidation.php';
	$checks=0;
	function check_purge( $ok, $message ) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
	$cloud = false; $optout = false;
	add_filter('litespeed_conf',static function($key) use (&$cloud) { return $cloud; });
	add_filter('schrack_wc_sync_preserve_catalog_technical_cache',static function($v) use (&$optout) { return !$optout; });
	add_action('litespeed_purge','LiteSpeed\\Purge::add');
	add_action('litespeed_purge_all','LiteSpeed\\Purge::purge_all');
	foreach (array('create_term','edit_terms','delete_term') as $hook) { add_action($hook,'LiteSpeed\\Purge::purge_all'); }
	$guard=new Schrack_Cache_Invalidation();
	$cloud=true; $guard->configure(); check_purge(10===has_action('create_term','LiteSpeed\\Purge::purge_all'),'Cloudflare retains original full invalidation.');
	$cloud=false; $optout=true; $guard->configure(); check_purge(10===has_action('create_term','LiteSpeed\\Purge::purge_all'),'Opt-out retains original callbacks.');
	$optout=false; $guard->configure();
	foreach(array('create_term','edit_terms','delete_term') as $hook) { check_purge(false===has_action($hook,'LiteSpeed\\Purge::purge_all') && 10===has_action($hook,array($guard,'term_changed')),'Only known callback replaced.'); }
	do_action('create_term',12,15,'pa_size'); do_action('delete_term',14,16,'product_cat');
	check_purge(0===LiteSpeed\Purge::$full && array('*')===LiteSpeed\Purge::$tags,'Catalog burst purges HTML once, retaining all technical caches.');
	do_action('edit_terms',12,'category'); check_purge(1===LiteSpeed\Purge::$full,'Unrelated taxonomy retains full purge.');
	do_action('litespeed_purge_all'); check_purge(2===LiteSpeed\Purge::$full,'Manual purge still purges everything.');
	remove_all_actions('edit_terms'); add_action('edit_terms','LiteSpeed\\Purge::purge_all');
	$new=new Schrack_Cache_Invalidation();$new->configure();do_action('edit_terms',12,'pa_color');
	check_purge(array('*','*')===LiteSpeed\Purge::$tags && 2===LiteSpeed\Purge::$full,'edit_terms uses its two-argument signature.');
	remove_all_actions('create_term'); (new Schrack_Cache_Invalidation())->configure();
	check_purge(false===has_action('create_term'),'A user-disabled purge hook is not reenabled.');

	define('SCHRACK_WC_SYNC_VERSION','0.1.135');
	$options = array(); $cache_deletes = array(); $rewarmed = 0; $release_optout = false;
	function get_option($key) { return $GLOBALS['options'][$key] ?? false; }
	function update_option($key,$value,$autoload=null) { $GLOBALS['options'][$key]=$value; check_purge(false===$autoload,'Release marker does not inflate autoloaded options.'); }
	function wp_cache_delete($key,$group) { $GLOBALS['cache_deletes'][]=array($key,$group); }
	$wpdb = new class {
		public string $options='custom_shop_options';
		public array $calls=array();
		public bool $busy=false;
		public bool $completed_elsewhere=false;
		public function prepare($sql,$name) { return str_replace('%s',"'".$name."'",$sql); }
		public function get_var($sql) {
			$this->calls[]=$sql;
			if(str_contains($sql,'GET_LOCK')) {
				if($this->completed_elsewhere) { $GLOBALS['options']['schrack_frontend_cache_version']=SCHRACK_WC_SYNC_VERSION; }
				return $this->busy ? '0' : '1';
			}
			return '1';
		}
	};
	add_filter('schrack_wc_sync_purge_deployed_html',static function($v) use (&$release_optout) { return !$release_optout; });
	add_action('schrack_catalog_pages_purged',static function() use (&$rewarmed) { ++$rewarmed; });
	$release_optout=true; $guard->deployed_version_changed();
	check_purge(!$options && !$wpdb->calls,'Opt-out does not write state or acquire locks.');
	$release_optout=false; $wpdb->busy=true; $guard->deployed_version_changed();
	check_purge(!$options,'Busy release lock leaves deployment pending.');
	$wpdb->busy=false; $before=count(LiteSpeed\Purge::$tags); $full=LiteSpeed\Purge::$full;
	$guard->deployed_version_changed();
	check_purge(count(LiteSpeed\Purge::$tags)===$before+1 && end(LiteSpeed\Purge::$tags)==='*' && LiteSpeed\Purge::$full===$full && 1===$rewarmed,'New Git version purges only HTML and wakes the bounded warmer once.');
	$lock_calls=count($wpdb->calls);
	for($i=0;$i<100;$i++) { $guard->deployed_version_changed(); }
	check_purge($lock_calls===count($wpdb->calls) && 1===$rewarmed,'Normal requests perform no locking, repeated purge or warmer restart.');
	$options['schrack_frontend_cache_version']='previous'; $wpdb->completed_elsewhere=true;
	$guard->deployed_version_changed();
	check_purge(1===$rewarmed && str_contains(end($wpdb->calls),'RELEASE_LOCK'),'Concurrent release completion is re-read and the lock is released.');
	$wpdb->completed_elsewhere=false; $options['schrack_frontend_cache_version']='0.1.136';
	$guard->deployed_version_changed();
	check_purge(2===$rewarmed,'Git rollback refreshes stale HTML too.');
	$options['schrack_frontend_cache_version']='previous';
	$throw=static function() { throw new RuntimeException('Simulated interruption'); };
	add_action('schrack_catalog_pages_purged',$throw);
	try { $guard->deployed_version_changed(); } catch(RuntimeException $e) {}
	check_purge('previous'===$options['schrack_frontend_cache_version'] && str_contains(end($wpdb->calls),'RELEASE_LOCK'),'Interrupted release does not mark completion and releases the connection lock.');
	remove_action('schrack_catalog_pages_purged',$throw); $guard->deployed_version_changed();
	check_purge(SCHRACK_WC_SYNC_VERSION===$options['schrack_frontend_cache_version'],'The next request completes an interrupted release.');
	foreach($cache_deletes as $deleted) { check_purge(array('schrack_frontend_cache_version','options')===$deleted,'Only the owned version option cache key is refreshed.'); }
	echo "Cache invalidation: $checks checks passed.\n";
}
