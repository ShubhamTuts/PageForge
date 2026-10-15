<?php
$wpRoot=getenv('PF_TEST_WP_ROOT');$pluginRoot=getenv('PF_TEST_PLUGIN_ROOT');if(!$wpRoot||!$pluginRoot)exit(2);
require rtrim($wpRoot,'/\\').'/wp-load.php';require_once rtrim($pluginRoot,'/\\').'/pageforge.php';\PageForge\Plugin::instance()->register_cpts();flush_rewrite_rules(false);
$pages=get_posts(['post_type'=>'page','numberposts'=>1,'orderby'=>'ID','order'=>'DESC','post_parent__not_in'=>[0]]);$custom=get_posts(['post_type'=>\PageForge\Compatibility::generated_post_types(),'numberposts'=>1,'orderby'=>'ID','order'=>'DESC']);
if(!$pages||!$custom)exit(2);echo json_encode(['page'=>get_permalink($pages[0]),'cpt'=>get_permalink($custom[0])],JSON_UNESCAPED_SLASHES).PHP_EOL;
