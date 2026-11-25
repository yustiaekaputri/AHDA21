<?php
// Disposable local preview only, after the smoke test has passed and cleaned up.
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI !== 'cli') exit(1);
global $wpdb;
$host=wp_parse_url(home_url('/'),PHP_URL_HOST);
if (wp_get_environment_type()!=='local' || $host!=='127.0.0.1' || get_option('blog_public') || !defined('SDP_SAFE_MODE') || SDP_SAFE_MODE!==true || !defined('SDP_SMOKE_TEST_ENABLED') || SDP_SMOKE_TEST_ENABLED!==true || !current_user_can('manage_options') || !class_exists('SDP_Directory') || is_multisite()) WP_CLI::error('Disposable loopback only');
$table=SDP_Directory::table();
$existing=$wpdb->get_var("SELECT npsn FROM `$table` LIMIT 1");
if ($wpdb->last_error || $existing!==null) WP_CLI::error('School table must be empty');
$existing=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s LIMIT 1", SDP_Directory::TYPE));
if ($wpdb->last_error || $existing!==null) WP_CLI::error('School posts must be empty');
$manifest=getenv('SDP_PREVIEW_MANIFEST');
if (!$manifest || file_exists($manifest)) WP_CLI::error('Use a new manifest path outside WordPress');
update_option('sdp_seo_config',array_merge(SDP_SEO::config(),['guard'=>0]),false);
$profiles=[];
foreach (['TEST0001'=>'SD DEMO FIKTIF','TEST0002'=>'SMP DEMO FIKTIF'] as $npsn=>$name) {
    $r=SDP_Directory::save(['npsn'=>$npsn,'nama'=>$name,'jenjang'=>$npsn==='TEST0001'?'SD':'SMP','status'=>'Negeri','provinsi'=>'Provinsi Fiktif','kabupaten'=>'Kabupaten Fiktif','kecamatan'=>'Kecamatan Fiktif','alamat'=>'Alamat Fiktif','sumber'=>'DEMO FIKTIF - BUKAN DATA RESMI','jumlah_siswa'=>'10','jumlah_guru'=>'2'],true);
    if (is_wp_error($r) || empty($r['id']) || get_post_status((int)$r['id'])!=='publish') WP_CLI::error('Fixture publication failed');
    $profiles[$npsn]=get_permalink((int)$r['id']);
}
$pages=[];
foreach (['directory'=>['Direktori DEMO FIKTIF','portal_sekolah'],'compare'=>['Perbandingan DEMO FIKTIF','bandingkan_sekolah'],'statistics'=>['Statistik DEMO FIKTIF','statistik_pendidikan'],'operator'=>['Portal Operator DEMO FIKTIF','portal_operator_sekolah']] as $key=>$spec) {
    $id=wp_insert_post(['post_title'=>$spec[0],'post_type'=>'page','post_status'=>'publish','post_content'=>'<p>DEMO FIKTIF — bukan data resmi.</p> ['.$spec[1].']'],true);
    if (is_wp_error($id) || !$id) WP_CLI::error('Page creation failed: '.$key);
    if ($key==='directory') { update_option('show_on_front','page');update_option('page_on_front',$id); }
    $pages[$key]=get_permalink($id);
}
$payload=['home'=>home_url('/'),'pages'=>$pages,'profiles'=>$profiles];
if (file_put_contents($manifest,wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) WP_CLI::error('Cannot save manifest');
WP_CLI::success('Demo fixtures ready. Never deploy this database.');
