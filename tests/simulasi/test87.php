<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/'); if(in_array('--standard',$argv,true))define('SDP_LOW_RESOURCE',false);
function is_admin(){return false;}
class WP_Error {function __construct(public $code,public $message){} function get_error_message(){return $this->message;} function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$a){$GLOBALS['hooks'][]=$a[0];} function add_filter(...$a){} function add_shortcode(...$a){} function register_activation_hook(...$a){} function register_deactivation_hook(...$a){}
function sanitize_text_field($s){return trim(strip_tags($s));} function sanitize_textarea_field($s){return trim(strip_tags($s));} function current_time(...$a){return gmdate('Y-m-d H:i:s');}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;} function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;$GLOBALS['autoload'][$k]=$a;return true;}
function get_transient($k){return $GLOBALS['trans'][$k]??false;} function set_transient($k,$v,$ttl){$GLOBALS['trans'][$k]=$v;$GLOBALS['ttls'][$k]=$ttl;return true;} function delete_transient($k){unset($GLOBALS['trans'][$k]);return true;} function wp_generate_uuid4(){return 'test-'.($GLOBALS['seq']=($GLOBALS['seq']??0)+1);}
function wp_safe_remote_get($u,$args){$GLOBALS['requests'][]=$u;return $GLOBALS['response']??new WP_Error('net','Network unavailable');}
function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_remote_retrieve_body($r){return $r['body'];}
function esc_url_raw($s,$p=[]){return $s;} function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL);} function sanitize_email($s){return $s;}
if(in_array('--network',$argv,true))define('SDP_LOW_RESOURCE_NETWORK_CRON',true);
function wp_next_scheduled($h,$args=[]){return $GLOBALS['cron'][$h][json_encode($args)]??false;}
function wp_schedule_single_event($t,$h,$args=[],$err=false){if(!empty($GLOBALS['cron_fail']))return new WP_Error('cron','failure');$GLOBALS['cron'][$h][json_encode($args)]=$t;return true;}
function wp_clear_scheduled_hook($h,$args=[]){unset($GLOBALS['cron'][$h][json_encode($args)]);return 1;}
function wp_unschedule_hook($h){unset($GLOBALS['cron'][$h]);return 1;}
function user_can($uid,$cap){return $uid===7&&!empty($GLOBALS['allowed']);}
function wp_date($fmt,$time){return gmdate($fmt,$time);}
define('ARRAY_A','ARRAY_A');
class FakeDB {public $prefix='wp_',$posts='wp_posts',$last_error=''; function prepare($q,...$a){return $q;} function get_var($q){if(str_contains($q,'GET_LOCK'))return empty($GLOBALS['lockbusy'])?1:0;if(str_contains($q,'RELEASE_LOCK'))return 1;return $GLOBALS['pending']??0;}function get_row($q,$mode){return ['payload'=>json_encode($GLOBALS['profile']),'post_type'=>'sdp_school','post_status'=>'draft','post_password'=>''];}}
$GLOBALS['wpdb']=new FakeDB();
require __DIR__.'/../../plugin/sekolah-directory-pro.php';
$n=0;function ok($c,$s){global $n;if(!$c){fwrite(STDERR,"FAIL: $s\n");exit(1);}echo 'PASS: '.$s."\n";$n++;}
function clear_rate(){foreach(['sdp_reference_rate','sdp85_rate','sdp84_network_rate'] as $k)delete_transient($k);}
$now=time();$cap=SDP_Resource::enabled()?12:40;$cls=SDP_Scheduler::class;
$cfg=$cls::settings(['start'=>'0','end'=>'0','mode'=>'manual','budget'=>(string)$cap]);
ok($cls::cap()===$cap,'Budget preset');ok(!is_wp_error($cfg),'Settings accepted');ok(is_wp_error($cls::settings(['budget'=>$cap+1])),'Budget cap');ok(is_wp_error($cls::settings(['start'=>24])),'Invalid hour');ok(is_wp_error($cls::settings(['mode'=>'oops'])),'Invalid mode');ok(is_wp_error($cls::settings(['load'=>0])),'Invalid load');ok($cls::window(23,22,4)&&$cls::window(1,22,4)&&!$cls::window(4,22,4),'Overnight window');ok($cls::window(10,0,0),'24-hour window');ok(!$cls::window(5,1,5)&&$cls::window(1,1,5),'End exclusive');ok($cls::delay(1)===120&&$cls::delay(5)===1800,'Bounded exponential backoff');ok($cls::mem_bytes('128M')===134217728&&$cls::mem_bytes('-1')===-1&&$cls::mem_bytes('bad')===0,'Memory parsing');
$m=['load'=>0.1,'available'=>536870912,'limit'=>134217728,'used'=>8388608];ok($cls::guard($cfg,$m)==='','Healthy metrics');ok($cls::guard($cfg,array_replace($m,['load'=>20]))!=='','Load protection');ok($cls::guard($cfg,array_replace($m,['available'=>1]))!=='','System memory protection');ok($cls::guard($cfg,array_replace($m,['used'=>134217727]))!=='','PHP headroom protection');ok($cls::guard($cfg,array_replace($m,['load'=>null]))!=='','Missing metrics conservative');ok($cls::guard(array_replace($cfg,['unknown'=>true]),array_replace($m,['load'=>null]))==='','Explicit unknown opt-in');
$d=['npsn'=>'12345678','nama'=>'SD Contoh','jenjang'=>'SD','status'=>'Negeri','provinsi'=>'Jawa Barat','kabupaten'=>'Indramayu','website'=>'https://sekolah.sch.id/'];$GLOBALS['profile']=$d;$GLOBALS['allowed']=true;
$f=SDP_Automation::filters(['provinsi'=>'Jawa Barat','limit'=>1]);$c=SDP_Automation::make(['filters'=>$f,'rows'=>[['npsn'=>$d['npsn']]],'next'=>'']);$c['phase']='handoff';SDP_Automation::store($c);$c=SDP_Automation::state();
$s=$cls::build([], $c,$d,$cfg,7,$now);ok($cls::active($s)&&$s['expires']===$now+86400,'Session lease');ok($s['grant']==='','No network by default');ok($cls::store($s)&&$GLOBALS['autoload'][$cls::OPTION]===false,'Non-autoload state');ok($cls::schedule($s,60)&&empty($GLOBALS['cron']),'Manual no cron');
$before=count($GLOBALS['requests']??[]);$cls::run($s,$now,$m);ok(SDP_Automation::state()['status']==='done','Local step persisted');ok(!$cls::active($cls::state()),'Stops on completed campaign');ok(count($GLOBALS['requests']??[])===$before,'No HTTP for local step');
function campaign($phase){global $f,$d;$c=SDP_Automation::make(['filters'=>$f,'rows'=>[['npsn'=>$d['npsn']]],'next'=>'']);$c['phase']=$phase;SDP_Automation::store($c);return SDP_Automation::state();}
function session($phase,$override=[],$old=[]){global $d,$cfg,$now;$c=campaign($phase);$s=SDP_Scheduler::build($old,$c,$d,array_replace($cfg,$override),7,$now);SDP_Scheduler::store($s);return $s;}
$s=session('reference');$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'No consent stops reference');ok(count($GLOBALS['requests']??[])===$before,'No unapproved HTTP');
$s=session('review');$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'Review handoff stops');$s=session('discovery');$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'Discovery handoff stops');
$s=session('handoff');campaign('handoff');$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'Stale campaign revision stops');$s=session('handoff');$GLOBALS['allowed']=false;$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'Revoked admin stops');$GLOBALS['allowed']=true;
$s=session('handoff');$cls::run($s,$now+86400,$m);ok(!$cls::active($cls::state()),'Expired session stops');
$s=session('handoff',['mode'=>'scheduled']);ok($cls::schedule($s,60),'Single event scheduled');$t=wp_next_scheduled($cls::HOOK,[$s['token']]);ok($t>=time()+59,'Minimum schedule delay');$cls::schedule($s,300);ok(wp_next_scheduled($cls::HOOK,[$s['token']])===$t,'No duplicate event');$cls::finish($s,'stop');ok(!wp_next_scheduled($cls::HOOK,[$s['token']]),'Stop clears event');
$s=session('handoff',['mode'=>'scheduled']);$cls::run($s,$now,array_replace($m,['load'=>100]));$saved=$cls::state();ok($saved['attempt']===1&&$saved['next']>=$now+119&&$cls::active($saved),'High load defers');ok(wp_next_scheduled($cls::HOOK,[$s['token']])!==false,'Deferred event scheduled');$cls::finish($saved,'stop');
$s=session('handoff');$s['attempt']=5;$cls::run($s,$now,array_replace($m,['load'=>100]));ok(!$cls::active($cls::state()),'Retry cap stops');
$s=session('handoff');$GLOBALS['pending']=1;$cls::run($s,$now,$m);ok($cls::state()['attempt']===1,'Dataset priority');$GLOBALS['pending']=0;
$s=session('handoff');$GLOBALS['opts'][SDP_Engine::OPTION]=['status'=>'running','auto'=>true];$cls::run($s,$now,$m);ok($cls::state()['attempt']===1,'Legacy engine priority');unset($GLOBALS['opts'][SDP_Engine::OPTION]);
$h=(int)gmdate('G',$now);$s=session('handoff',['start'=>($h+1)%24,'end'=>($h+2)%24]);$cls::run($s,$now,$m);ok($cls::state()['next']===$now+3600,'Outside window sleeps');
$s=session('reference',['network'=>true],['day'=>gmdate('Y-m-d',$now),'used'=>$cap]);ok($s['used']===$cap,'Daily count survives new session');ok($cls::reset_day($s,$now+86400)['used']===0,'Daily UTC reset');$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'Budget exhaustion stops');ok(count($GLOBALS['requests']??[])===$before,'Budget no HTTP');
$s=session('reference',['network'=>true]);$GLOBALS['profile']['website']='https://other.sch.id/';$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'Changed source fingerprint stops');$GLOBALS['profile']=$d;
$s=session('reference',['network'=>true]);$GLOBALS['response']=['code'=>429,'body'=>''];$cls::run($s,$now,$m);ok(!$cls::active($cls::state()),'HTTP 429 no auto retry');if(SDP_Resource::scheduled_network()){ok($cls::state()['used']===1,'Budget reserved before request');ok(SDP_Automation::state()['status']==='paused','Source rejection pauses campaign');ok(count($GLOBALS['requests']??[])===$before+1,'One network request');}else{ok($cls::state()['used']===0&&count($GLOBALS['requests']??[])===$before,'Low mode requires network override');}clear_rate();
$s=session('handoff',['mode'=>'scheduled']);$GLOBALS['cron_fail']=true;ok(!$cls::schedule($s,60),'Scheduling failure detected');unset($GLOBALS['cron_fail']);$cls::schedule($s,60);$cls::deactivate();ok(!$cls::active($cls::state())&&!wp_next_scheduled($cls::HOOK,[$s['token']]),'Deactivation clears schedule');
$s=session('handoff',['mode'=>'scheduled']);$cls::tick('stale');ok($cls::state()['token']===$s['token']&&$cls::state()['steps']===0,'Stale callback no-op');$GLOBALS['lockbusy']=true;$cls::tick($s['token']);ok(wp_next_scheduled($cls::HOOK,[$s['token']])!==false,'Lock busy schedules delayed wakeup');$GLOBALS['lockbusy']=false;$cls::finish($s,'done');
echo "TOTAL $n checks; mode ".(SDP_Resource::enabled()?'low':'standard')."; network ".(SDP_Resource::scheduled_network()?'enabled':'disabled')."\n";
