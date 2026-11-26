<?php
// Capture exact fictional profile state before/after a local ZIP replacement.
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli')exit(1);
if(getenv('GITHUB_ACTIONS')!=='true'||wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'||get_option('blog_public')||!defined('SDP_SAFE_MODE')||SDP_SAFE_MODE!==true||!defined('SDP_SMOKE_TEST_ENABLED')||SDP_SMOKE_TEST_ENABLED!==true||!current_user_can('manage_options')||is_multisite())WP_CLI::error('Disposable CI required');
global $wpdb;
$t=SDP_Directory::table();
$rows=$wpdb->get_results("SELECT npsn,post_id,payload FROM $t ORDER BY npsn",ARRAY_A);
if($wpdb->last_error||!is_array($rows)||array_column($rows,'npsn')!==['TEST0001','TEST0002'])WP_CLI::error('Expected two seed fixtures');
if(get_stylesheet()!=='sekolah-directory-theme'||!is_plugin_active('sekolah-directory-pro/sekolah-directory-pro.php'))WP_CLI::error('Incorrect active plugin/theme');
$result=SDP_Portal::results(SDP_Portal::state([]));
if(!empty($result['error'])||$result['total']!==2||count($result['rows'])!==2)WP_CLI::error('Unexpected public directory state');
$profiles=[];
foreach($rows as $r){
    $id=(int)$r['post_id'];$post=get_post($id);
    if(!$post||$post->post_status!=='publish'||$post->post_password!==''||get_post_meta($id,'_sdp_npsn',true)!==$r['npsn'])WP_CLI::error('Fixture visibility or linkage mismatch');
    $profiles[]=['npsn'=>$r['npsn'],'post_id'=>$id,'payload_sha256'=>hash('sha256',$r['payload']),'title'=>$post->post_title,'status'=>$post->post_status,'content_sha256'=>hash('sha256',$post->post_content)];
}
if(($args[0]??'')==='candidate'&&!method_exists('SDP_Performance','summary_enabled'))WP_CLI::error('Expected candidate source, not original RC4 archive');
WP_CLI::log(wp_json_encode(['profiles'=>$profiles,'total'=>$result['total'],'theme'=>get_stylesheet()],JSON_PRETTY_PRINT));
