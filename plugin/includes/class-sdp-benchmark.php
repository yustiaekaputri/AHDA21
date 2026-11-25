<?php
if (!defined('ABSPATH')) exit;
/** Opt-in local WP-CLI benchmark. No public endpoints, cron or persistent reports. */
final class SDP_Benchmark {
    public static function allowed($env,$enabled){return $env==='local'&&$enabled===true;}
    public static function limits($low){return ['repeats'=>$low?3:10,'seconds'=>$low?15:30];}
    public static function percentile($values,$p){if(!$values)return null;sort($values,SORT_NUMERIC);return round($values[max(0,(int)ceil(count($values)*$p)-1)],3);}
    public static function measure($callback,$repeats,$deadline){
        $times=[];$queries=[];$errors=0;$counts=[];
        global $wpdb;
        for($i=0;$i<$repeats&&microtime(true)<$deadline;$i++){
            $q=(int)($wpdb->num_queries??0);$t=hrtime(true);
            try{$r=$callback();}catch(Throwable $e){$errors++;break;}
            $times[]=(hrtime(true)-$t)/1e6;$queries[]=max(0,(int)($wpdb->num_queries??0)-$q);
            if(!is_array($r)||!empty($r['error'])||!empty($wpdb->last_error)){$errors++;break;}
            $counts[]=(int)($r['total']??0);
        }
        return ['samples'=>count($times),'errors'=>$errors,'min_ms'=>$times?round(min($times),3):null,'median_ms'=>self::percentile($times,.5),'p95_ms'=>self::percentile($times,.95),'max_ms'=>$times?round(max($times),3):null,'queries'=>$queries,'result_counts'=>$counts];
    }
    public static function scenarios($raw){
        $base=SDP_Portal::state([]);$filtered=SDP_Portal::state($raw);
        $deep=$filtered;$deep['p']=100;
        return ['listing'=>$base,'filtered'=>$filtered,'page_100'=>$deep];
    }
    public static function run($raw,$repeats,$seconds,$facets=false){
        $start=microtime(true);$deadline=$start+$seconds;$out=[];$memory=memory_get_usage(true);
        foreach(self::scenarios($raw) as $label=>$state){
            if(microtime(true)>=$deadline)break;
            $out[$label]=self::measure(fn()=>SDP_Portal::results($state),$repeats,$deadline);
            if($out[$label]['errors'])break;
        }
        if($facets&&microtime(true)<$deadline&&!array_filter($out,fn($r)=>$r['errors']>0)){
            $state=SDP_Portal::state($raw);
            $out['facets']=self::measure(function()use($state){$v=SDP_Portal::options($state);return ['total'=>array_sum(array_map('count',$v))];},$repeats,$deadline);
        }
        return ['plugin'=>SDP_Directory::VERSION,'mode'=>SDP_Resource::enabled()?'low':'standard','facet_cache_enabled'=>SDP_Performance::enabled(),'requested_repeats'=>$repeats,'soft_time_limit_s'=>$seconds,'elapsed_s'=>round(microtime(true)-$start,3),'php_allocated_start_bytes'=>$memory,'php_allocated_end_bytes'=>memory_get_usage(true),'php_process_peak_bytes'=>memory_get_peak_usage(true),'cases'=>$out,'notes'=>'Repeated calls in one PHP process; not HTTP or concurrent traffic. Deadline checked between calls only. Facets may populate bounded cache; zero results are not evidence of national scale.'];
    }
    public static function cli($args,$assoc){
        if(!self::allowed(wp_get_environment_type(),defined('SDP_BENCHMARK_ENABLED')&&SDP_BENCHMARK_ENABLED===true)||!isset($assoc['yes']))WP_CLI::error('Hanya lingkungan local, SDP_BENCHMARK_ENABLED=true dan --yes. Gunakan salinan database, bukan produksi.');
        $lim=self::limits(SDP_Resource::enabled());$r=$assoc['repeats']??'1';
        if(!is_scalar($r)||!preg_match('/^[1-9][0-9]?$/D',(string)$r)||(int)$r>$lim['repeats'])WP_CLI::error('Jumlah ulangan harus 1–'.$lim['repeats']);
        $raw=[];foreach(['q','provinsi','kabupaten','kecamatan','jenjang'] as $k)if(isset($assoc[$k]))$raw['sdp_'.$k]=$assoc[$k];
        $report=self::run($raw,(int)$r,$lim['seconds'],isset($assoc['facets']));
        WP_CLI::log(wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        if(array_filter($report['cases'],fn($x)=>$x['errors']>0))WP_CLI::halt(1);
    }
}
