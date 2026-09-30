<?php
/** Isolated aggregate-cache lifecycle checks; no WordPress database required. */
define('ABSPATH', __DIR__);
define('SCHRACK_WC_SYNC_VERSION', 'test');
$GLOBALS['options'] = $GLOBALS['transients'] = $GLOBALS['hooks'] = array();
$GLOBALS['admin'] = $GLOBALS['ajax'] = $GLOBALS['cold'] = false;
$GLOBALS['enabled'] = true;
$GLOBALS['locale'] = 'ro_RO';
function add_action($hook, $callback, $priority=10, $args=1): void { $GLOBALS['hooks'][$hook][] = array($callback,$args); }
function fire($hook, ...$args): void { foreach ($GLOBALS['hooks'][$hook] ?? array() as [$cb,$accepted]) { $cb(...array_slice($args,0,$accepted)); } }
function update_option($key,$value,$autoload=false): void { $GLOBALS['options'][$key]=$value; }
function add_option($key,$value,$deprecated='', $autoload=false): void { $GLOBALS['options'][$key] ??= $value; }
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function wp_generate_uuid4(): string { static $n=0; return 'generation-'.++$n; }
function wp_cache_delete($key,$group): void {}
function get_transient($key) { return $GLOBALS['transients'][$key]['value'] ?? false; }
function set_transient($key,$value,$ttl): void { $GLOBALS['transients'][$key]=array('value'=>$value,'ttl'=>$ttl); }
function is_admin(): bool { return $GLOBALS['admin']; }
function wp_doing_ajax(): bool { return $GLOBALS['ajax']; }
function apply_filters($hook,$value): bool { return $GLOBALS['enabled']; }
function get_locale(): string { return $GLOBALS['locale']; }
class Schrack_Page_Profile { public static function cold_selections(): bool { return $GLOBALS['cold']; } }
require __DIR__.'/../includes/class-schrack-catalog-facet-cache.php';
$cache=new Schrack_Catalog_Facet_Cache(); $cache->init();
$checks=0; $computations=0; $stock=3;
function verify(bool $ok,string $message): void { ++$GLOBALS['checks']; if(!$ok) { throw new RuntimeException($message); } }
$compute=function() use (&$computations,&$stock): array { ++$computations; return array('count'=>$stock); };
$get=fn()=>Schrack_Catalog_Facet_Cache::remember('counts',$compute);
verify($get()===array('count'=>3) && $get()===array('count'=>3) && $computations===1,'Unchanged public requests reuse an aggregate.');
verify(reset($GLOBALS['transients'])['ttl']===120,'Aggregates have a bounded two-minute lifetime.');
foreach(array('woocommerce_product_set_stock','woocommerce_product_set_stock_status','woocommerce_update_product','woocommerce_delete_product_transients','clean_term_cache','woocommerce_attribute_updated','update_option_schrack_wc_sync_dynamic_attributes') as $hook) {
 ++$stock; fire($hook); verify($get()===array('count'=>$stock),'Mutation hook must invalidate: '.$hook);
}
foreach(array('_stock_status','_schrack_manufacturer','_schrack_product_line') as $key) {
 ++$stock; fire('updated_post_meta',1,1,$key); verify($get()===array('count'=>$stock),'Relevant metadata changes must invalidate: '.$key);
}
$before=$computations; fire('updated_post_meta',1,1,'_view_counter'); $get(); verify($computations===$before,'Unrelated metadata must not invalidate counts.');
foreach(array('product_cat','pa_color') as $taxonomy) {
 ++$stock; fire('set_object_terms',1,array(),array(),$taxonomy); verify($get()===array('count'=>$stock),'Category and attribute membership changes invalidate.');
}
++$stock; fire('clean_post_cache',1,(object)array('post_type'=>'product')); verify($get()===array('count'=>$stock),'Publication/deletion post invalidation refreshes aggregates.');
foreach(array('admin','ajax','cold') as $flag) {
 $GLOBALS[$flag]=true; $before=$computations; $get(); $get(); verify($computations===$before+2,'Bypass aggregate cache for '.$flag); $GLOBALS[$flag]=false;
}
$GLOBALS['enabled']=false; $before=$computations; $get(); $get(); verify($computations===$before+2,'The feature can be disabled.'); $GLOBALS['enabled']=true;
$GLOBALS['locale']='hu_HU'; $before=$computations; $get(); verify($computations===$before+1,'Translated labels must not cross locales.');
$GLOBALS['transients']=array();
Schrack_Catalog_Facet_Cache::remember('race',function(): array { Schrack_Catalog_Facet_Cache::invalidate(); return array('count'=>999); });
verify(empty($GLOBALS['transients']),'A mutation during computation must not publish an older snapshot.');
$before=$computations; $get(); $GLOBALS['transients']=array(); $get(); verify($computations===$before+2,'Missing or expired entries rebuild.');
Schrack_Catalog_Facet_Cache::remember('empty',fn()=>array());
verify(Schrack_Catalog_Facet_Cache::remember('empty',fn()=>array('wrong'))===array(),'Empty aggregates are valid cached values.');
echo "Catalog facet cache: {$checks} checks passed.\n";
