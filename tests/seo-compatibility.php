<?php
/** SEO mapping and recovery test doubles, without WordPress or live data. */
define('ABSPATH',__DIR__);define('SITESEO_VERSION','1.4.1');
$GLOBALS['options']=array();$GLOBALS['metadata']=array(1=>array('_yoast_wpseo_primary_product_cat'=>'10'),2=>array('_yoast_wpseo_primary_product_cat'=>'20','_siteseo_robots_primary_cat'=>'99'),3=>array('_yoast_wpseo_primary_product_cat'=>'0'),4=>array('_yoast_wpseo_primary_product_cat'=>'40','_siteseo_robots_primary_cat'=>''),5=>array('_yoast_wpseo_primary_product_cat'=>'50'),6=>array('_yoast_wpseo_primary_product_cat'=>'60','_siteseo_robots_primary_cat'=>'none'));
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
function wp_cache_delete($key,$group){}
function get_post_meta($id,$key,$single){return $GLOBALS['metadata'][$id][$key]??'';}
function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['metadata'][$id]??array());}
function get_post_type($id){return $id===5?'post':'product';}
function has_term($term,$taxonomy,$id){return (string)$term===get_post_meta($id,'_yoast_wpseo_primary_product_cat',true);}
function update_post_meta($id,$key,$value,$previous=null){$GLOBALS['metadata'][$id][$key]=$value;return true;}
function delete_post_meta($id,$key,$value){if(($GLOBALS['metadata'][$id][$key]??null)!==$value)return false;unset($GLOBALS['metadata'][$id][$key]);return true;}
class SEO_DB {public string $postmeta='custom_meta',$last_error='';public function prepare($sql,...$args){return $sql;}public function get_col($sql){return array_keys($GLOBALS['metadata']);}}
$wpdb=new SEO_DB();require __DIR__.'/../includes/class-schrack-seo-compatibility.php';
$checks=0;function verify_seo($ok,$why){++$GLOBALS['checks'];if(!$ok)throw new RuntimeException($why);}
$original=$GLOBALS['metadata'];$job=new Schrack_SEO_Compatibility();$result=$job->merge();
verify_seo($result===array('imported'=>2,'preserved'=>2,'invalid'=>2),'Import only missing categories of eligible products.');
verify_seo(get_post_meta(2,'_siteseo_robots_primary_cat',true)==='99' && get_post_meta(6,'_siteseo_robots_primary_cat',true)==='none','Existing selections, including explicit none, retain priority.');
foreach($original as $id=>$row)verify_seo(get_post_meta($id,'_yoast_wpseo_primary_product_cat',true)===$row['_yoast_wpseo_primary_product_cat'],'Yoast source data stays intact.');
verify_seo($job->merge()['imported']===0,'Repeating merge is idempotent.');
$GLOBALS['metadata'][4]['_siteseo_robots_primary_cat']='41';$result=$job->restore();
verify_seo($result===array('restored'=>1,'preserved_edits'=>1),'Restore preserves later edits.');
verify_seo(!metadata_exists('post',1,'_siteseo_robots_primary_cat'),'Restore recovers a previously absent field.');
$GLOBALS['metadata'][4]['_siteseo_robots_primary_cat']='40';$job->restore();verify_seo(metadata_exists('post',4,'_siteseo_robots_primary_cat') && get_post_meta(4,'_siteseo_robots_primary_cat',true)==='','Restore distinguishes an existing empty field from an absent one.');
echo "SEO compatibility: {$checks} checks passed.\n";
