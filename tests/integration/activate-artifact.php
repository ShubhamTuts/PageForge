<?php
/** Activate an extracted artifact installed under the disposable site plugins directory. */
$wpRoot=getenv('PF_TEST_WP_ROOT');$relative=$argv[1]??'';
if(!$wpRoot||!$relative||str_contains($relative,'..'))exit(2);
require rtrim($wpRoot,'/\\').'/wp-load.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
wp_set_current_user(1);if($relative==='--deactivate'){deactivate_plugins(get_option('active_plugins',[]));echo 'Deactivated disposable plugins.'.PHP_EOL;exit(0);}
if(get_option('active_plugins',[])){fwrite(STDERR,'Deactivate all plugins in a separate PHP process first.');exit(2);}
$r=activate_plugin($relative);
if(is_wp_error($r)){fwrite(STDERR,$r->get_error_message());exit(1);}echo json_encode(['activated'=>$relative,'active'=>is_plugin_active($relative)]).PHP_EOL;

