<?php
/**
 * Disposable local WooCommerce only: wp eval-file <this-file> reproduce|resume|edges.
 * "reproduce" demonstrates the 0.1.75 error and leaves its real saved job to resume
 * after updating the plugin. It intentionally expects failure on the old version.
 */
if ( 'http://127.0.0.1:18943' !== get_option( 'home' ) ) { throw new RuntimeException( 'Disposable local test site only.' ); }
function values_expect( $expected, $actual, string $message ): void {
 if ( $expected !== $actual ) { throw new RuntimeException( $message . ': ' . wp_json_encode( array( $expected, $actual ) ) ); }
}
function values_taxonomy( string $slug, string $label ): void {
 $id = wc_create_attribute( array( 'name'=>$label, 'slug'=>$slug ) );
 if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
 register_taxonomy( 'pa_' . $slug, array('product'), array('hierarchical'=>false) );
}
function values_term( string $tax, string $name ): int {
 $term = wp_insert_term( wp_slash( $name ), $tax );
 if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
 return (int) $term['term_id'];
}
function values_drive( $job, string $expected ): array {
 for ( $i=0; $i<100; ++$i ) {
  $state=Schrack_Attribute_Merge_Job::status();
  if ('running' !== $state['state']) { break; }
  $job->work($state['id']);
 }
 $state=Schrack_Attribute_Merge_Job::status();
 values_expect($expected,$state['state'],'Job result: '.($state['message']??''));
 return $state;
}
function values_raw( string $tax ): array {
 return array($tax=>array('name'=>$tax,'value'=>'','position'=>0,'is_visible'=>1,'is_variation'=>0,'is_taxonomy'=>1));
}
$mode=$args[0]??'edges'; $job=new Schrack_Attribute_Merge_Job();
if ('reproduce' === $mode) {
 values_taxonomy('tiplentila','Tip lentila'); values_taxonomy('tiplentila_202','Tip Lentila');
 $canonical=values_term('pa_tiplentila','Fixa'); $source=values_term('pa_tiplentila_202','fixa');
 $p=new WC_Product_Simple(); $p->set_name('42755 lens regression'); $p->set_regular_price('9.50'); $p->save();
 $id=$p->get_id(); $raw=values_raw('pa_tiplentila_202');
 update_post_meta($id,'_product_attributes',$raw); wp_set_object_terms($id,array($source),'pa_tiplentila_202');
 $job->transition('preview'); $state=values_drive($job,'ready'); $job->transition('apply',$state['id']);
 $state=Schrack_Attribute_Merge_Job::status(); $state['not_before']=time()-1; update_option(Schrack_Attribute_Merge_Job::OPTION,$state,false);
 $state=values_drive($job,'error');
 values_expect("Term value verification failed for product {$id}, pa_tiplentila.",$state['message'],'Reported live error reproduced');
 values_expect($raw,get_post_meta($id,'_product_attributes',true),'Failed product rolls back');
 values_expect(true,Schrack_Attribute_Merge_Job::blocks_imports(),'Failed job keeps imports paused');
 update_option('term_values_resume_fixture',array('id'=>$id,'job'=>$state['id'],'backup'=>$state['backup'],'canonical'=>$canonical),false);
 echo "REPRODUCED: source fixa resolves to existing Fixa, but strict verification rejects it.\n";
} elseif ('resume' === $mode) {
 $fixture=get_option('term_values_resume_fixture');
 $job->transition('resume',$fixture['job']); $state=values_drive($job,'complete');
 values_expect($fixture['job'],$state['id'],'Same job resumed');
 values_expect($fixture['backup'],$state['backup'],'Original backup retained');
 values_expect(array('Fixa'),wp_get_object_terms($fixture['id'],'pa_tiplentila',array('fields'=>'names')),'Existing canonical spelling retained');
 values_expect(array($fixture['canonical']),array_map('intval',wp_get_object_terms($fixture['id'],'pa_tiplentila',array('fields'=>'ids'))),'Original target term reused');
 values_expect(array('pa_tiplentila'),array_keys(get_post_meta($fixture['id'],'_product_attributes',true)),'Source attribute removed');
 values_expect(false,Schrack_Attribute_Merge_Job::blocks_imports(),'Imports released');
 echo "PASS: old failed checkpoint resumes with original backup, canonical Fixa and source cleanup.\n";
} elseif ('edges' === $mode) {
 values_taxonomy('reg_value','Regression value'); values_taxonomy('reg_value_2','Regression value');
 global $wpdb;
 $definitions=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name IN ('reg_value','reg_value_2')",ARRAY_A);
 $merger=new Schrack_Attribute_Merger($definitions);
 $cases=array(array('Fixa','fixa',true),array('синий','СИНИЙ',true),array('transparenta','transparentă',false),array('red green','red-green',false),array('A&B','A B',false),array('0','0',true),array('C\\D','C\\D',true),array('A, B','A, B',true));
 foreach ($cases as [$existing,$incoming,$reuse]) {
  $target=values_term('pa_reg_value',$existing); $source=values_term('pa_reg_value_2',$incoming);
  add_term_meta($target,'keep','target'); add_term_meta($source,'keep','source'); add_term_meta($source,'from_source',wp_slash($incoming));
  $merger->admin_copy_term('pa_reg_value',get_term($source,'pa_reg_value_2'));
  $p=new WC_Product_Simple(); $p->set_name($incoming); $p->save();
  $id=$p->get_id(); $raw=values_raw('pa_reg_value_2'); update_post_meta($id,'_product_attributes',$raw); wp_set_object_terms($id,array($source),'pa_reg_value_2');
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_product_attributes'",$id),ARRAY_A);
  $merger->admin_apply_row($row,$merger->admin_plan_row($row));
  $assigned=wp_get_object_terms($id,'pa_reg_value'); values_expect(1,count($assigned),'One intended value');
  values_expect($reuse?$existing:$incoming,$assigned[0]->name,'Case-only reuse, accents/punctuation preserved');
  values_expect($reuse?'target':'source',get_term_meta($assigned[0]->term_id,'keep',true),'Correct term metadata precedence');
  values_expect($incoming,get_term_meta($assigned[0]->term_id,'from_source',true),'Source metadata retained');
 }
 // After an accent variant exists, an exact spelling must win over an older collation match.
 $source=get_term_by('name','transparentă','pa_reg_value_2');
 $merger->admin_copy_term('pa_reg_value',$source);
 $exact=array_values(array_filter(get_terms(array('taxonomy'=>'pa_reg_value','hide_empty'=>false)),static fn($t)=>'transparentă'===$t->name));
 values_expect(1,count($exact),'Exact accented value is retained once');
 $p=new WC_Product_Simple(); $p->set_name('Multivalue first column'); $p->save(); $id=$p->get_id();
 $source_ids=array_map(static fn($name)=>(int)get_term_by('name',$name,'pa_reg_value_2')->term_id,array('fixa','transparentă','0'));
 update_post_meta($id,'_product_attributes',values_raw('pa_reg_value_2')); wp_set_object_terms($id,$source_ids,'pa_reg_value_2');
 $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_product_attributes'",$id),ARRAY_A);
 $merger->admin_apply_row($row,$merger->admin_plan_row($row));
 $names=wp_get_object_terms($id,'pa_reg_value',array('fields'=>'names')); sort($names,SORT_STRING);
 values_expect(array('0','Fixa','transparentă'),$names,'The entire multivalue list including zero survives');
 // A truly different read-back still fails and rolls back the product transaction.
 $p=new WC_Product_Simple(); $p->set_name('Mismatch must fail'); $p->save(); $id=$p->get_id();
 update_post_meta($id,'_product_attributes',values_raw('pa_reg_value_2')); wp_set_object_terms($id,array($source->term_id),'pa_reg_value_2');
 $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_product_attributes'",$id),ARRAY_A); $plan=$merger->admin_plan_row($row);
 $wrong=static function($terms,$ids,$taxes,$query){return in_array('pa_reg_value',(array)$taxes,true)&&'names'===($query['fields']??'')?array('wrong value'):$terms;};
 add_filter('get_object_terms',$wrong,10,4); $rejected=false;
 try {$merger->admin_apply_row($row,$plan);} catch(RuntimeException $error){$rejected=str_contains($error->getMessage(),'Term value verification failed');}
 remove_filter('get_object_terms',$wrong,10);
 values_expect(true,$rejected,'Real mismatch remains blocked');
 values_expect(values_raw('pa_reg_value_2'),get_post_meta($id,'_product_attributes',true),'Mismatch rollback preserves source data');
 echo "PASS: case, Unicode case, accents, punctuation, term metadata, exact-match preference and mismatch rollback.\n";
}
