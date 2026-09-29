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
require __DIR__.'/../includes/class-schrack-page-profile.php';
require __DIR__.'/../includes/class-schrack-catalog-query.php';
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
require __DIR__.'/../includes/class-schrack-featured-categories-renderer.php';
$featured=new Schrack_Featured_Categories_Renderer();$grid=new ReflectionMethod($featured,'category_products');
$cache=[];$calls=[];$query_ids=[10,11];$products[10]->stock='instock';$products[11]->status='publish';
$term->term_id=7;
$gridrun=fn($sort='date')=>$grid->invoke($featured,$term,2,$sort);
check_home(count($gridrun())===2 && count($calls)===1 && $calls[0]['return']==='ids','Featured grids select IDs.');
$gridrun();check_home(count($calls)===1,'Featured category query cached across renders.');
$products[10]->stock='outofstock';$products[11]->status='draft';check_home($gridrun()===[],'Featured cached IDs still respect live stock and publication.');
$gridrun('price');check_home(count($calls)===2 && $calls[1]['order']==='ASC','Price sort uses a separate cache key.');
$term->slug='cables';$gridrun();check_home(count($calls)===3,'Each category has an independent grid cache.');
$session=true;$gridrun();check_home(count($calls)===4,'Featured cart sessions bypass shared ranking.');$session=false;
$logged=true;$gridrun();check_home(count($calls)===5,'Featured logged-in visitors bypass shared ranking.');$logged=false;
$cache=[];$query_ids=[];$gridrun();$gridrun();check_home(count($calls)===6,'Empty category grid is cached.');
$image=new ReflectionMethod($featured,'first_product_thumbnail_id');$cache=[];$query_ids=[10];$thumbnail=96;$status='publish';$before=$queries;
check_home(96===$image->invoke($featured,$term),'Featured image fallback works.');$thumbnail=97;
check_home(97===$image->invoke($featured,$term) && $queries===$before+1,'Featured thumbnail changes stay live without repeated scans.');
require __DIR__.'/../includes/class-schrack-header-renderer.php';
$header=new Schrack_Header_Renderer();$image=new ReflectionMethod($header,'first_product_thumbnail_id');
$before=$queries;check_home(97===$image->invoke($header,$term) && $queries===$before,'Header reuses the homepage category thumbnail ID cache.');
$status='draft';check_home(0===$image->invoke($header,$term),'Header immediately stops displaying a newly unpublished product image.');
$cold=new ReflectionProperty(Schrack_Page_Profile::class,'cold_selections');$cold->setValue(null,true);
$before=count($calls);$gridrun();$gridrun();check_home(count($calls)===$before+2,'Authorized cold measurement bypasses ranking IDs each time.');
$before=$queries;$image->invoke($header,$term);$image->invoke($header,$term);check_home($queries===$before+2,'Cold measurement also reselects category image IDs.');
$cold->setValue(null,false);
echo "Homepage selections: $checks checks passed.\n";
