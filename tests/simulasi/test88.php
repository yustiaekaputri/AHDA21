<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');if(in_array('--standard',$argv,true))define('SDP_LOW_RESOURCE',false);if(in_array('--nocache',$argv,true))define('SDP_FACET_CACHE',false);
define('DB_NAME', 'sdp_test');
function wp_using_ext_object_cache(){return false;}
function wp_cache_delete($key,$group){return true;}
function is_admin(){return false;}
class WP_Error {function __construct(public $code,public $message){}function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}}
function is_wp_error($x){return $x instanceof WP_Error;}function add_action(...$a){}function add_filter(...$a){}function add_shortcode(...$a){}function register_activation_hook(...$a){}function register_deactivation_hook(...$a){}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}function update_option($k,$v,$autoload=null){$GLOBALS['opts'][$k]=$v;$GLOBALS['autoload'][$k]=$autoload;return true;}
function get_transient($k){return $GLOBALS['trans'][$k]??false;}function set_transient($k,$v,$ttl){$GLOBALS['trans'][$k]=$v;return true;}function delete_transient($k){unset($GLOBALS['trans'][$k]);return true;}function wp_generate_uuid4(){return 'epoch-'.($GLOBALS['seq']=($GLOBALS['seq']??0)+1);}
function wp_next_scheduled($h){return $GLOBALS['cron'][$h]??false;}function wp_schedule_single_event($t,$h,$a=[],$e=false){$GLOBALS['cron'][$h]=$t;return true;}function wp_clear_scheduled_hook($h,$a=[],$e=false){unset($GLOBALS['cron'][$h]);return 1;}
function is_singular($t){return ($GLOBALS['type']??'')===$t;}function has_shortcode($s,$t){return str_contains($s,'['.$t.']');}function _prime_post_caches($ids,$terms,$meta){$GLOBALS['primed']=[$ids,$terms,$meta];}
function plugins_url($file,$root){return $file;}function wp_enqueue_style($h,...$a){$GLOBALS['assets'][$h]=1;}function wp_enqueue_script($h,...$a){$GLOBALS['assets'][$h]=1;}
class FakeDB{public $prefix='wp_',$last_error='';function prepare($q,...$a){return $q;}function get_var($q){if(str_contains($q,'GET_LOCK'))return empty($GLOBALS['busy'])?1:0;if(str_contains($q,'RELEASE_LOCK'))return 1;return $GLOBALS['pending']??null;}function query($q){$GLOBALS['last_sql']=$q;return 2;}function suppress_errors($suppress=true){return false;}}
$GLOBALS['wpdb']=new FakeDB();require __DIR__.'/../../plugin/sekolah-directory-pro.php';
$c=SDP_Performance::class;$n=0;function ok($b,$s){global $n;if(!$b){fwrite(STDERR,"FAIL $s\n");exit(1);}echo "PASS $s\n";$n++;}
$calls=0;$query=function()use(&$calls){$calls++;return ['Jawa Barat'];};
ok($c::facet('sql',$query)===['Jawa Barat'],'Facet value');$c::facet('sql',$query);ok($calls===($c::enabled()?1:2),'Repeated query cache or opt-out');
if($c::enabled()){
  $c::invalidate();$c::facet('sql',$query);ok($calls===2,'Epoch invalidates cache');ok($GLOBALS['autoload'][$c::EPOCH]===false,'Epoch nonautoload');
  $c::transition('private','publish',(object)['post_type'=>'sdp_school']);$c::facet('sql',$query);ok($calls===3,'Private transition invalidates');
  $c::post_updated(1,(object)['post_type'=>'sdp_school','post_password'=>'secret'],(object)['post_type'=>'sdp_school','post_password'=>'']);$c::facet('sql',$query);ok($calls===4,'Password change invalidates');
  $c::deleted(1,(object)['post_type'=>'sdp_school']);$c::facet('sql',$query);ok($calls===5,'Deletion invalidates');
  $err=0;$bad=function()use(&$err){$err++;return null;};$c::facet('bad',$bad);$c::facet('bad',$bad);ok($err===2,'Errors not cached');
  $big=0;$huge=function()use(&$big){$big++;return [str_repeat('x',20000)];};$c::facet('big',$huge);$c::facet('big',$huge);ok($big===2,'Oversized value not cached');
  for($i=0;$i<150;$i++)$c::facet('var'.$i,fn()=>[$i]);ok(count($GLOBALS['trans'])<=32,'Fixed cache slots');
  $q1='collision-a';$epoch=get_option($c::EPOCH,'0');$slot=$c::slot(hash('sha256',$epoch.'|'.$q1));for($i=0;;$i++){if($c::slot(hash('sha256',$epoch.'|collision-'.$i))===$slot)break;}$q2='collision-'.$i;
  $c::facet($q1,fn()=>['a']);$c::facet($q2,fn()=>['b']);ok($c::facet($q1,fn()=>['a'])===['a'],'Collision never returns other values');

  $directory_slots = ['summary', 'provinsi', 'jenjang', 'status', 'akreditasi'];
  $directory_reads = 0;
  foreach ($directory_slots as $name) {
      $c::facet('public-' . $name, static function () use ($name, &$directory_reads) {
          ++$directory_reads;
          return [$name];
      }, $name);
  }
  for ($index = 0; $index < 150; ++$index) {
      $c::facet('scoped-' . $index, static fn() => [$index]);
  }
  foreach ($directory_slots as $name) {
      $value = $c::facet('public-' . $name, static function () use (&$directory_reads) {
          ++$directory_reads;
          return ['miss'];
      }, $name);
      ok($value === [$name], 'Scoped queries do not evict public ' . $name);
  }
  ok($directory_reads === 5 && count($GLOBALS['trans']) <= 32, 'Reserved public entries share the existing 32-slot limit');
  $c::invalidate();
  ok($c::facet('public-summary', static fn() => ['updated'], 'summary') === ['updated'], 'Invalidation expires reserved entries');
  $c::invalidate();
  $GLOBALS['busy'] = true;
  ok($c::facet('public-summary', static fn() => ['fallback'], 'summary') === ['fallback'], 'Summary remains available on lock timeout');
  unset($GLOBALS['busy']);
  ok($c::facet('public-summary', static fn() => ['coordinated'], 'summary') === ['coordinated'], 'Uncoordinated summary does not overwrite the cache');
}else{ok(empty($GLOBALS['trans']),'Cache opt-out writes nothing');}
$GLOBALS['wp_query']=(object)['posts'=>[(object)['post_content'=>'Normal news']]];ok(!$c::assets_needed(),'Ordinary page skips assets');SDP_Directory::assets();SDP_Portal::assets();SDP_Analytics::assets();ok(empty($GLOBALS['assets']),'No portal CSS or JS ordinary page');
$GLOBALS['wp_query']->posts[0]->post_content='[direktori_sekolah]';ok($c::assets_needed()&&$c::assets_needed('analytics'),'Directory shortcode loads assets');SDP_Analytics::assets();ok(isset($GLOBALS['assets']['sdp-directory'],$GLOBALS['assets']['sdp-portal'],$GLOBALS['assets']['sdp-analytics']),'Asset dependencies loaded');
$GLOBALS['wp_query']->posts[0]->post_content='[statistik_sekolah]';ok($c::assets_needed()&&!$c::assets_needed('analytics'),'Statistics excludes analytics');$GLOBALS['type']='sdp_school';ok($c::assets_needed('analytics'),'School profile loads assets');unset($GLOBALS['type']);$GLOBALS['assets']=[];SDP_Analytics::assets(true);ok(isset($GLOBALS['assets']['sdp-analytics']),'Dynamic fallback');
$c::prime([['post_id'=>1],['post_id'=>1],['post_id'=>2]]);ok($GLOBALS['primed']===[[0=>1,2=>2],false,false],'Bulk prime unique posts only');
$now=time();$cfg=['schedule'=>1,'url'=>'https://example.org/data.csv'];$s=['s'=>['config'=>$cfg,'last'=>$now-100]];
ok($c::next_delay(0,false,$cfg,0,$s,['status'=>'running','auto'=>1],$now)===null,'Network off ignores configured jobs');ok($c::next_delay(1,false,[],0,[],[],$now)===300,'Local queue wakes network off');ok($c::next_delay(0,true,[],0,[],[],$now)===null,'Empty idle stops');ok($c::next_delay(0,true,$cfg,$now-100,[],[],$now)===86300,'Daily next due');ok($c::next_delay(0,true,[],0,$s,[],$now)===86300,'Multi-source next due');ok($c::next_delay(0,true,[],0,[],['status'=>'running','auto'=>1],$now)===300,'Legacy engine wakes');ok($c::next_delay(0,true,[],0,[],['status'=>'paused','auto'=>1],$now)===null,'Paused engine idle');
$GLOBALS['opts']['sdp_queue_version']='2.0.0';$h=SDP_Collector::HOOK;$GLOBALS['cron'][$h]=$now+60;$c::upgrade();ok(!wp_next_scheduled($h),'Migration removes idle recurring cron');ok(get_option($c::MIGRATION)==='1','Migration checkpoint');
$GLOBALS['pending']=1;ok(SDP_Collector::pending()===1,'Pending exists');ok($c::reconcile()&&wp_next_scheduled($h)>=$now+299,'Queue wakes cron');$t=wp_next_scheduled($h);$c::reconcile();ok(wp_next_scheduled($h)===$t,'No duplicate wakeup');$GLOBALS['pending']=null;ok(SDP_Collector::pending()===0,'Empty queue');$c::reconcile();ok(!wp_next_scheduled($h),'Queue drained stops');$GLOBALS['wpdb']->last_error='broken';ok(SDP_Collector::pending()===1,'DB error fails closed');$GLOBALS['wpdb']->last_error='';
$GLOBALS['busy']=true;ok($c::reconcile()&&wp_next_scheduled($h),'Lock contention delayed wake');$GLOBALS['busy']=false;$c::reconcile();ok(!wp_next_scheduled($h),'Idle recovery cleans wake');
ok($c::purge_done()===2&&str_contains($GLOBALS['last_sql'],"status='done'")&&str_contains($GLOBALS['last_sql'],'LIMIT 500'),'Bounded successful log purge');
echo "TOTAL $n checks; mode ".(SDP_Resource::enabled()?'low':'standard').'; cache '.($c::enabled()?'on':'off')."\n";
