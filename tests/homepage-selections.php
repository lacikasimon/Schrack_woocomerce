<?php
/** Homepage selection cache: no network or WordPress database. */
define( 'ABSPATH', __DIR__ ); define('MINUTE_IN_SECONDS',60);
$cache=[];$calls=[];$query_ids=[10,11];$logged=false;$session=false;$queries=0;$thumbnail=90;$status='publish';
function __( $s, $d = '' ) { return $s; }
function sanitize_title( $s ) { return $s; }
function absint( $i ) { return abs((int)$i); }
function wp_json_encode( $v ) { return json_encode($v); }
function determine_locale() { return 'ro_RO'; }
function is_user_logged_in() { return $GLOBALS['logged']; }
function get_transient( $k ) { return $GLOBALS['cache'][$k] ?? false; }
function set_transient( $k,$v,$ttl ) { if(600!==$ttl)throw new RuntimeException('Unexpected TTL');$GLOBALS['cache'][$k]=$v; }
function WC() { return (object)['session'=>new class { public function has_session() { return $GLOBALS['session']; } }]; }
class WP_Term { public int $term_id=7; public string $slug='lights'; public string $name='Lights'; }
class WC_Product {
	public bool $visible=true;public string $stock='instock'; public string $status='publish'; public float $price=10;
	public function __construct(public int $id) {}
	public function is_visible(){return $this->visible;} public function is_in_stock(){return $this->stock==='instock';} public function get_stock_status(){return $this->stock;} public function get_status(){return $this->status;}
}
$products=[10=>new WC_Product(10),11=>new WC_Product(11)];
function wc_get_products($args){$GLOBALS['calls'][]=$args;return $GLOBALS['query_ids'];}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}
function get_posts($args){$GLOBALS['queries']++;return $GLOBALS['query_ids'];}
function get_post_thumbnail_id($id){return $GLOBALS['thumbnail'];}
function get_post_status($id){return $GLOBALS['status'];}
require __DIR__.'/../includes/class-schrack-homepage-renderer.php';
$renderer=new Schrack_Homepage_Renderer();$recommended=new ReflectionMethod($renderer,'recommended_products');$image=new ReflectionMethod($renderer,'first_product_thumbnail_id');$checks=0;
function check_home($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;}
$run=fn($limit=2)=>$recommended->invoke($renderer,[],['recommended_product_limit'=>$limit]);
check_home(count($run())===2 && count($calls)===1 && $calls[0]['return']==='ids','Cold ranking selects IDs only.');
$products[10]->price=35;$run();check_home(count($calls)===1 && $products[10]->price===35.0,'Warm selection does not repeat catalog query or cache price objects.');
$products[10]->stock='outofstock';check_home(count($run())===1,'Fresh stock hides stale cached selection.');
$products[11]->visible=false;check_home($run()===[],'Fresh catalog visibility remains authoritative.');
$products[11]->visible=true;$products[11]->status='draft';check_home($run()===[],'New drafts are never served from ID cache.');
$before=count($calls);$logged=true;$run();check_home(count($calls)>$before,'Logged-in shoppers bypass ranking cache.');
$logged=false;$session=true;$before=count($calls);$run();check_home(count($calls)>$before,'Cart sessions bypass ranking cache.');$session=false;
$before=count($calls);$run(0);check_home(count($calls)===$before,'Disabled recommendations do no catalog work.');
$cache=[];$query_ids=[];$run();$before=count($calls);$run();check_home(count($calls)===$before,'Empty rankings are cached, including fallback.');
$query_ids=[10];$term=new WP_Term();check_home(90===$image->invoke($renderer,$term) && 1===$queries,'Cold category image selects one product.');
$thumbnail=91;check_home(91===$image->invoke($renderer,$term) && 1===$queries,'New thumbnail is read immediately without rerunning catalog scan.');
$status='draft';check_home(0===$image->invoke($renderer,$term),'Unpublished product stops supplying category image.');
$cache=[];$query_ids=[];$before=$queries;$image->invoke($renderer,$term);$image->invoke($renderer,$term);check_home($queries===$before+1,'Missing thumbnails are negatively cached.');
$term->term_id=8;$image->invoke($renderer,$term);check_home($queries===$before+2,'Categories have independent selection caches.');
echo "Homepage selections: $checks checks passed.\n";
