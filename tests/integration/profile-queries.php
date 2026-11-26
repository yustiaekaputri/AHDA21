<?php
// Read-only SQL diagnostics or explicitly requested index build, disposable CI only.
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli') exit(1);
if (getenv('GITHUB_ACTIONS')!=='true'||wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'||get_option('blog_public')||!defined('SDP_SAFE_MODE')||SDP_SAFE_MODE!==true||!defined('SDP_SMOKE_TEST_ENABLED')||SDP_SMOKE_TEST_ENABLED!==true||!current_user_can('manage_options')||is_multisite()) WP_CLI::error('Disposable CI required');
global $wpdb;
$t=SDP_Directory::table();
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")!==10004||$wpdb->last_error) WP_CLI::error('Expected 10004 isolated fixtures');
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE npsn NOT LIKE 'BENC%' AND npsn NOT IN ('TEST0001','TEST0002','TEST0003','TEST0005')")!==0||$wpdb->last_error) WP_CLI::error('Non-fixture data detected');
$phase=$args[0]??'';
if(!in_array($phase,['baseline','indexed'],true)) WP_CLI::error('Unexpected phase');
$added=[];
if($phase==='indexed'){
    $started=hrtime(true);
    $added=SDP_National::build_indexes();
    if(is_wp_error($added)) WP_CLI::error($added->get_error_message());
    $ddl_ms=(hrtime(true)-$started)/1e6;
}
$indexes=$wpdb->get_results("SHOW INDEX FROM $t",ARRAY_A);
if(!$indexes||$wpdb->last_error) WP_CLI::error('Cannot inspect indexes');
if($phase==='indexed'&&array_diff(array_keys(SDP_National::indexes()),array_column($indexes,'Key_name'))) WP_CLI::error('Required optional indexes missing');
$plans=[];$samples=[];$checks=[];
foreach(['listing'=>[],'region'=>['sdp_provinsi'=>'Provinsi Benchmark 0'],'search'=>['sdp_q'=>'BENC9999'],'page_100'=>['sdp_p'=>100]] as $name=>$input){
    $state=SDP_Portal::state($input);
    [$from,$bind]=SDP_Portal::from($state);
    $prepare=static fn($sql,$params)=>$params?$wpdb->prepare($sql,$params):$sql;
    $summary=$prepare("SELECT COUNT(*) total, SUM(s.status='Negeri') negeri, SUM(s.status='Swasta') swasta".$from,$bind);
    $list=$prepare('SELECT s.npsn,s.post_id,s.nama,s.jenjang,s.status,s.akreditasi,s.provinsi,s.kabupaten,s.kecamatan,s.payload'.$from.' ORDER BY s.nama,s.npsn LIMIT %d OFFSET %d',array_merge($bind,[SDP_Portal::LIMIT,($state['p']-1)*SDP_Portal::LIMIT]));
    $sqls=['summary'=>$summary,'listing'=>$list];
    // Reproduce public facet queries, including the region-dependent child facet.
    foreach(SDP_Portal::FILTERS as $key=>$label){
        $parents=$key==='kabupaten'?['provinsi']:($key==='kecamatan'?['provinsi','kabupaten']:[]);
        $required=$key==='kabupaten'?'provinsi':($key==='kecamatan'?'kabupaten':'');
        if($required&&empty($state[$required]))continue;
        $facet=$state;$facet['q']='';
        [$ff,$fb]=SDP_Portal::from($facet,$parents);
        $sqls['facet_'.$key]=$prepare("SELECT DISTINCT s.$key".$ff." AND s.$key<>'' ORDER BY s.$key LIMIT 1000",$fb);
    }
    foreach($sqls as $label=>$sql){
        $plan=$wpdb->get_results('EXPLAIN '.$sql,ARRAY_A);
        if(!is_array($plan)||!$plan||$wpdb->last_error) WP_CLI::error('EXPLAIN failed: '.$name.'/'.$label);
        $plans[$name][$label]=$plan;
        $times=[];$signature=null;
        for($i=0;$i<7;$i++){
            $begin=hrtime(true);
            $rows=$wpdb->get_results($sql,ARRAY_A);
            $ms=(hrtime(true)-$begin)/1e6;
            if(!is_array($rows)||$wpdb->last_error) WP_CLI::error('Query failed: '.$name.'/'.$label);
            $hash=hash('sha256',wp_json_encode($rows));
            if($signature!==null&&$hash!==$signature) WP_CLI::error('Unstable query results: '.$name.'/'.$label);
            $signature=$hash;$times[]=$ms;
        }
        $samples[$name][$label]=['median_ms'=>SDP_Benchmark::percentile($times,.5),'p95_ms'=>SDP_Benchmark::percentile($times,.95),'samples'=>count($times),'rows'=>count($rows),'result_sha256'=>$signature];
    }
    $r=SDP_Portal::results($state);
    if(!empty($r['error'])) WP_CLI::error('Portal failed: '.$name);
    $checks[$name]=['total'=>$r['total'],'page'=>$r['page'],'ids'=>array_column($r['rows'],'npsn')];
}
$report=['phase'=>$phase,'added_indexes'=>$added,'ddl_ms'=>$ddl_ms??null,'indexes'=>$indexes,'plans'=>$plans,'queries'=>$samples,'portal'=>$checks,'php_peak_bytes'=>memory_get_peak_usage(true),'note'=>'Seven direct SQL samples each, warm repeated queries; optional index DDL on disposable CI only. Not production capacity.'];
WP_CLI::log(wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
