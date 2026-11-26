<?php
// Backup/restore acceptance on an isolated fixture database only.
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli')exit(1);
if(getenv('GITHUB_ACTIONS')!=='true'||wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'||get_option('blog_public')||!defined('SDP_SAFE_MODE')||SDP_SAFE_MODE!==true||!defined('SDP_SMOKE_TEST_ENABLED')||SDP_SMOKE_TEST_ENABLED!==true||!current_user_can('manage_options')||is_multisite()||DB_NAME!=='sdp_test'||DB_USER!=='sdp_test'||DB_HOST!=='127.0.0.1:'.getenv('SDP_DB_PORT'))WP_CLI::error('Disposable CI required');
global $wpdb;
$t=SDP_Directory::table();
$rows=$wpdb->get_results("SELECT * FROM $t ORDER BY npsn",ARRAY_A);
if($wpdb->last_error||!is_array($rows)||array_column($rows,'npsn')!==['TEST0001','TEST0002','TEST0003','TEST0005'])WP_CLI::error('Expected exact four fixtures');
$mode=$args[0]??'';
if($mode==='mutate'){
    if(get_option('sdp_ci_restore_marker',false)!==false)WP_CLI::error('Unexpected restore marker');
    $id=(int)$rows[0]['post_id'];
    $r=wp_update_post(['ID'=>$id,'post_status'=>'draft','post_title'=>'RESTORE TEST DEMO FIKTIF'],true);
    if(is_wp_error($r)||$r!==$id||get_post_status($id)!=='draft')WP_CLI::error('Mutation failed');
    update_option('sdp_ci_restore_marker','mutation',false);
    $q=SDP_Portal::results(SDP_Portal::state([]));
    if(!empty($q['error'])||$q['total']!==1||get_option('sdp_ci_restore_marker')!=='mutation')WP_CLI::error('Mutation not observable');
    WP_CLI::success('Controlled fixture mutation observed');
    return;
}
if($mode!=='snapshot')WP_CLI::error('Unknown phase');
if(get_option('sdp_ci_restore_marker',false)!==false)WP_CLI::error('Unexpected marker remains');
$profiles=[];
foreach($rows as $row){
    $id=(int)$row['post_id'];$post=get_post($id);
    $expected=in_array($row['npsn'],['TEST0001','TEST0002'],true)?'publish':'draft';
    if(!$post||$post->post_status!==$expected||$post->post_password!==''||get_post_meta($id,'_sdp_npsn',true)!==$row['npsn'])WP_CLI::error('Invalid linkage or visibility');
    $meta=$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id",$id),ARRAY_A);
    if($wpdb->last_error||!is_array($meta))WP_CLI::error('Metadata read failed');
    $profiles[]=['npsn'=>$row['npsn'],'post_id'=>$id,'index_sha256'=>hash('sha256',wp_json_encode($row)),'post_sha256'=>hash('sha256',wp_json_encode($post->to_array())),'meta_sha256'=>hash('sha256',wp_json_encode($meta))];
}
$q=SDP_Portal::results(SDP_Portal::state([]));
$audit=SDP_Integrity::audit_posts();
if(!empty($q['error'])||$q['total']!==2||count($q['rows'])!==2||is_wp_error($audit)||$audit['checked']!==4||$audit['issues']!==[]||$audit['next']!==null)WP_CLI::error('Directory or integrity failed');
$old=get_current_user_id();wp_set_current_user(0);
foreach(['TEST0003','TEST0005'] as $n){$r=rest_do_request(new WP_REST_Request('GET','/sdp/v1/schools/'.$n));if($r->get_status()!==404)WP_CLI::error('Draft leaked');}
wp_set_current_user($old);
WP_CLI::log(wp_json_encode(['profiles'=>$profiles,'public_total'=>$q['total'],'theme'=>get_stylesheet(),'marker_absent'=>true,'draft_rest_hidden'=>true],JSON_PRETTY_PRINT));
