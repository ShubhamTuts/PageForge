<?php
$wpRoot = getenv('PF_TEST_WP_ROOT');
$pluginRoot = getenv('PF_TEST_PLUGIN_ROOT');
if (!$wpRoot || !$pluginRoot) { fwrite(STDERR, 'Set PF_TEST_WP_ROOT and PF_TEST_PLUGIN_ROOT to disposable test directories.'); exit(2); }
$edition=$argv[1]??'free';
if($edition==='premium'){$GLOBALS['licensed']=true;function pageforge_fs(){return new class{function can_use_premium_code(){return $GLOBALS['licensed'];}function get_site(){return (object)['plan_id'=>38500];}function add_filter(...$x){}function is__premium_only(){}function set_basename(...$x){}};}}
require rtrim($wpRoot, '/\\').'/wp-load.php';
require_once rtrim($pluginRoot, '/\\').'/pageforge.php';
wp_set_current_user(1);
$p=\PageForge\Plugin::instance(); $p->register_cpts();
update_option('permalink_structure','/%postname%/'); global $wp_rewrite; $wp_rewrite->set_permalink_structure('/%postname%/');
function ck($name,$ok,$detail=null){echo json_encode(['test'=>$name,'pass'=>(bool)$ok,'detail'=>$detail],JSON_UNESCAPED_SLASHES)."\n"; if(!$ok)$GLOBALS['fails']++;}
$GLOBALS['fails']=0;
function meta($id,$key,$v){\PageForge\Compatibility::write_meta($id,$key,$v);}
function tpl($target='page',$slug='{Location}'){$id=wp_insert_post(['post_type'=>\PageForge\Compatibility::post_type('template'),'post_title'=>'Integration '.uniqid(),'post_content'=>'Tuition in {Location}','post_status'=>'publish']);meta($id,'post_type',$target);meta($id,'title_pattern','Maths {Location}');meta($id,'slug_pattern',$slug);return $id;}
function gen($g,...$a){$r=$g->generate_one(...$a);return is_array($r)?(int)($r['post_id']??0):$r;}
$token='run'.time();
$parent=wp_insert_post(['post_type'=>'page','post_title'=>'Maths Tuition Gurgaon','post_name'=>'maths-'.$token,'post_status'=>'publish']);
$t=tpl();meta($t,'parent_page_id',$parent);meta($t,'parent_source','static');$g=new \PageForge\Generator();
$r=gen($g,$t,['Location'=>'dlf-iv-'.$token],'publish');
ck('native Page with parent',!is_wp_error($r)&&get_post_type($r)==='page'&&(int)get_post($r)->post_parent===$parent,is_wp_error($r)?$r->get_error_message():get_permalink($r));
if(!is_wp_error($r)){$preview=$g->preview_one($t,['Location'=>'dlf-iv-'.$token],'publish');ck('preview parent and expected path agree with inserted Page',!is_wp_error($preview)&&(int)($preview['post_parent']??-1)===$parent&&($preview['expected_path']??'')===parse_url(get_permalink($r),PHP_URL_PATH),$preview['expected_path']??null);ck('hierarchical public permalink',str_contains(get_permalink($r),'/maths-'.$token.'/dlf-iv-'.$token.'/'),get_permalink($r)); ck('canonical equals actual permalink',\PageForge\Compatibility::read_meta($r,'canonical','')===get_permalink($r),\PageForge\Compatibility::read_meta($r,'canonical',''));}
$dupe=gen($g,$t,['Location'=>'dlf-iv-'.$token],'publish'); ck('same-parent duplicate safely handled',is_wp_error($dupe)||$dupe===$r,is_wp_error($dupe)?$dupe->get_error_code():$dupe);
$parent2=wp_insert_post(['post_type'=>'page','post_title'=>'Other','post_name'=>'other-'.$token,'post_status'=>'publish']);meta($t,'parent_page_id',$parent2);meta($t,'slug_pattern','dlf-iv-'.$token);$sibling=gen($g,$t,['Location'=>'dlf-iv-'.$token,'Different'=>'row'],'publish');ck('same slug under different parent allowed',!is_wp_error($sibling),is_wp_error($sibling)?$sibling->get_error_message():get_permalink($sibling));
$pt=tpl('post');$post=gen($g,$pt,['Location'=>'post-'.$token],'publish');ck('native Post visible to WP_Query',!is_wp_error($post)&&in_array($post,get_posts(['post_type'=>'post','fields'=>'ids','numberposts'=>-1])),is_wp_error($post)?$post->get_error_message():$post);
meta($t,'parent_page_id',9999999);$bad=gen($g,$t,['Location'=>'bad-'.$token],'publish');ck('invalid parent fails safely',is_wp_error($bad),is_wp_error($bad)?$bad->get_error_code():$bad);
meta($t,'parent_page_id',$parent);$sub=wp_insert_user(['user_login'=>'sub-'.$token,'role'=>'subscriber','user_pass'=>'temporary']);wp_set_current_user($sub);$denied=gen($g,$t,['Location'=>'denied-'.$token],'publish');ck('subscriber rejected',is_wp_error($denied),is_wp_error($denied)?$denied->get_error_code():$denied);wp_set_current_user(1);
$settings=\PageForge\settings_get();$settings['cpt_base']='locations-'.$token;update_option(PAGEFORGE_OPTION,$settings);$p->register_cpts();$ct=tpl(\PageForge\Compatibility::post_type('generated'));$c=gen($g,$ct,['Location'=>'cpt-'.$token],'publish');ck('custom CPT base actual permalink',!is_wp_error($c)&&str_contains(get_permalink($c),'/locations-'.$token.'/'),is_wp_error($c)?$c->get_error_message():get_permalink($c));
$other=\PageForge\Compatibility::namespace()==='pageforge'?'pf_page':'pageforge_page';wp_insert_post(['post_type'=>$other,'post_title'=>'Legacy '.$token,'post_name'=>'legacy-'.$token,'post_status'=>'publish']);$p->register_cpts();$vars=$p->resolve_generated_rewrite_request(['pageforge_generated_slug'=>'legacy-'.$token]);ck('mixed namespace resolver recognizes legacy',($vars['post_type']??'')===$other,$vars);
if($edition==='premium'){$GLOBALS['licensed']=false;$revoked=gen($g,$t,['Location'=>'revoked-static-'.$token],'publish');ck('unlicensed static insertion denied',is_wp_error($revoked)&&$revoked->get_error_code()==='premium_license_required',is_wp_error($revoked)?$revoked->get_error_code():$revoked);}
meta($t,'parent_source','dynamic');meta($t,'parent_column','Parent');$dyn=gen($g,$t,['Location'=>'dyn-'.$token,'Parent'=>'maths-'.$token],'publish');ck('unlicensed dynamic mapping refused',is_wp_error($dyn),is_wp_error($dyn)?$dyn->get_error_code():$dyn);
echo 'FAILURES='.$GLOBALS['fails']."\n";


exit($GLOBALS['fails'] ? 1 : 0);


