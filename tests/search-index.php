<?php
/** Search SQL semantics on disposable in-memory SQLite, no live database. */
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
$GLOBALS['options']=array();$GLOBALS['posts']=array();$GLOBALS['meta']=array();
function get_option($key,$default=false) { return $GLOBALS['options'][$key]??$default; }
function update_option($key,$value,$autoload=false) { $GLOBALS['options'][$key]=$value; }
function wp_next_scheduled($hook){return false;}
function wp_schedule_single_event($time,$hook){}
function update_meta_cache($type,$ids){}
function apply_filters($key,$value) { return $value; }
function get_post($id) { if (isset($GLOBALS['during_read'])) { $callback=$GLOBALS['during_read']; unset($GLOBALS['during_read']); $callback(); } return $GLOBALS['posts'][$id]??null; }
function get_post_type($id) { return $GLOBALS['posts'][$id]->post_type??false; }
function get_post_meta($id,$key,$single=false) { $values=$GLOBALS['meta'][$id][$key]??array();return $single?($values[0]??''):$values; }
class WP_Query { private array $data;public function __construct($data){$this->data=$data;}public function get($key){return $this->data[$key]??'';}public function set($key,$value){$this->data[$key]=$value;} }
class SearchDB {
 public string $prefix='custom_', $posts='custom_posts',$postmeta='custom_postmeta',$options='custom_options',$last_error='';public PDO $db;
 public function __construct(){ $this->db=new PDO('sqlite::memory:'); }
 public function esc_like($value){return addcslashes($value,'_%\\');}
 public function prepare($sql,...$params){$params=is_array($params[0]??null)?$params[0]:$params;$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$params){$v=$params[$i++];return $m[0]==='%d'?(string)(int)$v:$this->db->quote($v);},$sql);}
 private function sql($sql){return preg_replace("/LIKE ('(?:[^']|'')*')/",'$0 ESCAPE '. $this->db->quote('\\'),$sql);}
 public function get_results($sql,$mode){return $this->db->query($this->sql($sql))->fetchAll(PDO::FETCH_ASSOC);}
 public function query($sql){if(!empty($GLOBALS['fail_write'])){unset($GLOBALS['fail_write']);return false;}return $this->db->exec($sql);}
 public function get_var($sql){if(str_contains($sql,'GET_LOCK')||str_contains($sql,'RELEASE_LOCK'))return '1';$v=$this->db->query($this->sql($sql))->fetchColumn();return false===$v?null:$v;}
 public function get_col($sql){return $this->db->query($this->sql($sql))->fetchAll(PDO::FETCH_COLUMN);}
 public function replace($table,$data,$formats){$q=$this->db->prepare('INSERT OR REPLACE INTO '.$table.'('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');return $q->execute(array_values($data));}
 public function delete($table,$where,$formats){return $this->db->exec('DELETE FROM '.$table.' WHERE product_id='.(int)$where['product_id']);}
}
$wpdb=new SearchDB();
require __DIR__.'/../includes/class-schrack-search-index.php';
require __DIR__.'/../includes/class-schrack-header-search-renderer.php';
require __DIR__.'/../includes/class-schrack-product-filter-renderer.php';
$fields=array('title','excerpt','content','sku','schrack_item','schrack_ean','telesystem_item','telesystem_ean','edoc_item','edoc_ean');
$wpdb->db->exec('CREATE TABLE custom_schrack_search_documents (product_id INTEGER PRIMARY KEY,'.implode(',',array_map(fn($f)=>$f.' TEXT',$fields)).');CREATE TABLE custom_schrack_search_documents_dirty(product_id INTEGER PRIMARY KEY,revision INTEGER);CREATE TABLE custom_posts(ID INTEGER PRIMARY KEY,post_type TEXT,post_status TEXT,post_title TEXT,post_excerpt TEXT,post_content TEXT,menu_order INTEGER);CREATE TABLE custom_postmeta(post_id INTEGER,meta_key TEXT,meta_value TEXT);CREATE TABLE custom_wc_product_meta_lookup(product_id INTEGER,sku TEXT)');
$docs=array(
 1=>array('KARO 18W','A description','A full description',array('_sku'=>array('TS-12_SKU%'),'_schrack_ean'=>array('5941234567890'),'_edoc_item_number'=>array('0'))),
 2=>array('Other','karo in excerpt','Plafonieră',array('_sku'=>array('ABC'),'_telesystem_item_number'=>array('Multiple','second-value'))),
 3=>array('Draft','','',array('_sku'=>array('KARO-DRAFT'))),
 4=>array('Boundary','','',array('_sku'=>array('BOUND'),'_schrack_item_number'=>array('abc'),'_schrack_ean'=>array('def')))
);
$job=new Schrack_Search_Index();$write=new ReflectionMethod($job,'write_document');
foreach($docs as $id=>[$title,$excerpt,$content,$document_meta]) {
 $GLOBALS['posts'][$id]=(object)array('ID'=>$id,'post_type'=>'product','post_status'=>$id===3?'draft':'publish','post_title'=>$title,'post_excerpt'=>$excerpt,'post_content'=>$content);
 $GLOBALS['meta'][$id]=$document_meta;
 $q=$wpdb->db->prepare('INSERT INTO custom_posts VALUES (?,?,?,?,?,?,0)');$q->execute(array($id,'product',$id===3?'draft':'publish',$title,$excerpt,$content));
 foreach($document_meta as $key=>$values)foreach($values as $value){$q=$wpdb->db->prepare('INSERT INTO custom_postmeta VALUES (?,?,?)');$q->execute(array($id,$key,$value));}
 $q=$wpdb->db->prepare('INSERT INTO custom_wc_product_meta_lookup VALUES (?,?)');$q->execute(array($id,$document_meta['_sku'][0]));$write->invoke($job,$id);
}
$checks=0;function verify_search($ok,$message){++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($message);}
$indexed=function($term)use($wpdb){return $wpdb->get_col("SELECT custom_posts.ID FROM custom_posts".Schrack_Search_Index::join()." WHERE custom_posts.post_status='publish' AND ".Schrack_Search_Index::predicate($term).' ORDER BY ID');};
foreach(array('karo','594123','second-value','0','TS-12_SKU%','_','%','abc def','nonexistent','Plafonieră') as $term) {
 $like='%'.$wpdb->esc_like($term).'%';
 $sql=$wpdb->prepare("SELECT DISTINCT p.ID FROM custom_posts p LEFT JOIN custom_wc_product_meta_lookup l ON p.ID=l.product_id LEFT JOIN custom_postmeta m ON p.ID=m.post_id AND m.meta_key IN ('_schrack_item_number','_schrack_ean','_telesystem_item_number','_telesystem_ean','_edoc_item_number','_edoc_ean') WHERE p.post_status='publish' AND (p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR l.sku LIKE %s OR m.meta_value LIKE %s) ORDER BY p.ID",array_fill(0,5,$like));
 verify_search($indexed($term)===$wpdb->get_col($sql),'Index preserves all-field substring semantics for '.$term);
}
$GLOBALS['options'][Schrack_Search_Index::STATE]=array('ready'=>false);verify_search(!Schrack_Search_Index::ready(),'Partial builds keep native search.');
$GLOBALS['options'][Schrack_Search_Index::STATE]=array('ready'=>true);verify_search(Schrack_Search_Index::ready(),'Complete, clean index can serve search.');
foreach(array(new Schrack_Header_Search_Renderer(),new Schrack_Product_Filter_Renderer()) as $renderer){
 $query=new WP_Query(array('schrack_header_search'=>true,'schrack_header_search_term'=>'karo','schrack_product_filter_search'=>'karo'));
 $join=$renderer->query_join('',$query);$where=$renderer->query_where('',$query);
 verify_search(str_contains($join,'custom_schrack_search_documents') && !str_contains($join,'custom_postmeta'),'Complete index replaces six metadata joins.');
 verify_search(str_contains($where,'schrack_search_doc.title LIKE'),'Header and filter search use the same predicate.');
}
$wpdb->db->exec('INSERT INTO custom_schrack_search_documents_dirty VALUES (1,2)');verify_search(!Schrack_Search_Index::ready(),'Concurrent edits fence readers until refreshed.');
$GLOBALS['posts'][1]->post_status='draft';$write->invoke($job,1);verify_search(!in_array(1,$indexed('karo')),'Unpublished documents are removed.');
verify_search(Schrack_Search_Index::fuzzy_ids(array('karo','sec'),10)===array(2),'Fuzzy candidates retain native ordering and draft exclusion.');
$GLOBALS['options'][Schrack_Search_Index::STATE]=array('ready'=>false,'cursor'=>0,'upper'=>4,'processed'=>0,'status'=>'running');
$GLOBALS['during_read']=function() use ($wpdb){$wpdb->db->exec('UPDATE custom_schrack_search_documents_dirty SET revision=revision+1 WHERE product_id=1');};
$job->tick(); verify_search(!Schrack_Search_Index::ready() && (int)$wpdb->get_var('SELECT revision FROM custom_schrack_search_documents_dirty WHERE product_id=1')===3,'A concurrent edit survives the dirty-row deletion fence.');
$job->tick(); verify_search(Schrack_Search_Index::ready() && get_option(Schrack_Search_Index::STATE)['processed']===4,'A resumed worker finishes its saved cursor and dirty queue before enabling search.');
$GLOBALS['fail_write']=true;$job->dirty(2);
verify_search(!Schrack_Search_Index::ready() && !empty(get_option(Schrack_Search_Index::STATE)['needs_rebuild']),'A lost dirty mark disables the index and requires rebuilding.');
$job->tick();verify_search(!Schrack_Search_Index::ready() && get_option(Schrack_Search_Index::STATE)['status']==='error','An empty dirty queue cannot enable a stale index after a failed mark.');
echo "Search index: {$checks} checks passed.\n";
