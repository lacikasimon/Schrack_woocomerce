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
	echo "Cache invalidation: $checks checks passed.\n";
}
