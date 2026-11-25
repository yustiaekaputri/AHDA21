<?php
// Benchmark only on an isolated disposable CI database. Not a production endpoint.
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli') exit(1);
if (wp_get_environment_type()!=='local' || wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1' || get_option('blog_public') || !defined('SDP_SAFE_MODE') || SDP_SAFE_MODE!==true || !defined('SDP_SMOKE_TEST_ENABLED') || SDP_SMOKE_TEST_ENABLED!==true || !current_user_can('manage_options') || is_multisite() || !SDP_Resource::enabled()) WP_CLI::error('Disposable low-resource test site required');
global $wpdb;
$size=(int)($args[0]??-1);
if(!in_array($size,[0,100,1000,10000],true)) WP_CLI::error('Unexpected benchmark size');
$table=SDP_Directory::table();
$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
if($wpdb->last_error || $total!==4+$size) WP_CLI::error('Unexpected index size');
$published=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table s JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE p.post_status=%s AND p.post_type=%s AND p.post_password=''",'publish',SDP_Directory::TYPE));
if($wpdb->last_error || $published!==2+$size) WP_CLI::error('Unexpected published count');
$bad=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE npsn NOT LIKE 'BENC%' AND npsn NOT IN ('TEST0001','TEST0002','TEST0003','TEST0005')");
if($wpdb->last_error || $bad) WP_CLI::error('Non-fixture data detected');
if($size===0) { WP_CLI::success('Initial disposable fixture state verified'); return; }
$mode=SDP_Resource::enabled()?'low':'standard';
$result=SDP_Benchmark::run(['sdp_provinsi'=>'Provinsi Benchmark 0'],3,15,true);
$case=$result['cases'];
foreach(['listing','filtered','page_100','facets'] as $k)if(!isset($case[$k]) || $case[$k]['samples']!==3 || $case[$k]['errors']!==0) WP_CLI::error('Incomplete or failed benchmark '.$k);
foreach($case['listing']['result_counts'] as $n) if($n!==$published) WP_CLI::error('Listing benchmark count mismatch');
foreach(['filtered','page_100'] as $k)foreach($case[$k]['result_counts'] as $n)if($n!==intdiv($size,10)) WP_CLI::error('Filtered benchmark count mismatch');
// The existing filtered page_100 scenario clamps to the last available page.
 // Independently verify a genuinely deep page on the unfiltered directory.
 if($size===10000){
     $first=SDP_Portal::results(SDP_Portal::state([]));
     $deep=SDP_Portal::results(SDP_Portal::state(['sdp_p'=>100]));
     if(!empty($first['error'])||!empty($deep['error'])||$deep['page']!==100||count($deep['rows'])!==SDP_Portal::LIMIT||array_intersect(array_column($first['rows'],'npsn'),array_column($deep['rows'],'npsn'))) WP_CLI::error('Deep unfiltered pagination failed');
     $result['unfiltered_page_100']=SDP_Benchmark::measure(fn()=>SDP_Portal::results(SDP_Portal::state(['sdp_p'=>100])),3,microtime(true)+15);
     if($result['unfiltered_page_100']['errors']||$result['unfiltered_page_100']['samples']!==3) WP_CLI::error('Deep pagination benchmark incomplete');
 }
$result['fixture_rows']=$size;
$result['index_rows']=$total;
$result['public_rows']=$published;
$result['db_version']=$wpdb->db_version();
$result['php_version']=PHP_VERSION;
$result['wordpress_version']=get_bloginfo('version');
$result['scope']='CI runner, fictional dataset, single-process query timings; not VPS capacity';
WP_CLI::log(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
