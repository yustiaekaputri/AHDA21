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
    $r=SDP_Directory::save(['npsn'=>$npsn,'nama'=>$name,'jenjang'=>$npsn==='TEST0001'?'SD':'SMP','status'=>'Negeri','provinsi'=>$npsn==='TEST0001'?'Provinsi Fiktif A':'Provinsi Fiktif B','kabupaten'=>$npsn==='TEST0001'?'Kabupaten Fiktif A':'Kabupaten Fiktif B','kecamatan'=>$npsn==='TEST0001'?'Kecamatan Fiktif A':'Kecamatan Fiktif B','alamat'=>'Alamat Fiktif','sumber'=>'DEMO FIKTIF - BUKAN DATA RESMI','jumlah_siswa'=>'10','jumlah_guru'=>'2'],true);
    if (is_wp_error($r) || empty($r['id']) || get_post_status((int)$r['id'])!=='publish') WP_CLI::error('Fixture publication failed');
    $profiles[$npsn]=get_permalink((int)$r['id']);
}
// Verify period-sensitive arithmetic in memory. Do not store synthetic official totals.
$aggregate=SDP_Education::blank();
SDP_Education::add($aggregate,[
    'npsn'=>'TEST0001','nama'=>'DEMO FIKTIF','jenjang'=>'SD','status'=>'Negeri',
    'jumlah_siswa'=>'10','jumlah_guru'=>'2',
    '_field_sources'=>['jumlah_siswa'=>['period'=>'2025/2026'],'jumlah_guru'=>['period'=>'2025/2026']]
],'2025/2026');
if($aggregate['schools']!==1||$aggregate['sum']['jumlah_siswa']!==10||$aggregate['sum']['jumlah_guru']!==2||SDP_Education::ratio($aggregate)!=5) WP_CLI::error('Synthetic in-memory aggregate mismatch');
SDP_Education::add($aggregate,[
    'npsn'=>'TEST0002','nama'=>'DEMO FIKTIF','jenjang'=>'SMP',
    'jumlah_siswa'=>'99','jumlah_guru'=>'9',
    '_field_sources'=>['jumlah_siswa'=>['period'=>'2024/2025'],'jumlah_guru'=>['period'=>'2024/2025']]
],'2025/2026');
if($aggregate['schools']!==2||$aggregate['known']['jumlah_siswa']!==1||$aggregate['sum']['jumlah_siswa']!==10||$aggregate['ratio_n']!==1) WP_CLI::error('Wrong-period values counted');
WP_CLI::log('PASS: in-memory educational aggregate and period exclusion');
// Real stored snapshots must exclude demo profiles, exactly as in production.
foreach([1=>'2025/2026',2=>'2024/2025'] as $slot=>$period){
    $started=SDP_Education::start(['period'=>$period],$slot);
    if(is_wp_error($started)) WP_CLI::error($started->get_error_message());
    $job=SDP_Education::step();
    if(is_wp_error($job)||($job['status']??'')!=='complete'||$job['scanned']!==2||$job['demo']!==2||$job['invalid']!==0||$job['total']['schools']!==0) WP_CLI::error('Snapshot did not safely exclude the two fictional profiles');
    $stored=get_option(SDP_Education::key($slot),[]);
    if(($stored['status']??'')!=='complete'||$stored['total']['schools']!==0||$stored['filter']['period']!==$period) WP_CLI::error('Persisted snapshot mismatch');
    $fp=fopen('php://temp','w+b');
    SDP_Education::csv($fp,$stored);rewind($fp);
    $header=fgetcsv($fp,0,',','"','');$row=fgetcsv($fp,0,',','"','');
    fclose($fp);
    if(!is_array($header)||!is_array($row)||count($header)!==count($row)) WP_CLI::error('Snapshot CSV export mismatch');
    $csv=array_combine($header,$row);
    if($csv['profil']!=='0'||$csv['siswa_total']!==''||$csv['guru_total']!=='') WP_CLI::error('Snapshot export misrepresents excluded demo data');
    WP_CLI::log('PASS: real database snapshot, fixture exclusion and CSV export '.$period);
}
$compared=SDP_Education::compare_snapshots([get_option(SDP_Education::key(1)),get_option(SDP_Education::key(2))]);
if(is_wp_error($compared)||array_keys($compared)!==['2024/2025','2025/2026']) WP_CLI::error('Snapshot period comparison failed');
$pages=[];
foreach (['directory'=>['Direktori DEMO FIKTIF','portal_sekolah'],'compare'=>['Perbandingan DEMO FIKTIF','bandingkan_sekolah'],'statistics'=>['Statistik DEMO FIKTIF','statistik_pendidikan'],'periods'=>['Perbandingan Periode DEMO FIKTIF','perbandingan_periode_pendidikan slots="1,2"'],'operator'=>['Portal Operator DEMO FIKTIF','portal_operator_sekolah']] as $key=>$spec) {
    $id=wp_insert_post(['post_title'=>$spec[0],'post_type'=>'page','post_status'=>'publish','post_content'=>'<p>DEMO FIKTIF — bukan data resmi.</p> ['.$spec[1].']'],true);
    if (is_wp_error($id) || !$id) WP_CLI::error('Page creation failed: '.$key);
    if ($key==='directory') { update_option('show_on_front','page');update_option('page_on_front',$id); }
    if (in_array($key,['statistics','periods'],true)) {
        $page=get_post($id);
        $update=wp_update_post(['ID'=>$id,'post_content'=>'<p>DEMO FIKTIF — data uji dikecualikan dari agregat pendidikan; nilai kosong bukan nol.</p> '.$page->post_content],true);
        if(is_wp_error($update)) WP_CLI::error('Cannot explain fixture exclusion');
    }
    if ($key==='compare') update_option('sdp41_compare_page',$id);
    if ($key==='operator') update_option('sdp71_operator_page',$id);
    $pages[$key]=get_permalink($id);
}
$payload=['home'=>home_url('/'),'pages'=>$pages,'profiles'=>$profiles];
if (file_put_contents($manifest,wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) WP_CLI::error('Cannot save manifest');
// Optional browser credentials: temp directory only, never public artifacts.
$auth=getenv('SDP_PREVIEW_AUTH');
if ($auth) {
    $dir=realpath(dirname($auth));$root=realpath(ABSPATH);
    if (!$dir || !$root || $dir===$root || str_starts_with($dir,$root.DIRECTORY_SEPARATOR) || file_exists($auth) || is_link($auth)) WP_CLI::error('Unsafe auth file location');
    $admin=get_user_by('login','sdp_preview_admin');
    if (!$admin || !user_can($admin,'manage_options')) WP_CLI::error('Missing disposable administrator');
    $admin_pass=wp_generate_password(32,false,false);
    wp_set_password($admin_pass,$admin->ID);
    $sub_pass=wp_generate_password(32,false,false);
    $sub=wp_insert_user(['user_login'=>'sdp_preview_subscriber','user_pass'=>$sub_pass,'user_email'=>'subscriber@example.invalid','role'=>'subscriber']);
    if (is_wp_error($sub)) WP_CLI::error('Cannot create disposable subscriber');
    $old_umask=umask(0077);
    $f=fopen($auth,'x');
    if (!$f) {umask($old_umask); WP_CLI::error('Cannot create auth file');}
    $bytes=fwrite($f,wp_json_encode(['admin'=>['user'=>'sdp_preview_admin','password'=>$admin_pass],'subscriber'=>['user'=>'sdp_preview_subscriber','password'=>$sub_pass]]));
    fclose($f);umask($old_umask);
    if (!$bytes || !chmod($auth,0600)) WP_CLI::error('Cannot write private auth file');
}
WP_CLI::success('Demo fixtures ready. Never deploy this database.');
