<?php
// Run ONLY in standalone PHP with -d disable_functions=extension_loaded.
// No WordPress or database is used by this simulation.
$case=$argv[1]??'pass';
define('ABSPATH','/mock/'); define('WP_CLI',true);
define('SDP_SAFE_MODE',$case!=='unsafe'); define('SDP_SMOKE_TEST_ENABLED',true);
function extension_loaded($ext){global $case;return !($case==='no_mysqli'&&$ext==='mysqli');}
function wp_parse_url($u,$part){return parse_url($u,$part);}
function wp_get_environment_type(){global $case;return $case==='production'?'production':'local';}
function home_url($s){return 'http://test.local/';}
function site_url($s){global $case;return $case==='external'?'https://example.org/':'http://test.local/';}
function get_bloginfo($k){return '6.8';}
function is_multisite(){return false;}
function get_option($k){global $case;return $case==='indexed'?'1':'0';}
function current_user_can($k){global $case;return $case!=='unauthorized';}
function wp_json_encode($d,$flags){return json_encode($d,$flags);}
function wp_get_theme(){return new class {function get_stylesheet(){return 'sekolah-directory-theme';}function get($key){return '7.0.0';}};}
class SDP_Directory {const VERSION='10.0.0-rc.4';const TYPE='sdp_school';static function table(){return 'wp_sdp_schools';}}
class SDP_Resource {static function enabled(){return true;}}
class SDP_Production {static function schema(){global $case; echo "SCHEMA_CALLED\n";return array_fill(0,8,['status'=>$case==='schema'?'error':'ok']);}}
class FakeDB {public $last_error='';public $posts='wp_posts';function prepare($s,...$a){return $s;}function get_var($sql){global $case;echo "TABLE_QUERY\n";if($case==='db_error')$this->last_error='error';return ($case==='nonempty'&&str_contains($sql,'npsn'))?'12345678':null;}}
$wpdb=new FakeDB();
class WP_CLI {static function log($s){echo $s,"\n";}static function error($s){echo "BLOCKED\n";exit(1);}static function success($s){echo "PASSED\n";}}
$args=$case==='missing_confirmation'?[]:['DISPOSABLE-LOCAL-ONLY'];
require __DIR__.'/sdp-run-localwp.php';
