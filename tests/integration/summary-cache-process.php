<?php
// Verify cache persistence and invalidation in separate WP-CLI processes.
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli')exit(1);
if(getenv('GITHUB_ACTIONS')!=='true'||wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'||get_option('blog_public')||!defined('SDP_SAFE_MODE')||SDP_SAFE_MODE!==true||!defined('SDP_SMOKE_TEST_ENABLED')||SDP_SMOKE_TEST_ENABLED!==true||!current_user_can('manage_options')||is_multisite())WP_CLI::error('Disposable CI required');
global $wpdb;
$t=SDP_Directory::table();
$ids=$wpdb->get_col("SELECT npsn FROM $t ORDER BY npsn");
if($wpdb->last_error||$ids!==['TEST0001','TEST0002','TEST0003','TEST0005'])WP_CLI::error('Expected exact fixture IDs');
if(SDP_Performance::summary_enabled())WP_CLI::error('Expected default off');
define('SDP_SUMMARY_CACHE',true);
$phase=$args[0]??'';
$id=(int)$wpdb->get_var("SELECT post_id FROM $t WHERE npsn='TEST0001'");
if(!$id||$wpdb->last_error)WP_CLI::error('Missing fixture');
if($phase==='prime'){
    delete_transient(SDP_Performance::SUMMARY_KEY);
}elseif($phase==='hide'||$phase==='restore'){
    $r=wp_update_post(['ID'=>$id,'post_status'=>$phase==='hide'?'draft':'publish','post_password'=>''],true);
    if(is_wp_error($r)||$r!==$id)WP_CLI::error('Fixture mutation failed');
    WP_CLI::success('Separate writer '.$phase);return;
}elseif($phase==='cleanup'){
    wp_update_post(['ID'=>$id,'post_status'=>'publish','post_password'=>''],true);
    delete_transient(SDP_Performance::SUMMARY_KEY);
    return;
}elseif(!in_array($phase,['hit','hidden','restored'],true))WP_CLI::error('Invalid phase');
$queries=0;
$observe=static function($sql)use(&$queries){if(strpos($sql,"SELECT COUNT(*) total, SUM(s.status='Negeri')")===0)$queries++;return $sql;};
add_filter('query',$observe);
$r=SDP_Portal::results(SDP_Portal::state([]));
remove_filter('query',$observe);
$want=$phase==='hidden'?1:2;
if(!empty($r['error'])||$r['total']!==$want||count($r['rows'])!==$want)WP_CLI::error('Wrong result '.$phase);
if(in_array('TEST0001',array_column($r['rows'],'npsn'),true)!==($phase!=='hidden'))WP_CLI::error('Wrong visibility '.$phase);
if($queries!==($phase==='hit'?0:1))WP_CLI::error('Unexpected aggregate SQL count '.$phase.': '.$queries);
WP_CLI::success($phase.': expected count '.$want.', aggregate SQL queries '.$queries);
