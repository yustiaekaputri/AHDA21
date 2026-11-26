<?php
// A candidate optimization; only opt in within this isolated process.
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli')exit(1);
if(getenv('GITHUB_ACTIONS')!=='true'||wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'||get_option('blog_public')||!defined('SDP_SAFE_MODE')||SDP_SAFE_MODE!==true||!defined('SDP_SMOKE_TEST_ENABLED')||SDP_SMOKE_TEST_ENABLED!==true||!current_user_can('manage_options')||is_multisite())WP_CLI::error('Disposable CI required');
global $wpdb;
$t=SDP_Directory::table();
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")!==10004||$wpdb->last_error)WP_CLI::error('Expected 10k fixtures');
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE npsn NOT LIKE 'BENC%' AND npsn NOT IN ('TEST0001','TEST0002','TEST0003','TEST0005')")!==0||$wpdb->last_error)WP_CLI::error('Non-fixture data');
$check=static function($ok,$label){if(!$ok)WP_CLI::error($label);WP_CLI::log('PASS: '.$label);};
$state=SDP_Portal::state([]);
$check(!SDP_Performance::summary_enabled(),'Default off');
$start=hrtime(true);$baseline=SDP_Portal::results($state);$uncached=(hrtime(true)-$start)/1e6;
$check(empty($baseline['error'])&&$baseline['total']===10002,'Uncached total');
define('SDP_SUMMARY_CACHE',true);
$check(SDP_Performance::summary_eligible($state),'Explicit opt-in');
foreach(['sdp_q'=>'BENC9999','sdp_provinsi'=>'Provinsi Benchmark 0','sdp_jenjang'=>'SD','sdp_status'=>'Negeri','sdp_jurusan'=>'Demo'] as $k=>$v)$check(!SDP_Performance::summary_eligible(SDP_Portal::state([$k=>$v])),'Bypass filter '.$k);
delete_transient(SDP_Performance::SUMMARY_KEY);
$start=hrtime(true);$cold=SDP_Portal::results($state);$cold_ms=(hrtime(true)-$start)/1e6;
$check($cold===$baseline,'Cold cache exact result');
$warm=[];
for($i=0;$i<5;$i++){$start=hrtime(true);$r=SDP_Portal::results($state);$warm[]=(hrtime(true)-$start)/1e6;$check($r===$baseline,'Warm exact result '.($i+1));}
$entry=get_transient(SDP_Performance::SUMMARY_KEY);
$check(is_array($entry)&&$entry['values']['total']===10002&&$entry['until']<=time()+30,'Single slot stores only aggregate with bounded TTL');
$check(array_keys($entry['values'])===['total','negeri','swasta'],'No cached profile rows');
$deep=SDP_Portal::results(SDP_Portal::state(['sdp_p'=>100]));
$check(empty($deep['error'])&&$deep['page']===100&&$deep['total']===10002&&count($deep['rows'])===20,'Cached count permits deep page, rows still fresh');
$id=(int)$wpdb->get_var("SELECT post_id FROM $t WHERE npsn='TEST0001'");
$check($id>0&&get_post_status($id)==='publish'&&get_post($id)->post_password==='','Fixture original status');
$change=static function($fields)use($id,$check){$r=wp_update_post(['ID'=>$id]+$fields,true);$check(!is_wp_error($r)&&$r===$id,'Fixture update');};
try{
    foreach([['post_status'=>'draft'],['post_status'=>'private'],['post_status'=>'publish','post_password'=>'fixture-only']] as $fields){
        $change($fields);$r=SDP_Portal::results($state);
        $check(empty($r['error'])&&$r['total']===10001&&!in_array('TEST0001',array_column($r['rows'],'npsn'),true),'Visibility mutation invalidates aggregate');
        $search=SDP_Portal::results(SDP_Portal::state(['sdp_q'=>'TEST0001']));
        $check(empty($search['error'])&&$search['total']===0,'Hidden profile never appears in exact search');
        $change(['post_status'=>'publish','post_password'=>'']);
        $check(SDP_Portal::results($state)['total']===10002,'Republish invalidates aggregate');
    }
    // Failed callback must not populate the fixed cache.
    delete_transient(SDP_Performance::SUMMARY_KEY);
    $check(SDP_Performance::summary('test-failure',static fn()=>null)===null&&get_transient(SDP_Performance::SUMMARY_KEY)===false,'Database failure not cached');
    $calls=0;$read=static function()use(&$calls){$calls++;return ['total'=>2,'negeri'=>1,'swasta'=>1];};
    SDP_Performance::summary('test-expiry',$read);SDP_Performance::summary('test-expiry',$read);
    $check($calls===1,'Warm cache bypasses summary query');
    $e=get_transient(SDP_Performance::SUMMARY_KEY);$e['until']=time()-1;set_transient(SDP_Performance::SUMMARY_KEY,$e,30);
    SDP_Performance::summary('test-expiry',$read);
    $check($calls===2,'Expired entry recomputed');
}finally{
    wp_update_post(['ID'=>$id,'post_status'=>'publish','post_password'=>''],true);
    delete_transient(SDP_Performance::SUMMARY_KEY);
}
$check(SDP_Portal::results($state)['total']===10002,'Original fixture visibility restored');
delete_transient(SDP_Performance::SUMMARY_KEY);
WP_CLI::log(wp_json_encode(['uncached_ms'=>round($uncached,3),'cold_ms'=>round($cold_ms,3),'warm_median_ms'=>SDP_Benchmark::percentile($warm,.5),'warm_p95_ms'=>SDP_Benchmark::percentile($warm,.95),'samples'=>count($warm),'note'=>'Single process, one uncached sample and five warm samples; no production guarantee'],JSON_PRETTY_PRINT));
