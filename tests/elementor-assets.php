<?php
namespace Elementor\Core\Base\Elements_Iteration_Actions { class Assets {const ASSETS_META_KEY='_elementor_page_assets';} }
namespace Elementor\Core\Files\CSS { class Post {function __construct(private int $id){}function enqueue(){ $GLOBALS['events'][]='css:'.$this->id; }} }
namespace Elementor {
 class Plugin {
  public object $editor,$preview,$assets_loader,$frontend;
  public function __construct(){
   $this->editor=new class {function is_edit_mode(){return $GLOBALS['editing'];}};
   $this->preview=new class {function is_preview_mode(){return $GLOBALS['preview'];}};
   $this->assets_loader=new class {function enable_assets($assets){$GLOBALS['events'][]='assets:'.json_encode($assets);}};
   $this->frontend=new class {function enqueue_styles(){$GLOBALS['events'][]='frontend';}};
  }
  static function instance(){static $instance;return $instance??=new self();}
 }
}
namespace ElementorPro\Modules\ThemeBuilder {
 class Module {
  public object $manager,$conditions;
  public function __construct(){
   $this->manager=new class {function get_locations(){return array('header'=>array('multiple'=>false),'single'=>array('multiple'=>false),'footer'=>array('multiple'=>true));}function enqueue_styles(){} };
   $this->conditions=new class {function get_location_templates($where){$GLOBALS['condition_checks'][]=$where;return $GLOBALS['locations'][$where]??array();}};
  }
  static function instance(){static $instance;return $instance??=new self();}
  function get_locations_manager(){return $this->manager;}function get_conditions_manager(){return $this->conditions;}
 }
}
namespace {
 define('ABSPATH',__DIR__);define('ELEMENTOR_VERSION','4.2.4');define('ELEMENTOR_PRO_VERSION','4.2.3');
 $GLOBALS['editing']=$GLOBALS['preview']=$GLOBALS['admin']=false;$GLOBALS['template']='';$GLOBALS['post_type']='product';$GLOBALS['events']=array();$GLOBALS['condition_checks']=array();
 $GLOBALS['locations']=array('header'=>array(10=>1,11=>2),'single'=>array(20=>1),'footer'=>array(30=>1,31=>2));$GLOBALS['meta']=array();
 foreach(array(10,11,20,30,31) as $id)$GLOBALS['meta'][$id]=array('_elementor_css'=>array('status'=>'file'),'_elementor_page_assets'=>$id===20?array():array('styles'=>array('widget-'.$id)));
 function is_admin(){return $GLOBALS['admin'];}function is_preview(){return $GLOBALS['preview'];}function is_front_page(){return false;}function is_shop(){return false;}function is_product(){return true;}function is_product_taxonomy(){return false;}
 function get_the_ID(){return 1;}function get_queried_object_id(){return 1;}function get_post_type($id){return $GLOBALS['post_type'];}function get_page_template_slug($id){return $GLOBALS['template'];}
 function apply_filters($hook,$value){return $value;}function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['meta'][$id]??array());}function get_post_meta($id,$key,$single){return $GLOBALS['meta'][$id][$key]??'';}
 function has_action($hook,$callback){return $GLOBALS['original']?10:false;}function remove_action($hook,$callback,$priority){$GLOBALS['original']=false;}function add_action($hook,$callback,$priority=10){$GLOBALS['replacement']=$callback;}function do_action($hook,$id){$GLOBALS['events'][]='render:'.$id;}
 require __DIR__.'/../includes/class-schrack-elementor-assets.php';
 $checks=0;function verify_assets($ok,$why){++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException($why);}
 $job=new \Schrack_Elementor_Assets();$GLOBALS['original']=true;$job->prepare();
 verify_assets(!$GLOBALS['original'],'Saved assets replace the native preparation callback.');($GLOBALS['replacement'])();
 verify_assets(array('header','single','footer')===$GLOBALS['condition_checks'],'Location conditions remain live per request.');
 verify_assets(array_values(array_filter($GLOBALS['events'],fn($e)=>str_starts_with($e,'css:')))===array('css:10','css:20','css:30','css:31'),'Single locations choose only their first template; multiple locations retain all CSS in order.');
 verify_assets(in_array('render:20',$GLOBALS['events']) && array_search('frontend',$GLOBALS['events'])<array_search('css:10',$GLOBALS['events']),'Render hooks and frontend style order are preserved even with empty saved assets.');
 foreach(array('editing','preview','admin') as $flag){$GLOBALS[$flag]=true;$GLOBALS['original']=true;$job->prepare();verify_assets($GLOBALS['original'],'Native loading is retained for '.$flag);$GLOBALS[$flag]=false;}
 foreach(array('elementor_canvas','elementor_header_footer','custom.php') as $template){$GLOBALS['template']=$template;$GLOBALS['original']=true;$job->prepare();verify_assets($GLOBALS['original'],'Page template exclusions keep native preparation.');}$GLOBALS['template']='';
 $GLOBALS['original']=true;$_GET['theme_template_id']='10';$job->prepare();verify_assets($GLOBALS['original'],'Forced previews keep native loading.');$_GET=array();
 $GLOBALS['original']=true;unset($GLOBALS['meta'][30]['_elementor_css']);$job->prepare();verify_assets($GLOBALS['original'],'A missing saved CSS record retains native preparation for the entire plan.');
 echo "Elementor assets: {$checks} checks passed.\n";
}
