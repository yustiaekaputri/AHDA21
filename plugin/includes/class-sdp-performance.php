<?php
if (!defined('ABSPATH')) exit;
/** Bounded public facet cache, conditional assets and on-demand legacy cron. */
final class SDP_Performance {
    const EPOCH='sdp88_cache_epoch';
    const MIGRATION='sdp88_cron_migrated';
    const SLOTS=32;
    const TTL=300;
    private static $local=[];
    private static $hits=0,$misses=0;
    public static function boot(){
        add_action('sdp_school_saved',[__CLASS__,'invalidate'],20,0);
        add_action('post_updated',[__CLASS__,'post_updated'],20,3);
        add_action('transition_post_status',[__CLASS__,'transition'],20,3);
        add_action('deleted_post',[__CLASS__,'deleted'],20,2);
        add_action(SDP_Collector::HOOK,[__CLASS__,'reconcile'],99,0);
        add_action('admin_menu',[__CLASS__,'menu'],75);
        add_action('admin_post_sdp88_maintenance',[__CLASS__,'action']);
    }
    public static function enabled(){return !defined('SDP_FACET_CACHE')||SDP_FACET_CACHE!==false;}
    const SUMMARY_KEY='sdp_preview_public_summary';
    const SUMMARY_TTL=30;
    // Experimental, opt-in. Only the unrestricted public aggregate; never cache rows.
    public static function summary_enabled(){return defined('SDP_SUMMARY_CACHE')&&SDP_SUMMARY_CACHE===true;}
    public static function summary_eligible($state){
        if(!self::summary_enabled())return false;
        foreach(array_merge(['q','fasilitas','jurusan'],array_keys(SDP_Portal::FILTERS)) as $k)if(($state[$k]??'')!=='')return false;
        return true;
    }
    public static function summary($sql,$query){
        if(!self::summary_enabled())return $query();
        $epoch=(string)get_option(self::EPOCH,'0');
        $hash=hash('sha256',$epoch.'|'.$sql);
        $entry=get_transient(self::SUMMARY_KEY);
        if(is_array($entry)&&($entry['hash']??'')===$hash&&($entry['until']??0)>time()&&is_array($entry['values']??null)){
            $v=$entry['values'];
            if(isset($v['total'],$v['negeri'],$v['swasta'])&&is_int($v['total'])&&is_int($v['negeri'])&&is_int($v['swasta'])&&min($v)>=0&&$v['negeri']+$v['swasta']<=$v['total'])return $v;
        }
        $v=$query();
        if(!is_array($v))return $v; // Do not cache failures.
        $v=['total'=>(int)($v['total']??0),'negeri'=>(int)($v['negeri']??0),'swasta'=>(int)($v['swasta']??0)];
        if($epoch===(string)get_option(self::EPOCH,'0'))set_transient(self::SUMMARY_KEY,['hash'=>$hash,'until'=>time()+self::SUMMARY_TTL,'values'=>$v],self::SUMMARY_TTL);
        return $v;
    }
    public static function invalidate(){self::$local=[];$ok=update_option(self::EPOCH,wp_generate_uuid4(),false);if(!$ok){for($i=0;$i<self::SLOTS;$i++)delete_transient('sdp88_facet_'.$i);delete_transient(self::SUMMARY_KEY);}return $ok;}
    public static function post_updated($id,$after,$before){if(($after->post_type??'')===SDP_Directory::TYPE||($before->post_type??'')===SDP_Directory::TYPE){if(($after->post_password??'')!==($before->post_password??'')||($after->post_type??'')!==($before->post_type??''))self::invalidate();}}
    public static function transition($new,$old,$post){if($new!==$old&&($post->post_type??'')===SDP_Directory::TYPE)self::invalidate();}
    public static function deleted($id,$post){if(($post->post_type??'')===SDP_Directory::TYPE)self::invalidate();}
    public static function slot($hash){return 'sdp88_facet_'.(hexdec(substr($hash,0,4))%self::SLOTS);}
    public static function facet($sql,$query){
        if(!self::enabled())return $query();
        $epoch=(string)get_option(self::EPOCH,'0');$hash=hash('sha256',$epoch.'|'.$sql);$key=self::slot($hash);
        $entry=self::$local[$key]??get_transient($key);
        if(is_array($entry)&&($entry['hash']??'')===$hash&&($entry['until']??0)>time()&&is_array($entry['values']??null)){
            self::$hits++;self::$local[$key]=$entry;return $entry['values'];
        }
        self::$misses++;$values=$query();
        // null signals a failed database query; never cache errors or unbounded responses.
        if(!is_array($values))return [];
        if(strlen(serialize($values))<=16384&&$epoch===(string)get_option(self::EPOCH,'0')){
            $entry=['hash'=>$hash,'until'=>time()+self::TTL,'values'=>$values];self::$local[$key]=$entry;set_transient($key,$entry,self::TTL);
        }
        return $values;
    }
    public static function assets_needed($kind='portal'){
        if(defined('SDP_ALWAYS_ASSETS')&&SDP_ALWAYS_ASSETS===true)return true;
        if(is_singular(SDP_Directory::TYPE))return true;
        // Scan queried posts for supported shortcodes; dynamic calls also enqueue on render.
        global $wp_query;
        $tags=$kind==='analytics'?['direktori_sekolah','portal_sekolah','bandingkan_sekolah']:['direktori_sekolah','portal_sekolah','bandingkan_sekolah','statistik_sekolah'];
        foreach((array)($wp_query->posts??[]) as $post){$content=$post->post_content??'';foreach($tags as $tag)if(has_shortcode($content,$tag))return true;}
        return false;
    }
    public static function prime($rows){if(!function_exists('_prime_post_caches'))return;$ids=[];foreach($rows as $r)if(!empty($r['post_id']))$ids[]=(int)$r['post_id'];if($ids)_prime_post_caches(array_unique($ids),false,false);}
    // null = no job. A queue is prioritized over scheduled network sources.
    public static function next_delay($pending,$network,$config,$last,$sources,$engine,$now){
        if(defined('SDP_SAFE_MODE')&&SDP_SAFE_MODE===true)return null;if($pending)return 300;if(!$network)return null;
        if(($engine['status']??'')==='running'&&!empty($engine['auto']))return 300;
        $due=[];if(!empty($config['schedule'])&&!empty($config['url']))$due[]=(int)$last+86400;
        foreach($sources as $s)if(!empty($s['config']['schedule'])&&!empty($s['config']['url']))$due[]=(int)($s['last']??0)+86400;
        return $due?max(300,min($due)-$now):null;
    }
    public static function reconcile($migrate=false){
        if(get_option('sdp_queue_version')!=='2.0.0')return false;
        $r=SDP_Collector::lock(function()use($migrate){
            if($migrate){$removed=wp_clear_scheduled_hook(SDP_Collector::HOOK,[],true);if(is_wp_error($removed)||$removed===false)return false;}
            $delay=self::next_delay(SDP_Collector::pending(),SDP_Resource::scheduled_network(),SDP_Collector::config(),get_option('sdp_collector_last_attempt',0),SDP_Sources::all(),SDP_Engine::state(),time());
            $next=wp_next_scheduled(SDP_Collector::HOOK);
            if($delay===null){if(!$next)return true;$removed=wp_clear_scheduled_hook(SDP_Collector::HOOK,[],true);return !is_wp_error($removed)&&$removed!==false;}
            $when=time()+$delay;if($next&&$next<=$when)return true;
            if($next){$removed=wp_clear_scheduled_hook(SDP_Collector::HOOK,[],true);if(is_wp_error($removed)||$removed===false)return false;}
            return wp_schedule_single_event($when,SDP_Collector::HOOK,[],true)===true;
        });
        // If another worker owns the collector lock, leave its event alone. A
        // one-time wakeup prevents a missing event when the current tick collides.
        if(!$migrate&&is_wp_error($r)&&$r->get_error_code()==='busy'&&!wp_next_scheduled(SDP_Collector::HOOK))return wp_schedule_single_event(time()+300,SDP_Collector::HOOK,[],true)===true;
        return $r===true;
    }
    public static function upgrade(){if(get_option(self::MIGRATION)!=='1'&&self::reconcile(true))update_option(self::MIGRATION,'1',false);}
    public static function guard_legacy(){
        if(defined('SDP_SAFE_MODE')&&SDP_SAFE_MODE===true)return false;
        // Unknown OS metrics are allowed for compatibility with LocalWP on Windows;
        // known low memory/load and PHP headroom still stop the automatic step.
        return SDP_Scheduler::guard(['load'=>SDP_Resource::cap(20,8),'unknown'=>true],SDP_Scheduler::metrics())==='';
    }
    public static function purge_done(){return SDP_Collector::lock(function(){global $wpdb;$t=SDP_Collector::table();$r=$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE status='done' AND updated_at<%s ORDER BY id LIMIT 500",gmdate('Y-m-d H:i:s',time()-30*86400)));return $r===false?new WP_Error('db','Gagal membersihkan log.'):(int)$r;});}
    public static function menu(){add_submenu_page('sdp-directory','Performa & Cache','Performa & Cache','manage_options','sdp-performance',[__CLASS__,'page']);}
    public static function action(){if(!current_user_can('manage_options'))wp_die('Akses ditolak.');check_admin_referer('sdp88_maintenance');$op=sanitize_key($_POST['op']??'');if($op==='cache'){self::invalidate();for($i=0;$i<self::SLOTS;$i++)delete_transient('sdp88_facet_'.$i);}elseif($op==='cron'){if(!self::reconcile(true))wp_die('Gagal memperbaiki jadwal; periksa database atau coba kembali.');update_option(self::MIGRATION,'1',false);}elseif($op==='purge'){$r=self::purge_done();if(is_wp_error($r))wp_die(esc_html($r->get_error_message()));}else wp_die('Operasi tidak dikenal.');wp_safe_redirect(admin_url('admin.php?page=sdp-performance'));exit;}
    public static function page(){if(!current_user_can('manage_options'))return;$m=SDP_Scheduler::metrics();$next=wp_next_scheduled(SDP_Collector::HOOK);echo '<div class="wrap"><h1>Performa &amp; Cache — SDP 8.8</h1><p>Cache terbatas untuk pilihan filter publik, bukan data profil. Jadwal kolektor sekali jalan ketika dibutuhkan. Tidak ada pengukuran trafik atau RAM historis.</p><table class="widefat striped"><tbody>';foreach(['Mode'=>SDP_Resource::enabled()?'Hemat':'Standar','Cache filter'=>self::enabled()?'Aktif':'Nonaktif','Slot cache maksimum'=>self::SLOTS,'TTL (detik)'=>self::TTL,'Load average 1 menit'=>$m['load']??'Tidak tersedia','RAM tersedia (MiB)'=>$m['available']===null?'Tidak tersedia':round($m['available']/1048576,1),'Memori PHP proses ini (MiB)'=>round($m['used']/1048576,1),'Kolektor berikutnya'=>$next?wp_date('Y-m-d H:i:s',$next):'Tidak terjadwal'] as $k=>$v)echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html((string)$v).'</td></tr>';echo '</tbody></table><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp88_maintenance">';wp_nonce_field('sdp88_maintenance');echo '<p><button class="button" name="op" value="cache">Bersihkan cache filter</button> <button class="button" name="op" value="cron">Periksa / pulihkan jadwal kolektor</button> <button class="button" name="op" value="purge">Hapus maks. 500 log selesai &gt;30 hari</button></p></form><p>Untuk tema/page builder dinamis, gunakan SDP_ALWAYS_ASSETS=true jika stylesheet terlambat muncul. Redis tidak wajib. Indeks tambahan tersedia melalui wp sdp indexes --yes setelah backup.</p></div>';}
}
