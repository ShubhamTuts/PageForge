<?php
$GLOBALS['licensed']=true;
function pageforge_fs(){return new class{function can_use_premium_code(){return $GLOBALS['licensed'];} function get_site(){return (object)['plan_id'=>38500];} function add_filter(...$x){} function is__premium_only(){} function set_basename(...$x){}};}
$wpRoot=getenv('PF_TEST_WP_ROOT');$pluginRoot=getenv('PF_TEST_PLUGIN_ROOT');if(!$wpRoot||!$pluginRoot)exit(2);
require rtrim($wpRoot,'/\\').'/wp-load.php';require_once rtrim($pluginRoot,'/\\').'/pageforge.php';wp_set_current_user(1);$p=\PageForge\Plugin::instance();$p->register_cpts();
$GLOBALS['fails']=0;
function ck($n,$r){if(!$r)$GLOBALS['fails']++;echo json_encode(['test'=>$n,'pass'=>$r])."\n";}
function m($id,$k,$v){\PageForge\Compatibility::write_meta($id,$k,$v);}
$stamp=time();$p=wp_insert_post(['post_type'=>'page','post_name'=>'licensed-parent-'.$stamp,'post_title'=>'Parent','post_status'=>'publish']);$t=wp_insert_post(['post_type'=>\PageForge\Compatibility::post_type('template'),'post_title'=>'Dynamic','post_content'=>'Content','post_status'=>'publish']);m($t,'post_type','page');m($t,'parent_source','dynamic');m($t,'parent_column','Parent Path');m($t,'slug_pattern','{Location}');$g=new \PageForge\Generator;$r=$g->generate_one($t,['Location'=>'dynamic-child-'.$stamp,'Parent Path'=>'licensed-parent-'.$stamp],'publish');$id=is_array($r)?(int)$r['post_id']:0;ck('licensed dynamic path inserts under real WP parent',$id>0&&(int)get_post($id)->post_parent===$p);m($t,'parent_column','Parent ID');$r=$g->generate_one($t,['Location'=>'dynamic-id-'.$stamp,'Parent ID'=>(string)$p],'publish');$id=is_array($r)?(int)$r['post_id']:0;ck('licensed dynamic id inserts under real WP parent',$id>0&&(int)get_post($id)->post_parent===$p);$GLOBALS['licensed']=false;$r=$g->generate_one($t,['Location'=>'revoked-'.$stamp,'Parent ID'=>(string)$p],'publish');ck('revocation same process fails closed',is_wp_error($r)&&$r->get_error_code()==='parent_license_required');echo is_wp_error($r)?$r->get_error_message():'';

echo 'FAILURES='.$GLOBALS['fails'].PHP_EOL;exit($GLOBALS['fails']?1:0);
