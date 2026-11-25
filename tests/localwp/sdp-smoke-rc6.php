<?php
/** Run with wp eval-file on a DISPOSABLE, empty LocalWP site only. Not a plugin. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli') { exit(1); }
(function(){
    global $wpdb;
    $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
    $local=in_array($host,['localhost','127.0.0.1','::1','[::1]'],true)||str_ends_with($host,'.local')||str_ends_with($host,'.test');
    if(!defined('SDP_SMOKE_TEST_ENABLED')||SDP_SMOKE_TEST_ENABLED!==true||wp_get_environment_type()!=='local'||!$local||get_option('blog_public')||!defined('SDP_SAFE_MODE')||SDP_SAFE_MODE!==true||is_multisite()) WP_CLI::error('Wajib clone kosong, URL lokal, WP_ENVIRONMENT_TYPE=local, blog_public=0, SDP_SAFE_MODE=true, SDP_SMOKE_TEST_ENABLED=true; bukan multisite.');
    if(!class_exists('SDP_Directory')||!class_exists('SDP_Production')||SDP_Directory::VERSION!=='10.0.0-rc.4'||!current_user_can('manage_options')) WP_CLI::error('Aktifkan SDP 10.0 RC4 (paket RC6) dan jalankan --user=admin-lokal.');
    foreach(SDP_Production::schema() as $name=>$result) if($result['status']==='error') WP_CLI::error('Schema belum siap: '.$name);
    $table=SDP_Directory::table();
    $row=$wpdb->get_var("SELECT npsn FROM `$table` LIMIT 1");if($wpdb->last_error||$row!==null)WP_CLI::error('Tabel sekolah harus kosong.');
    $row=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s LIMIT 1",SDP_Directory::TYPE));if($wpdb->last_error||$row!==null)WP_CLI::error('Tidak boleh ada profil sekolah lama, termasuk trash.');
    $original=get_current_user_id();$old=get_option('sdp_seo_config',null);$users=[];$id=0;$npsn='TEST0001';$checks=0;$error=null;
    $check=static function($v,$msg)use(&$checks){if(!$v)throw new RuntimeException($msg);$checks++;WP_CLI::log('PASS '.$msg);};
    $api=static function($path,$params=[]) { $r=new WP_REST_Request('GET',$path);$r->set_query_params($params);return rest_do_request($r); };
    $blocked=static fn()=>new WP_Error('sdp_smoke_network','Network disabled during smoke test');
    add_filter('pre_http_request',$blocked,PHP_INT_MAX);
    try {
        $check(SDP_Scheduler::disabled(),'Safe mode scheduler');
        $check(!SDP_Resource::scheduled_network(),'Safe mode disables scheduled network');
        $check(!SDP_Performance::guard_legacy(),'Safe mode blocks legacy collector');
        foreach(['direktori_sekolah','portal_sekolah','statistik_sekolah','bandingkan_sekolah','portal_operator_sekolah','statistik_pendidikan','perbandingan_periode_pendidikan'] as $shortcode) $check(shortcode_exists($shortcode),'Shortcode registered: '.$shortcode);
        $check(wp_get_theme()->get_stylesheet()==='sekolah-directory-theme','Expected directory theme active');
        update_option('sdp_seo_config',array_merge(SDP_SEO::config(),['guard'=>1]),false);
        $d=['npsn'=>$npsn,'nama'=>'Sekolah Fiktif Smoke Test','jenjang'=>'SD','status'=>'Negeri','provinsi'=>'Provinsi Fiktif','kabupaten'=>'Kabupaten Fiktif','kecamatan'=>'Kecamatan Fiktif','alamat'=>'Alamat fiktif','jumlah_siswa'=>'10','jumlah_guru'=>'2','tahun_data'=>'2025/2026','sumber'=>'Simulasi lokal'];
        $r=SDP_Directory::save(['npsn'=>'invalid','nama'=>'Invalid'],false);$check(is_wp_error($r),'Invalid NPSN rejected');
        $r=SDP_Directory::save($d,true);$check(!is_wp_error($r)&&!empty($r['id']),'Create profile');$id=(int)$r['id'];
        $check(get_post_status($id)==='draft','Default demo publication held');
        $r=SDP_Directory::save(['npsn'=>$npsn,'jumlah_siswa'=>'11','tahun_data'=>'2025/2026'],false);$check(!is_wp_error($r)&&(int)$r['id']===$id,'Update retains post ID');
        $check($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE npsn=%s",$npsn))==1,'Unique index record');
        $reverse=SDP_Integrity::audit_posts();$check(!is_wp_error($reverse)&&$reverse['checked']===1&&$reverse['issues']===[]&&$reverse['next']===null,'Reverse audit clean');
        $d2=json_decode($wpdb->get_var($wpdb->prepare("SELECT payload FROM `$table` WHERE npsn=%s",$npsn)),true);$check(($d2['jumlah_siswa']??'')==='11'&&($d2['nama']??'')===$d['nama'],'Payload merged');
        $check(SDP_Intelligence::field_period($d2,'jumlah_siswa')==='2025/2026','Attribute period preserved');
        $r=$api('/sdp/v1/schools/'.$npsn);$check($r->get_status()===404,'Draft REST inaccessible');
        // Enable demo publication ONLY on the disposable, local, non-indexable site.
        update_option('sdp_seo_config',array_merge(SDP_SEO::config(),['guard'=>0]),false);
        $r=SDP_Directory::save(['npsn'=>$npsn],true);$check(!is_wp_error($r)&&get_post_status($id)==='publish','Local test publication');
        $check(current_user_can('edit_post',$id),'Admin may edit');
        foreach(['subscriber','editor'] as $role){$u=wp_insert_user(['user_login'=>'sdp_smoke_'.wp_generate_password(12,false,false),'user_pass'=>wp_generate_password(32),'role'=>$role]);$check(!is_wp_error($u),'Create temporary '.$role);$users[]=(int)$u;wp_set_current_user((int)$u);$check(!current_user_can('edit_post',$id)&&!current_user_can('delete_post',$id),$role.' cannot edit/delete school');$req=new WP_REST_Request('POST','/wp/v2/sdp_school/'.$id);$req->set_param('title','Unauthorized smoke attempt');$r=rest_do_request($req);$check(in_array($r->get_status(),[401,403],true),$role.' cannot update via core REST');wp_set_current_user($original);}
        wp_set_current_user(0);
        $r=$api('/sdp/v1/schools/'.$npsn);$check($r->get_status()===200&&($r->get_data()['npsn']??'')===$npsn,'Anonymous public REST detail');
        $r=$api('/sdp/v1/schools',['q'=>$npsn]);$check($r->get_status()===200&&count($r->get_data()['items']??[])===1,'Anonymous REST search');
        $state=SDP_Portal::state(['sdp_q'=>$npsn]);$r=SDP_Portal::results($state);$check(empty($r['error'])&&$r['total']===1,'Portal search');
        $options=SDP_Portal::options(SDP_Portal::state([]));$check(in_array($d['provinsi'],$options['provinsi']??[],true),'Public province facet');
        wp_set_current_user($original);$r=wp_update_post(['ID'=>$id,'post_password'=>'local-only'],true);$check(!is_wp_error($r),'Password protection set');wp_set_current_user(0);
        $r=SDP_Portal::results($state);$check($api('/sdp/v1/schools/'.$npsn)->get_status()===404&&empty($r['error'])&&$r['total']===0,'Password protection hides REST and portal');
        wp_set_current_user($original);$r=wp_update_post(['ID'=>$id,'post_password'=>'','post_status'=>'private'],true);$check(!is_wp_error($r),'Set private');wp_set_current_user(0);
        $r=SDP_Portal::results($state);$check($api('/sdp/v1/schools/'.$npsn)->get_status()===404&&empty($r['error'])&&$r['total']===0,'Private profile hidden');
        $options=SDP_Portal::options(SDP_Portal::state([]));$check(!in_array($d['provinsi'],$options['provinsi']??[],true),'Private profile invalidates province cache');
        wp_set_current_user($original);$audit=SDP_Production::audit();$check(!is_wp_error($audit)&&$audit['checked']===1&&$audit['next']===null,'Audit reads one indexed profile');
        // Deliberately corrupt the fixture only. Restore metadata even if the assertion fails.
        update_post_meta($id,'_sdp_npsn','TEST0002');
        try {
            $bad=SDP_Directory::save(['npsn'=>$npsn,'nama'=>'Should not overwrite'],false);
            $check(is_wp_error($bad),'Mismatched NPSN rejected before write');
            $reverse=SDP_Integrity::audit_posts();
            $check(!is_wp_error($reverse)&&in_array('post_index_npsn_mismatch',$reverse['issues'][0]['flags']??[],true),'Reverse audit detects NPSN mismatch');
        } finally { update_post_meta($id,'_sdp_npsn',$npsn); }
    }catch(Throwable $e){$error=$e->getMessage();}
    finally{
        wp_set_current_user($original);
        if($id&&get_post_meta($id,'_sdp_npsn',true)===$npsn)wp_delete_post($id,true);
        require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid)wp_delete_user($uid);
        if($old===null)delete_option('sdp_seo_config');else update_option('sdp_seo_config',$old,false);
        remove_filter('pre_http_request',$blocked,PHP_INT_MAX);
    }
    $left=$wpdb->get_var($wpdb->prepare("SELECT npsn FROM `$table` WHERE npsn=%s",$npsn));if($wpdb->last_error||$left!==null)$error=($error?$error.'; ':'').'Cleanup index failed';
    $remaining=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s LIMIT 1",SDP_Directory::TYPE));
    if($wpdb->last_error||$remaining!==null)$error=($error?$error.'; ':'').'School post cleanup failed';
    foreach($users as $uid)if(get_userdata($uid))$error=($error?$error.'; ':'').'Temporary user cleanup failed';
    if($error!==null)WP_CLI::error('FAIL after '.$checks.' checks: '.$error.'. Discard clone or inspect remaining test artifacts.');
    WP_CLI::success($checks.' integration checks; fixture post, index and users removed. Discard clone after testing. Not a production readiness certification.');
})();
