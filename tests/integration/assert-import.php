<?php
// Called only by WP-CLI against a freshly seeded disposable preview.
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI !== 'cli') exit(1);
if (wp_get_environment_type()!=='local' || wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1' || get_option('blog_public') || !defined('SDP_SAFE_MODE') || SDP_SAFE_MODE!==true || !defined('SDP_SMOKE_TEST_ENABLED') || SDP_SMOKE_TEST_ENABLED!==true || !current_user_can('manage_options') || is_multisite()) WP_CLI::error('Disposable test site required');
global $wpdb;
$check=static function($ok,$message){if(!$ok)WP_CLI::error($message);WP_CLI::log('PASS: '.$message);};
$rows=$wpdb->get_results('SELECT npsn,post_id,payload FROM '.SDP_Directory::table().' ORDER BY npsn',ARRAY_A);
$check(is_array($rows)&&!$wpdb->last_error,'School index readable');
$by=array_column($rows,null,'npsn');
$phase=$args[0]??'';
$expected=['TEST0001','TEST0002'];
if(in_array($phase,['first','complete','updated','converted','final'],true))$expected[]='TEST0003';
if($phase==='final')$expected[]='TEST0005';
$check(array_keys($by)===$expected,'Exact fixture index membership: '.$phase);
foreach($rows as $r){
    $check(get_post_meta((int)$r['post_id'],'_sdp_npsn',true)===$r['npsn'],'Index/post link '.$r['npsn']);
    $check(get_post_status((int)$r['post_id'])===(in_array($r['npsn'],['TEST0001','TEST0002'],true)?'publish':'draft'),'Expected visibility '.$r['npsn']);
}
if(in_array($phase,['first','complete','updated','converted','final'],true)){
    $data=json_decode($by['TEST0003']['payload'],true);
    $state=getenv('SDP_IMPORT_FIXTURES').'/post-id.txt';
    if($phase==='first')$check(file_put_contents($state,(string)$by['TEST0003']['post_id'])!==false,'Record original post ID');
    else $check((string)$by['TEST0003']['post_id']===trim((string)file_get_contents($state)),'NPSN update preserves post ID');
    $check(($data['nama']??'')==='SD IMPOR DEMO FIKTIF','Expected CSV school name');
    if(in_array($phase,['updated','converted','final'],true))$check(($data['alamat']??'')==='Alamat Demo Baru','Fill policy adds previously empty address');
}
if($phase==='converted'){
    $dir=getenv('SDP_IMPORT_FIXTURES');
    $check(SDP_Excel::available(),'ZipArchive and XMLReader available');
    $csv=SDP_Excel::convert($dir.'/valid.xlsx');
    $check(is_string($csv),'Valid XLSX converts to CSV');
    $check(file_put_contents($dir.'/converted.csv',$csv)!==false,'Write converted test CSV');
    $bad=SDP_Excel::convert($dir.'/formula.xlsx');
    $check(is_wp_error($bad)&&stripos($bad->get_error_message(),'Formula')!==false,'XLSX formula rejected');
    $check(is_wp_error(SDP_Excel::convert($dir.'/valid.xlsx',2)),'Missing worksheet rejected');
    $check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.SDP_Directory::table())===3&&!$wpdb->last_error,'Conversion does not modify school index');
}
if($phase==='final'){
    $data=json_decode($by['TEST0005']['payload'],true);
    $check(($data['nama']??'')==='SMP XLSX DEMO FIKTIF','Converted XLSX school imported');
    $audit=SDP_Integrity::audit_posts();
    $check(!is_wp_error($audit)&&$audit['checked']===4&&$audit['issues']===[]&&$audit['next']===null,'Reverse integrity audit clean');
    $old=get_current_user_id();wp_set_current_user(0);
    foreach(['TEST0003','TEST0005'] as $npsn){
        $r=rest_do_request(new WP_REST_Request('GET','/sdp/v1/schools/'.$npsn));
        $check($r->get_status()===404,'Imported draft hidden from anonymous REST: '.$npsn);
    }
    wp_set_current_user($old);
}
