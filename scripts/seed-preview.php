<?php
// Disposable local demonstration only. No data from real schools.
if (!defined('WP_CLI') || !WP_CLI || !current_user_can('manage_options') || wp_get_environment_type() !== 'local' || (string)get_option('blog_public') !== '0' || home_url() !== 'http://127.0.0.1:8080' || !defined('SDP_SAFE_MODE') || !SDP_SAFE_MODE) exit(1);
global $wpdb;
$table=SDP_Directory::table();
if ($wpdb->get_var("SELECT npsn FROM `$table` LIMIT 1") !== null || $wpdb->last_error) WP_CLI::error('Preview requires empty school index');
update_option('sdp_seo_config',array_merge(SDP_SEO::config(),['guard'=>0]),false);
$schools=[];
foreach (['SD','SMP','MTs'] as $i=>$level) {
    $r=SDP_Directory::save(['npsn'=>sprintf('TEST%04d',$i+1),'nama'=>"$level Fiktif DEMO ".($i+1),'jenjang'=>$level,'status'=>'Negeri','provinsi'=>'Provinsi Fiktif','kabupaten'=>'Kabupaten Fiktif','kecamatan'=>'Kecamatan Fiktif','alamat'=>'Alamat fiktif','jumlah_siswa'=>'10','jumlah_guru'=>'2','tahun_data'=>'2025/2026','sumber'=>'Simulasi lokal'],true);
    if(is_wp_error($r)||empty($r['id'])||get_post_status($r['id'])!=='publish') WP_CLI::error('Demo save/publication failed');
    $schools[]=get_permalink($r['id']);
}
$pages=[];
foreach (['direktori'=>'[direktori_sekolah]','perbandingan'=>'[bandingkan_sekolah]','statistik'=>'[statistik_pendidikan slot="1"]','operator'=>'[portal_operator_sekolah]'] as $slug=>$content) {
    $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>ucfirst($slug).' DEMO','post_name'=>$slug,'post_content'=>$content],true);
    if(is_wp_error($id)||!$id) WP_CLI::error('Demo page failed');
    $pages[$slug]=get_permalink($id);
    if($slug==='direktori') {update_option('sdp_page_id',$id); update_option('show_on_front','page'); update_option('page_on_front',$id);}
}
WP_CLI::log(wp_json_encode(['home'=>home_url('/'),'pages'=>$pages,'schools'=>$schools],JSON_UNESCAPED_SLASHES));
