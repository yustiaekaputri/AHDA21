<?php
if (!defined('ABSPATH')) exit;
/** Bounded, admin-driven HTML discovery. No cron, no public routes, no auto publication. */
final class SDP_Crawler {
    const AGENT='SekolahDirectoryPro';
    const TTL=86400;
    const ROBOTS_BYTES=512000;
    public static function boot(){add_action('admin_menu',[__CLASS__,'menu'],76);add_action('admin_post_sdp_crawler',[__CLASS__,'action']);}
    public static function menu(){add_submenu_page('sdp-directory','Penelusuran Website','Penelusuran Website','manage_options','sdp-crawler',[__CLASS__,'page']);}
    public static function limits(){return ['pages'=>SDP_Resource::cap(15,5),'depth'=>SDP_Resource::cap(2,1),'bytes'=>SDP_Resource::cap(524288,262144),'timeout'=>SDP_Resource::cap(12,8),'queue'=>30];}
    public static function url($url,$website){
        $u=SDP_Discovery::url($url);if(!$u||SDP_Discovery::kind($u)==='social'||is_wp_error(SDP_Website::validate_url($u,$website)))return '';
        $p=parse_url($u,PHP_URL_PATH); // Only simple ASCII paths; avoid URL interpretation and robots encoding ambiguities.
        if(!is_string($p)||!preg_match('~^/[A-Za-z0-9_./-]*$~D',$p)||str_contains($p,'//')||preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$p))return '';
        if(preg_match('~(?:^|/)(?:wp-admin|wp-login\.php|wp-json|xmlrpc\.php|logout|login|admin|cgi-bin|download|uploads|feed)(?:/|$)~i',$p)||preg_match('~\.(?:zip|pdf|docx?|xlsx?|pptx?|jpe?g|png|gif|webp|svg|mp[34]|exe|xml|json|css|js)$~i',$p))return '';
        return $u;
    }
    public static function resolve($href,$base,$website){
        if(!is_string($href)||strlen($href)>1000)return '';$h=trim(html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if($h===''||str_starts_with($h,'//')||strpbrk($h,'?#%')!==false)return '';
        if(preg_match('~^[a-z][a-z0-9+.-]*:~i',$h))return self::url($h,$website);
        $origin='https://'.parse_url($base,PHP_URL_HOST);$path=parse_url($base,PHP_URL_PATH)?:'/';
        return self::url($origin.(str_starts_with($h,'/')?$h:substr($path,0,strrpos($path,'/')+1).$h),$website);
    }
    public static function robots($body){
        if(!is_string($body)||strlen($body)>self::ROBOTS_BYTES||!preg_match('//u',$body)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$body)||str_contains($body,'<'))return new WP_Error('robots','robots.txt tidak dapat diproses dengan aman.');
        $body=preg_replace('/^\xEF\xBB\xBF/','',$body);$groups=[];$g=['agents'=>[],'rules'=>[],'delay'=>60,'has'=>false];
        foreach(preg_split('/\r\n|\n|\r/',$body) as $line){
            $line=trim(explode('#',$line,2)[0]);if($line==='')continue;
            if(!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i',$line,$m))return new WP_Error('robots','Baris robots.txt tidak dikenali.');
            $k=strtolower($m[1]);$v=trim($m[2]);
            if($k==='user-agent'){
                if($g['has']){$groups[]=$g;$g=['agents'=>[],'rules'=>[],'delay'=>60,'has'=>false];}
                if(!preg_match('/^(?:\*|[A-Za-z_-]+)$/D',$v))return new WP_Error('robots','User-agent tidak didukung.');$g['agents'][]=strtolower($v);continue;
            }
            if(!in_array($k,['allow','disallow','crawl-delay'],true))continue;
            if(!$g['agents'])return new WP_Error('robots','Aturan tanpa user-agent.');$g['has']=true;
            if($k==='crawl-delay'){if(!preg_match('/^\d{1,4}(?:\.\d{1,3})?$/D',$v)||(float)$v>3600)return new WP_Error('robots','Crawl-delay tidak didukung; hentikan.');$g['delay']=max($g['delay'],(int)ceil((float)$v));continue;}
            if($v==='')continue;
            // Unsupported encodings/query patterns fail closed rather than risk bypass.
            if(strlen($v)>512||!preg_match('~^/[A-Za-z0-9_./*\-]*\$?$~D',$v)||substr_count($v,'*')>8)return new WP_Error('robots','Pola robots.txt tidak didukung; hentikan.');
            $g['rules'][]=[$k==='allow',$v];if(count($g['rules'])>500)return new WP_Error('robots','Terlalu banyak aturan.');
        }
        if($g['agents'])$groups[]=$g;$specific=[];$fallback=[];
        foreach($groups as $g){if(in_array(strtolower(self::AGENT),$g['agents'],true))$specific[]=$g;elseif(in_array('*',$g['agents'],true))$fallback[]=$g;}
        $out=['rules'=>[],'delay'=>60];foreach($specific?:$fallback as $g){$out['rules']=array_merge($out['rules'],$g['rules']);$out['delay']=max($out['delay'],$g['delay']);if(count($out['rules'])>500)return new WP_Error('robots','Terlalu banyak aturan aktif.');}return $out;
    }
    public static function allowed($policy,$url){
        if(!is_array($policy)||!isset($policy['rules'])||!is_array($policy['rules']))return false;
        $path=parse_url($url,PHP_URL_PATH)?:'/';$best=-1;$allow=true;
        foreach($policy['rules'] as [$permit,$pattern]){
            $end=str_ends_with($pattern,'$');$p=$end?substr($pattern,0,-1):$pattern;
            $regex='~^'.str_replace('\\*','.*',preg_quote($p,'~')).($end?'$':'').'~D';$match=preg_match($regex,$path);if($match===false)return false;if(!$match)continue;
            $len=strlen($pattern);if($len>$best||($len===$best&&$permit)){$best=$len;$allow=$permit;}
        }return $allow;
    }
    public static function links($html,$base,$website){
        if(!is_string($html)||strlen($html)>self::limits()['bytes']||!preg_match('//u',$html)||preg_match('~<base\b~i',$html))return [];
        $html=preg_replace('~<!--.*?-->|<(script|style|template|iframe|noscript|svg)\b[^>]*>.*?</\1\s*>~is',' ',$html);if($html===null||preg_match('~<(script|style|template|iframe|noscript|svg)\b~i',$html))return [];
        $out=[];preg_match_all('~<a\b([^>]{0,2000})>(.*?)</a\s*>~is',$html,$matches,PREG_SET_ORDER);
        foreach(array_slice($matches,0,300) as $m){
            if(preg_match('~\b(?:nofollow|hidden)\b~i',$m[1]))continue;
            if(!preg_match('~(?:^|\s)href\s*=\s*([\x22\x27])(.*?)\1~is',$m[1],$a))continue;
            $u=self::resolve($a[2],$base,$website);if(!$u||$u===$base)continue;
            $text=html_entity_decode(strip_tags($m[2]),ENT_QUOTES|ENT_HTML5,'UTF-8').' '.parse_url($u,PHP_URL_PATH);
            if(!preg_match('~(?:profil|visi|misi|sejarah|tentang|fasilitas|sarana|prestasi|ekstra|spmb|ppdb|penerimaan|jurusan|keahlian|beasiswa|biaya|program)~i',$text))continue;
            $out[$u]=$u;if(count($out)>=self::limits()['queue'])break;
        }return array_values($out);
    }
    public static function initial($d){
        $u=self::url($d['website']??'',$d['website']??'');if(!$u)return new WP_Error('url','Website tercatat harus HTTPS, path sederhana, dan bukan media sosial.');
        return ['npsn'=>$d['npsn'],'fingerprint'=>SDP_Discovery::fingerprint($d),'website'=>$d['website'],'created'=>time(),'policy'=>null,'next'=>0,'state'=>'ready','queue'=>[['url'=>$u,'depth'=>0]],'seen'=>[],'results'=>[],'pages'=>0,'note'=>'Periksa robots.txt terlebih dahulu.'];
    }
    public static function current($job,$d){return is_array($job)&&($job['npsn']??'')===($d['npsn']??null)&&($job['fingerprint']??'')===SDP_Discovery::fingerprint($d)&&time()-($job['created']??0)<self::TTL;}
    public static function halted($job,$message){$job['state']='stopped';$job['note']=$message;return $job;}
    public static function next_page($job){
        while($job['queue']){$q=array_shift($job['queue']);if(isset($job['seen'][$q['url']]))continue;if(!self::allowed($job['policy'],$q['url'])){$job['seen'][$q['url']]='Dilarang robots.txt';continue;}return [$job,$q];}return [$job,null];
    }
    public static function ingest($job,$q,$html,$d,$nofollow=false){
        $u=$q['url'];$job['pages']++;$job['seen'][$u]='Diperiksa';
        $a=SDP_Discovery::assess($html,$d,$u);if(is_wp_error($a)||!empty($a['conflict']))return self::halted($job,'HTML tidak didukung atau konflik NPSN.');
        // Every page must independently show the school name or exact NPSN. Root needs stronger discovery evidence.
        if(($q['depth']===0&&!$a['eligible'])||(!$a['name']&&!$a['npsn']))return self::halted($job,'Bukti identitas halaman tidak cukup.');
        if(preg_match('~<meta\b[^>]*(?:noindex|nofollow|noarchive|nosnippet)[^>]*>~i',$html))$nofollow=true;
        if(!$nofollow){$v=SDP_Website::parse($html,$d);if(!is_wp_error($v))$job['results'][$u]=array_intersect_key($v,array_flip(SDP_Website::FIELDS));}
        if(!$nofollow&&$q['depth']<self::limits()['depth']){
            $queued=array_column($job['queue'],'url');foreach(self::links($html,$u,$job['website']) as $link){if(isset($job['seen'][$link])||in_array($link,$queued,true)||!self::allowed($job['policy'],$link))continue;if(count($job['queue'])>=self::limits()['queue'])break;$job['queue'][]=['url'=>$link,'depth'=>$q['depth']+1];$queued[]=$link;}
        }
        if($job['pages']>=self::limits()['pages']||!$job['queue'])$job['state']='done';$job['note']='Halaman diperiksa. Usulan belum disimpan ke profil.';return $job;
    }
    private static function fetch($u,$job,$robots=false){
        $host=parse_url($u,PHP_URL_HOST);if(!self::url($u,$job['website'])||!wp_http_validate_url($u))return new WP_Error('url','URL ditolak.');
        $rr=@dns_get_record($host,DNS_A|DNS_AAAA);$found=false;if(!is_array($rr))return new WP_Error('dns','DNS tidak tersedia.');foreach($rr as $r){$ip=$r['ip']??$r['ipv6']??null;if($ip===null)continue;$found=true;if(!SDP_Discovery::public_ip($ip))return new WP_Error('dns','DNS bukan IP publik.');}if(!$found)return new WP_Error('dns','DNS publik tidak ditemukan.');
        $delay=max(60,(int)($job['policy']['delay']??60));$domain='sdp61_rate_'.md5($host);if(time()<$job['next']||get_transient('sdp85_rate')||get_transient('sdp84_network_rate')||get_transient($domain))return new WP_Error('rate','Jeda permintaan belum selesai.');
        if(!set_transient('sdp85_rate',1,$delay)||!set_transient('sdp84_network_rate',1,$delay)||!set_transient($domain,1,$delay))return new WP_Error('rate','Pembatas permintaan tidak tersedia.');
        $limit=$robots?self::ROBOTS_BYTES:self::limits()['bytes'];
        $r=wp_safe_remote_get($u,['timeout'=>self::limits()['timeout'],'redirection'=>0,'limit_response_size'=>$limit+1,'sslverify'=>true,'headers'=>['Accept'=>$robots?'text/plain':'text/html'],'user-agent'=>self::AGENT.'/8.5 (school directory; administrator requested)']);
        if(is_wp_error($r))return new WP_Error('network','Koneksi gagal. Tidak ada retry otomatis.');$c=(int)wp_remote_retrieve_response_code($r);$body=wp_remote_retrieve_body($r);
        if($robots&&in_array($c,[404,410],true))return ['body'=>'','nofollow'=>false];
        if($c!==200)return new WP_Error('http','HTTP '.$c.'. Tidak mengikuti redirect atau retry.');
        $type=wp_remote_retrieve_header($r,'content-type');if(!is_string($type)||!preg_match($robots?'~^text/plain(?:\s*;|$)~i':'~^text/html(?:\s*;|$)~i',$type)||!is_string($body)||strlen($body)>$limit)return new WP_Error('body','Tipe atau ukuran respons ditolak.');
        if(preg_match('/charset\s*=\s*[\x22\x27]?([^;\s\x22\x27]+)/i',$type,$m)&&strtolower($m[1])!=='utf-8')return new WP_Error('body','Encoding bukan UTF-8.');
        $x=wp_remote_retrieve_header($r,'x-robots-tag');return ['body'=>$body,'nofollow'=>(bool)preg_match('/noindex|nofollow|noarchive|nosnippet/i',is_array($x)?implode(' ',$x):(string)$x)];
    }
    public static function step($job,$d){
        if(!self::current($job,$d)||$job['state']!=='ready')return new WP_Error('state','Pekerjaan kedaluwarsa, identitas berubah, atau sudah berhenti.');
        if($job['policy']===null){$u='https://'.parse_url($job['website'],PHP_URL_HOST).'/robots.txt';$r=self::fetch($u,$job,true);if(is_wp_error($r))return $r->get_error_message()==='Jeda permintaan belum selesai.'?$r:self::halted($job,$r->get_error_message());$p=self::robots($r['body']);if(is_wp_error($p))return self::halted($job,$p->get_error_message());if($p['delay']>60){$token=time()+$p['delay'];$domain='sdp61_rate_'.md5(parse_url($u,PHP_URL_HOST));if(!set_transient('sdp85_rate',$token,$p['delay'])||!set_transient('sdp84_network_rate',$token,$p['delay'])||!set_transient($domain,$token,$p['delay']))return self::halted($job,'Jeda robots.txt gagal disimpan.');}$job['policy']=$p;$job['next']=time()+$p['delay'];$job['note']='robots.txt diperiksa; tunggu jeda sebelum halaman pertama.';return $job;}
        if($job['pages']>=self::limits()['pages']){$job['state']='done';return $job;}
        [$next,$q]=self::next_page($job);if(!$q){$next['state']='done';$next['note']='Tidak ada halaman lain yang diizinkan.';return $next;}
        $r=self::fetch($q['url'],$job);if(is_wp_error($r))return $r->get_error_message()==='Jeda permintaan belum selesai.'?$r:self::halted($next,$r->get_error_message());$next['next']=time()+$job['policy']['delay'];return self::ingest($next,$q,$r['body'],$d,$r['nofollow']);
    }
    public static function review($job,$d,$url){
        if(!self::current($job,$d)||!isset($job['results'][$url])||!self::url($url,$d['website']))return new WP_Error('review','Usulan tidak tersedia atau identitas berubah.');
        if($job['state']==='stopped')return new WP_Error('review','Pekerjaan dihentikan; periksa penyebab sebelum mengambil ulang.');
        return SDP_Website::preview($d,$url,$job['results'][$url]);
    }
    private static function input($key){$v=$_POST[$key]??'';return is_string($v)?trim(wp_unslash($v)):'';}
    public static function action(){
        if(!current_user_can('manage_options'))wp_die('Akses ditolak.');check_admin_referer('sdp_crawler');$uid=get_current_user_id();$key='sdp85_job_'.$uid;$op=self::input('op');
        $r=SDP_Collector::lock(function()use($key,$op,$uid){
            if($op==='discard'){delete_transient($key);return 'Pekerjaan dibuang.';}
            if(self::input('authorized')!=='1')return new WP_Error('permission','Konfirmasi izin akses dan penggunaan data.');
            $j=get_transient($key);$id=$op==='start'?strtoupper(self::input('npsn')):($j['npsn']??'');$d=SDP_Website::load($id);if(is_wp_error($d))return $d;
            if($op==='start'){$j=self::initial($d);}elseif($op==='step'){$j=self::step($j,$d);}elseif($op==='review'){
                $p=self::review($j,$d,self::input('url'));if(is_wp_error($p))return $p;
                if(!set_transient('sdp61_preview_'.$uid,$p,900))return new WP_Error('database','Pratinjau gagal disimpan.');return 'Usulan disalin ke menu Website Sekolah. Periksa dan sunting sebelum menyimpan.';
            }else return new WP_Error('op','Operasi tidak dikenal.');
            if(is_wp_error($j))return $j;if(!set_transient($key,$j,max(1,self::TTL-(time()-$j['created']))))return new WP_Error('database','Checkpoint gagal disimpan.');return $j['note'];
        });
        set_transient('sdp85_notice_'.$uid,['error'=>is_wp_error($r),'text'=>is_wp_error($r)?$r->get_error_message():$r],120);wp_safe_redirect(admin_url('admin.php?page=sdp-crawler'));exit;
    }
    private static function form(){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp_crawler">';wp_nonce_field('sdp_crawler');}
    private static function consent(){echo '<p><label><input type="checkbox" name="authorized" value="1" required> Saya telah memeriksa izin penelusuran, ketentuan sumber, dan penggunaan ulang informasi.</label></p>';}
    public static function page(){
        if(!current_user_can('manage_options'))return;$uid=get_current_user_id();$j=get_transient('sdp85_job_'.$uid);$n=get_transient('sdp85_notice_'.$uid);delete_transient('sdp85_notice_'.$uid);$l=self::limits();
        echo '<div class="wrap"><h1>Penelusuran Website Sekolah — v8.5</h1><p>Eksperimental, manual, satu permintaan per klik. Maksimal '.esc_html((string)$l['pages']).' halaman HTML, kedalaman '.esc_html((string)$l['depth']).', checkpoint hingga 24 jam. Tidak ada cron baru.</p>';
        if(is_array($n))echo '<div class="notice '.($n['error']?'notice-warning':'notice-info').'"><p>'.esc_html($n['text']).'</p></div>';
        self::form();echo '<p><label>NPSN <input name="npsn" maxlength="8" required value="'.esc_attr($j['npsn']??'').'"></label></p>';self::consent();echo '<button class="button button-primary" name="op" value="start">Buat / ganti pekerjaan</button></form>';
        if(is_array($j)){
            echo '<h2>Pekerjaan '.esc_html($j['npsn']).'</h2><p>'.esc_html($j['state'].' — '.$j['note']).'</p><p>Halaman diperiksa: '.esc_html((string)$j['pages']).'; antrean: '.esc_html((string)count($j['queue'])).'; robots.txt: '.($j['policy']===null?'belum':'diperiksa').'.</p>';
            if($j['state']==='ready'){self::form();self::consent();echo '<button class="button button-primary" name="op" value="step">'.($j['policy']===null?'Periksa robots.txt':'Proses 1 halaman').'</button></form>';}
            if($j['seen']){echo '<h3>Log halaman</h3><ul>';foreach($j['seen'] as $u=>$status)echo '<li>'.esc_html($u.' — '.$status).'</li>';echo '</ul>';}
            if($j['results']){echo '<h3>Usulan per halaman</h3><p>Tinjau satu sumber per pengajuan agar URL sumber tetap tepat. Tidak ada publikasi otomatis.</p>';foreach($j['results'] as $u=>$vals){echo '<p><a href="'.esc_url($u).'" target="_blank" rel="noopener noreferrer">'.esc_html($u).'</a> — '.esc_html(implode(', ',array_keys($vals))).'</p>';if($j['state']!=='stopped'){self::form();echo '<input type="hidden" name="url" value="'.esc_attr($u).'">';self::consent();echo '<button class="button" name="op" value="review">Kirim ke pratinjau Website Sekolah</button></form>';}}echo '<p><a href="'.esc_url(admin_url('admin.php?page=sdp-website')).'">Buka Website Sekolah</a></p>';}
            self::form();echo '<p><button class="button" name="op" value="discard">Buang pekerjaan</button></p></form>';
        }echo '</div>';
    }
}
