<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
if(in_array('--standard',$argv,true))define('SDP_LOW_RESOURCE',false);
class SDP_Directory {const VERSION='8.9.0';}
class SDP_Resource {static function enabled(){return !defined('SDP_LOW_RESOURCE');}}
class SDP_Performance {static function enabled(){return true;}}
class SDP_Portal {static function state($raw){return ['q'=>$raw['sdp_q']??'','p'=>1];}static function results($s){global $wpdb;$wpdb->num_queries+=2;return ['total'=>$s['q']?3:100];}static function options($s){global $wpdb;$wpdb->num_queries++;return ['region'=>['a','b']];}}
$wpdb=(object)['num_queries'=>0,'last_error'=>''];
require __DIR__.'/../../plugin/includes/class-sdp-benchmark.php';
$n=0;function ok($v,$s){global $n;if(!$v){fwrite(STDERR,"FAIL $s\n");exit(1);}echo "PASS $s\n";$n++;}
$c=SDP_Benchmark::class;
ok($c::allowed('local',true),'Explicit local');foreach(['production','staging','development'] as $env)ok(!$c::allowed($env,true),'Reject '.$env);ok(!$c::allowed('local',false),'Opt in required');ok(!$c::allowed('local',1),'Strict boolean');
ok($c::limits(true)===['repeats'=>3,'seconds'=>15],'Low limits');ok($c::limits(false)===['repeats'=>10,'seconds'=>30],'Standard limits');ok($c::percentile([],0.5)===null,'Empty percentile');ok($c::percentile([4,1,3,2],.5)===2.0,'Nearest rank median');ok($c::percentile([4,1,3,2],.95)===4.0,'Nearest rank p95');
$s=$c::scenarios(['sdp_q'=>'TEST']);ok($s['listing']['q']==='','Unfiltered');ok($s['filtered']['q']==='TEST','Filtered');ok($s['page_100']['p']===100,'Deep page');
$m=$c::measure(fn()=>['total'=>7],3,microtime(true)+2);ok($m['samples']===3&&$m['result_counts']===[7,7,7],'Samples and counts');ok($m['errors']===0&&$m['min_ms']>=0,'Valid timing');$m=$c::measure(fn()=>['error'=>true],3,microtime(true)+2);ok($m['errors']===1&&$m['samples']===1,'Error stops repeat');$m=$c::measure(fn()=>throw new Exception('bad'),3,microtime(true)+2);ok($m['errors']===1&&$m['samples']===0,'Exception stops');$m=$c::measure(fn()=>['total'=>1],3,microtime(true)-1);ok($m['samples']===0&&$m['p95_ms']===null,'Deadline');
$wpdb->last_error='bad';$m=$c::measure(fn()=>['total'=>1],3,microtime(true)+2);ok($m['errors']===1,'Database error');$wpdb->last_error='';
$r=$c::run(['sdp_q'=>'TEST'],2,3,true);ok(count($r['cases'])===4,'Four cases');ok($r['cases']['listing']['queries']===[2,2],'Query deltas');ok($r['cases']['filtered']['result_counts']===[3,3],'Counts correct');ok($r['cases']['facets']['result_counts']===[2,2],'Facets counts');ok($r['mode']===(SDP_Resource::enabled()?'low':'standard'),'Mode label');ok($r['php_process_peak_bytes']>0,'Peak memory');ok(count($c::run([],1,2,false)['cases'])===3,'Facets opt in');ok(!$c::run([],1,0,false)['cases'],'Deadline before first case');echo "TOTAL $n checks\n";
