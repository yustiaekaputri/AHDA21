<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/'); if(in_array('--standard',$argv,true))define('SDP_LOW_RESOURCE',false);
function is_admin(){return false;}
class WP_Error {function __construct(public $code,public $message){} function get_error_message(){return $this->message;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$a){$GLOBALS['hooks'][]=$a[0];} function add_filter(...$a){} function add_shortcode(...$a){} function register_activation_hook(...$a){} function register_deactivation_hook(...$a){}
function sanitize_text_field($s){return trim(strip_tags($s));} function sanitize_textarea_field($s){return trim(strip_tags($s));} function current_time(...$a){return gmdate('Y-m-d H:i:s');}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;} function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;$GLOBALS['autoload'][$k]=$a;return true;}
function get_transient($k){return $GLOBALS['trans'][$k]??false;} function set_transient($k,$v,$ttl){$GLOBALS['trans'][$k]=$v;$GLOBALS['ttls'][$k]=$ttl;return true;} function delete_transient($k){unset($GLOBALS['trans'][$k]);return true;} function wp_generate_uuid4(){return 'test-'.($GLOBALS['seq']=($GLOBALS['seq']??0)+1);}
function wp_safe_remote_get($u,$args){$GLOBALS['requests'][]=$u;return $GLOBALS['response']??new WP_Error('net','Network unavailable');}
function wp_remote_retrieve_response_code($r){return $r['code'];} function wp_remote_retrieve_body($r){return $r['body'];}
function esc_url_raw($s,$p=[]){return $s;} function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL);} function sanitize_email($s){return $s;}
require __DIR__.'/../../plugin/sekolah-directory-pro.php';
$n=0;function ok($c,$s){global $n;if(!$c){fwrite(STDERR,"FAIL: $s\n");exit(1);}echo 'PASS: '.$s."\n";$n++;}
function clear_rate(){foreach(['sdp_reference_rate','sdp85_rate','sdp84_network_rate'] as $k)delete_transient($k);}
$limit=SDP_Resource::enabled()?10:30;
$f=SDP_Automation::filters(['provinsi'=>'Jawa Barat','kabupaten'=>'Indramayu','jenjang'=>'SD','limit'=>$limit]);
ok(SDP_Automation::cap()===$limit,'Mode cap');ok(!is_wp_error($f),'Valid filter');ok(is_wp_error(SDP_Automation::filters(['provinsi'=>'Jawa Barat','limit'=>$limit+1])),'Limit rejection');ok(is_wp_error(SDP_Automation::filters(['limit'=>1])),'Province required');ok(is_wp_error(SDP_Automation::filters(['provinsi'=>'Jawa Barat','kecamatan'=>'A','limit'=>1])),'Parent required');
$d=['npsn'=>'12345678','nama'=>'SD Contoh','jenjang'=>'SD','status'=>'Negeri','provinsi'=>'Jawa Barat','kabupaten'=>'Indramayu','jumlah_siswa'=>'0','visi'=>'Data belum tersedia'];
$needs=SDP_Automation::needs($d);ok(!in_array('jumlah_siswa',$needs['statistics'],true),'Zero not missing');ok(in_array('jumlah_guru',$needs['statistics'],true),'Missing teacher data');ok(in_array('akreditasi',$needs['accreditation'],true),'Missing accreditation');ok(in_array('visi',$needs['review'],true)&&!in_array('visi',$needs['website'],true),'Placeholder requires review');ok(!in_array('jurusan',$needs['website'],true),'Irrelevant SD fields excluded');ok(in_array('website',$needs['reference'],true),'Missing website routed to reference');ok(in_array('misi',$needs['website'],true),'Missing narrative detected');
$p=['filters'=>$f,'rows'=>[['npsn'=>'12345678'],['npsn'=>'12345679']],'next'=>'12345679'];$s=SDP_Automation::make($p);ok($s['phase']==='reference'&&$s['pos']===0,'New campaign');ok(empty($GLOBALS['requests']),'No request on campaign creation');ok(is_wp_error(SDP_Automation::make(['rows'=>[]])),'Empty rejected');$dup=$p;$dup['rows'][]=$p['rows'][0];ok(is_wp_error(SDP_Automation::make($dup)),'Duplicate rejected');$bad=$p;$bad['rows'][0]['npsn']='DEMO0001';ok(is_wp_error(SDP_Automation::make($bad)),'Demo rejected');
ok(SDP_Automation::matches($d,$f),'Matching filters');$wrong=$d;$wrong['kabupaten']='Cirebon';ok(!SDP_Automation::matches($wrong,$f),'Changed region rejected');ok(is_wp_error(SDP_Automation::step($s,$wrong,true)),'Changed region cannot process');ok(is_wp_error(SDP_Automation::step($s,$d,false)),'Consent required');ok(empty($GLOBALS['requests']),'No request before consent');
set_transient('sdp_reference_rate',1,60);ok(is_wp_error(SDP_Automation::step($s,$d,true)),'Cooldown respected');clear_rate();
$GLOBALS['response']=['code'=>429,'body'=>''];$paused=SDP_Automation::step($s,$d,true);ok($paused['status']==='paused'&&$paused['pos']===0,'HTTP 429 pauses');ok(count($GLOBALS['requests'])===1,'Only one request');ok(is_wp_error(SDP_Automation::step($paused,$d,true)),'Paused not processed');ok(SDP_Automation::transition($paused,'resume')['status']==='running','Explicit resume');clear_rate();
$GLOBALS['response']=['code'=>200,'body'=>'<table><tr><td>NPSN</td><td>87654321</td></tr><tr><td>Nama</td><td>SD Contoh</td></tr><tr><td>Bentuk Pendidikan</td><td>SD</td></tr></table>'];$r=SDP_Automation::step($s,$d,true);ok($r['status']==='paused','Wrong NPSN pauses');clear_rate();
$GLOBALS['response']=['code'=>200,'body'=>'<table><tr><td>NPSN</td><td>12345678</td></tr><tr><td>Nama</td><td>SD Contoh</td></tr><tr><td>Bentuk Pendidikan</td><td>SD</td></tr></table>'];$r=SDP_Automation::step($s,$d,true);ok($r['phase']==='website_route','Reference unchanged advances routing');clear_rate();
$r=SDP_Automation::step($r,$d,false);ok($r['phase']==='discovery','Missing website routes to discovery');$numRequests=count($GLOBALS['requests']);$r=SDP_Automation::step($r,$d,false);ok($r['phase']==='discovery'&&count($GLOBALS['requests'])===$numRequests,'Discovery handoff no API call');
$d['website']='https://sekolah.sch.id/';$r=SDP_Automation::step($r,$d,false);ok($r['phase']==='crawler','Verified URL available routes crawler');ok(is_wp_error(SDP_Automation::step($r,$d,false)),'Crawler requires consent');$r=SDP_Automation::step($r,$d,true);ok(is_array($r['crawler'])&&$r['crawler']['policy']===null,'Crawler initialized without request');ok(count($GLOBALS['requests'])===$numRequests,'No HTTP on initialization');
$expired=$r;$expired['crawler']=null;$expired['crawler_expired']=true;ok(SDP_Automation::step($expired,$d,true)['status']==='paused','Expired crawler does not restart');
$changed=$d;$changed['website']='https://sekolahlain.sch.id/';ok(is_wp_error(SDP_Automation::step($r,$changed,true)),'Changed website rejects checkpoint');
$method=new ReflectionMethod(SDP_Automation::class,'store');$method->setAccessible(true);ok($method->invoke(null,$r)===true,'Checkpoint saved');$stored=get_option(SDP_Automation::OPTION);ok($stored['crawler']===null&&isset($stored['crawler_key']),'Crawler payload not in persistent option');ok($GLOBALS['autoload'][SDP_Automation::OPTION]===false,'Checkpoint autoload disabled');ok(SDP_Automation::state()['crawler']['npsn']==='12345678','Checkpoint restored');ok($GLOBALS['ttls'][$stored['crawler_key']]<=86400,'Crawler TTL bounded');
$rev=$stored['rev'];$p2=SDP_Automation::transition(SDP_Automation::state(),'pause');ok($method->invoke(null,$p2)===true,'Pause persisted');ok(get_option(SDP_Automation::OPTION)['rev']!==$rev,'Revision changes');ok(!get_transient($stored['crawler_key']),'Old checkpoint removed');
$state=SDP_Automation::state();delete_transient($state['crawler_key']);ok(SDP_Automation::state()['crawler_expired'],'Expired transient recognized');
$r=SDP_Automation::transition($state,'skip');ok($r['pos']===1&&$r['phase']==='reference'&&$r['crawler']===null,'Skip resets crawler');$r=SDP_Automation::transition($r,'skip');ok($r['status']==='done'&&count($r['events'])===2,'Final skip completes campaign');ok(is_wp_error(SDP_Automation::transition($r,'resume')),'Completed cannot resume');ok(SDP_Automation::transition($s,'cancel')['status']==='cancelled','Cancel works');
$handoff=$s;$handoff['phase']='handoff';ok(SDP_Automation::step($handoff,$d,false)['pos']===1,'Handoff advances without network');
$review=$s;$review['phase']='review';ok(is_wp_error(SDP_Automation::step($review,$d,false)),'Review requires explicit completion');
$all=$d;foreach(SDP_Website::FIELDS as $k)$all[$k]='Terisi';ok(SDP_Automation::route_website($s,$all)['phase']==='handoff','No narrative needs no crawler');
$networkless=$all;foreach(SDP_Engine::FIELDS as $k)if(!isset($networkless[$k]))$networkless[$k]='Terisi';ok(SDP_Automation::step($s,$networkless,false)['phase']==='handoff','No need skips network');
$job=SDP_Crawler::initial($d);$job['policy']=SDP_Crawler::robots("User-agent: *\nDisallow: /private/\n");
$html='<h1>SD Contoh</h1><p>NPSN: 12345678 Indramayu</p><h2>Misi</h2><p>Meningkatkan pembelajaran.</p><a href="/private/">Sejarah</a>';
$job=SDP_Crawler::ingest($job,['url'=>$d['website'],'depth'=>0],$html,$d);
ok(isset($job['results'][$d['website']]['misi']),'Crawler extraction with real parser');
$preview=SDP_Crawler::review($job,$d,$d['website']);ok(!is_wp_error($preview)&&$preview['values']['misi']==='Meningkatkan pembelajaran.','Review handoff contains source text');
ok(is_wp_error(SDP_Crawler::review($job,$changed,$d['website'])),'Review blocked for changed website');
$add=SDP_Website::additions($preview,$d,['misi'=>1],['misi'=>'Ringkasan faktual']);ok(!is_wp_error($add)&&$add['misi']==='Ringkasan faktual','Reviewed selected field normalizes');
$filled=$d;$filled['misi']='Nilai lama';ok(is_wp_error(SDP_Website::additions($preview,$filled,['misi'=>1],['misi'=>'Baru'])),'Existing narrative not overwritten');
echo "TOTAL $n checks; mode ".(SDP_Resource::enabled()?'low':'standard')."\n";
