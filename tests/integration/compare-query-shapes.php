<?php
// Read-only experiment on disposable CI. No production SQL changes.
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI||PHP_SAPI!=='cli')exit(1);
if(getenv('GITHUB_ACTIONS')!=='true'||wp_get_environment_type()!=='local'||wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1'||get_option('blog_public')||!defined('SDP_SAFE_MODE')||SDP_SAFE_MODE!==true||!defined('SDP_SMOKE_TEST_ENABLED')||SDP_SMOKE_TEST_ENABLED!==true||!current_user_can('manage_options')||is_multisite())WP_CLI::error('Disposable CI required');
global $wpdb;
$t=SDP_Directory::table();$p=$wpdb->posts;
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")!==10004||$wpdb->last_error)WP_CLI::error('Expected isolated 10k fixtures');
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE npsn NOT LIKE 'BENC%' AND npsn NOT IN ('TEST0001','TEST0002','TEST0003','TEST0005')")!==0||$wpdb->last_error)WP_CLI::error('Non-fixture data detected');
$v="p.post_status='publish' AND p.post_password='' AND p.post_type=%s";
$join=" FROM $t s JOIN $p p ON p.ID=s.post_id WHERE ".$v;
$variants=['original'=>$join,'school_first'=>" FROM $t s STRAIGHT_JOIN $p p ON p.ID=s.post_id WHERE ".$v,'posts_first'=>" FROM $p p STRAIGHT_JOIN $t s ON p.ID=s.post_id WHERE ".$v,'exists'=>" FROM $t s WHERE EXISTS (SELECT 1 FROM $p p WHERE p.ID=s.post_id AND ".$v.")"];
$specs=[];
foreach(['all'=>[],'region'=>['sdp_provinsi'=>'Provinsi Benchmark 0'],'npsn'=>['sdp_q'=>'BENC9999'],'page100'=>['sdp_p'=>100]] as $label=>$raw){
    $state=SDP_Portal::state($raw);[$from,$bind]=SDP_Portal::from($state);
    $specs[$label.'_summary']=["SELECT COUNT(*) total, SUM(s.status='Negeri') negeri, SUM(s.status='Swasta') swasta".$from,$bind];
    $specs[$label.'_list']=['SELECT s.npsn,s.post_id,s.nama,s.jenjang,s.status,s.akreditasi,s.provinsi,s.kabupaten,s.kecamatan,s.payload'.$from.' ORDER BY s.nama,s.npsn LIMIT %d OFFSET %d',array_merge($bind,[SDP_Portal::LIMIT,($state['p']-1)*SDP_Portal::LIMIT])];
}
foreach(['provinsi','jenjang','status','akreditasi','kabupaten'] as $key){
    $state=SDP_Portal::state($key==='kabupaten'?['sdp_provinsi'=>'Provinsi Benchmark 0']:[]);
    [$from,$bind]=SDP_Portal::from($state,$key==='kabupaten'?['provinsi']:[]);
    $specs['facet_'.$key]=["SELECT DISTINCT s.$key".$from." AND s.$key<>'' ORDER BY s.$key LIMIT 1000",$bind];
}
$report=[];
foreach($specs as $name=>[$raw,$bind]){
    if(substr_count($raw,$join)!==1)WP_CLI::error('Production SQL shape changed: '.$name);
    $sqls=[];$plans=[];$times=[];$hash=null;
    foreach($variants as $label=>$replacement){
        $sql=$wpdb->prepare(str_replace($join,$replacement,$raw),$bind);
        $plan=$wpdb->get_results('EXPLAIN '.$sql,ARRAY_A);
        if(!is_array($plan)||!$plan||$wpdb->last_error)WP_CLI::error('EXPLAIN failed '.$name.'/'.$label);
        $sqls[$label]=$sql;$plans[$label]=$plan;$times[$label]=[];
    }
    $labels=array_keys($sqls);
    for($round=0;$round<5;$round++){
        $order=array_merge(array_slice($labels,$round%count($labels)),array_slice($labels,0,$round%count($labels)));
        foreach($order as $label){
            $start=hrtime(true);$rows=$wpdb->get_results($sqls[$label],ARRAY_A);$ms=(hrtime(true)-$start)/1e6;
            if(!is_array($rows)||$wpdb->last_error)WP_CLI::error('Query failed '.$name.'/'.$label);
            $signature=hash('sha256',wp_json_encode($rows));
            if($hash!==null&&$hash!==$signature)WP_CLI::error('Result changed '.$name.'/'.$label);
            $hash=$signature;$times[$label][]=$ms;
        }
    }
    foreach($labels as $label)$report[$name][$label]=['median_ms'=>SDP_Benchmark::percentile($times[$label],.5),'p95_ms'=>SDP_Benchmark::percentile($times[$label],.95),'samples'=>count($times[$label]),'plan'=>$plans[$label],'result_sha256'=>$hash];
}
WP_CLI::log(wp_json_encode(['cases'=>$report,'db'=>$wpdb->db_version(),'notes'=>'Five interleaved samples per case, synthetic 10k dataset; no production query or schema changes.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
