<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$args){$GLOBALS['hooks'][]=$args;}
function current_user_can($c){return $GLOBALS['admin']??false;}
function esc_url($v){return htmlspecialchars($v,ENT_QUOTES);}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}
function admin_url($v){return 'https://example.org/wp-admin/'.$v;}
require __DIR__.'/../../plugin/includes/class-sdp-source-catalog.php';
$n=0;function ok($v,$msg){global $n;if(!$v)throw new Exception($msg);echo "PASS $msg\n";$n++;}
SDP_Source_Catalog::boot();ok(count($GLOBALS['hooks'])===1&&$GLOBALS['hooks'][0][0]==='admin_menu','Only admin menu hook');$entries=SDP_Source_Catalog::entries();ok(count($entries)===8,'Eight catalog entries');foreach($entries as $e)ok(str_starts_with($e[1],'https://'),'HTTPS source '.$e[0]);ob_start();SDP_Source_Catalog::page();ok(ob_get_clean()==='','Unauthorized output blocked');$GLOBALS['admin']=true;ob_start();SDP_Source_Catalog::page();$h=ob_get_clean();ok(str_contains($h,'validate_source.py'),'Validator instructions');ok(str_contains($h,'Tidak ada pengunduhan otomatis'),'No automatic download claim');echo "TOTAL $n checks\n";
