<?php
if (!defined('ABSPATH')) exit;
/** Comparison, period-tagged observations, nearby schools and moderated corrections. */
final class SDP_Analytics {
    const VERSION='1';
    const FACILITIES=['laboratorium'=>'Laboratorium','perpustakaan'=>'Perpustakaan','toilet'=>'Toilet'];
    const STATS=['jumlah_siswa'=>'Siswa','jumlah_guru'=>'Guru','jumlah_rombel'=>'Rombel','ruang_kelas'=>'Ruang kelas','siswa_laki_laki'=>'Siswa laki-laki','siswa_perempuan'=>'Siswa perempuan','jumlah_tendik'=>'Tenaga kependidikan'];
    public static function robots($robots){if(isset($_GET['sdp_banding'])||isset($_GET['sdp_favorit'])){unset($robots['index']);$robots['noindex']=true;}return $robots;}
    public static function history_table(){global $wpdb;return $wpdb->prefix.'sdp_history';}
    public static function reports_table(){global $wpdb;return $wpdb->prefix.'sdp_reports';}
    public static function boot(){
        add_action('admin_init',[__CLASS__,'install']);add_action('admin_menu',[__CLASS__,'menu'],50);
        add_action('admin_post_sdp41_page',[__CLASS__,'create_page']);add_action('admin_post_sdp41_report',[__CLASS__,'report']);add_action('admin_post_sdp41_review',[__CLASS__,'review']);
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);add_shortcode('bandingkan_sekolah',[__CLASS__,'comparison']);
        add_filter('wp_robots',[__CLASS__,'robots'],100);add_filter('the_content',[__CLASS__,'profile'],25);add_action('deleted_post',[__CLASS__,'delete'],5,2);
    }
    public static function install(){
        if(get_option('sdp41_schema')===self::VERSION)return true;
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$h=self::history_table();$r=self::reports_table();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $h (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            npsn varchar(8) NOT NULL,
            fingerprint char(64) NOT NULL,
            period varchar(120) NOT NULL,
            payload text NOT NULL,
            source varchar(191) NOT NULL DEFAULT '',
            recorded_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY observation (npsn,fingerprint),
            KEY school_time (npsn,id)
        ) $c;");
        dbDelta("CREATE TABLE $r (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            npsn varchar(8) NOT NULL,
            field_name varchar(64) NOT NULL,
            message text NOT NULL,
            source_url varchar(1000) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_id (status,id),
            KEY npsn (npsn)
        ) $c;");
        foreach([$h,$r] as $t)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))!==$t)return false;
        update_option('sdp41_schema',self::VERSION);return true;
    }
    public static function observation($clean){
        $period=is_scalar($clean['tahun_data']??null)?trim((string)$clean['tahun_data']):'';if(strlen($period)>120||!preg_match('/\b(?:19|20)\d{2}\b/',$period))return [];
        $stats=[];foreach(self::STATS as $k=>$label){$v=SDP_Portal::num($clean,$k);if($v!==null)$stats[$k]=$v;}
        return $stats?['period'=>$period,'stats'=>$stats]:[];
    }
    public static function record($clean,$provenance=null){
        $o=self::observation($clean);if(!$o||!self::install())return;
        global $wpdb;$source=SDP_Portal::clean($provenance['label']??$clean['sumber']??'Input admin / CSV',150);$json=wp_json_encode($o['stats']);$fp=hash('sha256',$o['period'].'|'.$json.'|'.$source);
        // Each distinct submitted observation is retained, never padded with prior-period fields.
        $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::history_table().' (npsn,fingerprint,period,payload,source,recorded_at) VALUES (%s,%s,%s,%s,%s,%s)',$clean['npsn'],$fp,$o['period'],$json,$source,current_time('mysql',true)));
    }
    public static function ids($raw,$max=4){
        if(!is_string($raw)&&!is_array($raw))return [];$raw=is_string($raw)?explode(',',substr($raw,0,500)):$raw;$out=[];
        foreach(array_slice($raw,0,50) as $id){if(!is_string($id))continue;$id=strtoupper(trim($id));if(preg_match('/^[A-Z0-9]{8}$/D',$id)&&!in_array($id,$out,true))$out[]=$id;if(count($out)>=$max)break;}return $out;
    }
    public static function public_rows($ids){
        if(!$ids)return [];global $wpdb;[$from,$args]=SDP_Portal::from([]);$marks=implode(',',array_fill(0,count($ids),'%s'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT s.*'.$from." AND s.npsn IN ($marks)",array_merge($args,$ids)),ARRAY_A)?:[];
        $by=[];foreach($rows as $r)if(in_array($r['npsn'],$ids,true))$by[$r['npsn']]=$r;
        return array_values(array_filter(array_map(static fn($id)=>$by[$id]??null,$ids)));
    }
    public static function ratio($d,$denom){$a=SDP_Portal::num($d,'jumlah_siswa');$b=SDP_Portal::num($d,$denom);return $a!==null&&$b!==null&&$b>0?$a/$b:null;}
    public static function distance($a,$b){$lat=deg2rad($b[0]-$a[0]);$lon=deg2rad($b[1]-$a[1]);$h=sin($lat/2)**2+cos(deg2rad($a[0]))*cos(deg2rad($b[0]))*sin($lon/2)**2;return 6371*2*asin(sqrt(max(0,min(1,$h))));}
    public static function compare_url(){ $id=(int)get_option('sdp41_compare_page');return $id&&SDP_Portal::context($id)?get_permalink($id):''; }
    public static function controls($r){return '<div class="sdp41-controls"><button type="button" data-sdp41-compare="'.esc_attr($r['npsn']).'" aria-pressed="false" hidden>Bandingkan</button><button type="button" data-sdp41-fav="'.esc_attr($r['npsn']).'" aria-pressed="false" hidden>Favorit</button></div>';}
    public static function toolbar(){ $url=self::compare_url();if(!$url)return '';return '<div class="sdp41-toolbar" data-sdp41-toolbar data-url="'.esc_url($url).'" hidden><span data-sdp41-count role="status"></span><a data-sdp41-go href="'.esc_url($url).'">Bandingkan pilihan</a><a data-sdp41-favorites href="'.esc_url($url).'">Favorit saya</a><button type="button" data-sdp41-clear>Hapus pilihan</button></div>'; }
    public static function comparison(){
        self::assets(true);
        $favorite=isset($_GET['sdp_favorit']);$ids=self::ids(wp_unslash($_GET['sdp_banding']??''),$favorite?20:4);$rows=self::public_rows($ids);
        $html='<section class="sdp-profile sdp41"><h2>'.($favorite?'Sekolah favorit':'Perbandingan sekolah').'</h2>'.self::toolbar().'<p>Masukkan NPSN dipisahkan koma (maksimal '.($favorite?'20':'4').'). Hanya profil publik yang ditampilkan.</p><form method="get" class="sdp41-form">';
        if(!get_option('permalink_structure')&&get_queried_object_id())$html.='<input type="hidden" name="page_id" value="'.(int)get_queried_object_id().'">';
        if($favorite)$html.='<input type="hidden" name="sdp_favorit" value="1">';
        $html.='<label>NPSN <input name="sdp_banding" maxlength="180" value="'.esc_attr(implode(',',$ids)).'"></label> <button type="submit">Tampilkan</button></form>';
        if(!$rows)return $html.'<p>Belum ada profil publik yang cocok.</p></section>';
        if(count($rows)<count($ids))$html.='<p>Beberapa NPSN tidak ditemukan atau tidak tersedia untuk publik.</p>';
        if($favorite){$html.='<ul>';foreach($rows as $r)$html.='<li><a href="'.esc_url(get_permalink((int)$r['post_id'])).'">'.esc_html($r['nama']).'</a>'.self::controls($r).'</li>';return $html.'</ul></section>';}
        $fields=['npsn'=>'NPSN','jenjang'=>'Jenjang','status'=>'Status','akreditasi'=>'Akreditasi','tahun_akreditasi'=>'Tahun akreditasi','alamat'=>'Alamat','kecamatan'=>'Kecamatan','kabupaten'=>'Kabupaten','provinsi'=>'Provinsi','tahun_data'=>'Periode data','jumlah_siswa'=>'Jumlah siswa','jumlah_guru'=>'Jumlah guru','jumlah_rombel'=>'Rombel','ruang_kelas'=>'Ruang kelas','luas_tanah'=>'Luas tanah (m²)','laboratorium'=>'Laboratorium','perpustakaan'=>'Perpustakaan','internet'=>'Internet','jurusan'=>'Jurusan','kurikulum'=>'Kurikulum','ratio_guru'=>'Siswa per guru','ratio_kelas'=>'Siswa per ruang kelas'];
        $html.='<p>Perbedaan periode dan kelengkapan dapat memengaruhi perbandingan. Ini bukan pemeringkatan kualitas sekolah.</p><div class="sdp-table-wrap"><table class="sdp-table sdp41-compare"><thead><tr><th scope="col">Atribut</th>';
        foreach($rows as $r)$html.='<th scope="col"><a href="'.esc_url(get_permalink((int)$r['post_id'])).'">'.esc_html($r['nama']).'</a></th>';$html.='</tr></thead><tbody>';
        foreach($fields as $k=>$label){$html.='<tr><th scope="row">'.esc_html($label).'</th>';foreach($rows as $r){$d=array_merge(json_decode($r['payload'],true)?:[],array_intersect_key($r,array_flip(['npsn','jenjang','status','akreditasi','kecamatan','kabupaten','provinsi'])));if(str_starts_with($k,'ratio_')){$v=self::ratio($d,$k==='ratio_guru'?'jumlah_guru':'ruang_kelas');$value=$v===null?'—':number_format_i18n($v,1);}else $value=SDP_SEO::present($d,$k)?$d[$k].SDP_Intelligence::period_note($d,$k):'—';$html.='<td>'.nl2br(esc_html($value)).'</td>';}$html.='</tr>';}
        return $html.'</tbody></table></div><p>— = data belum tersedia.</p><button type="button" class="sdp41-print" hidden>Cetak / Simpan PDF</button></section>';
    }
    public static function history($npsn){
        if(get_option('sdp41_schema')!==self::VERSION)return '';global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT period,payload,source,recorded_at FROM '.self::history_table().' WHERE npsn=%s ORDER BY id DESC LIMIT 20',$npsn),ARRAY_A)?:[];
        $html='<section><h2>Riwayat statistik tercatat</h2><p>Observasi dari data yang dikirim dengan periode eksplisit; bukan riwayat resmi yang lengkap atau bukti data diterapkan pada profil. Observasi berbeda pada periode yang sama dapat muncul. Tanggal pencatatan dalam UTC.</p>';
        if(!$rows)return $html.'<p>Belum ada riwayat. Data sebelum v4.1 tidak direkonstruksi otomatis.</p></section>';
        $html.='<div class="sdp-table-wrap"><table class="sdp-table"><thead><tr><th>Periode</th><th>Siswa</th><th>Guru</th><th>Rombel</th><th>Ruang kelas</th><th>Sumber / pencatatan</th></tr></thead><tbody>';
        foreach($rows as $r){$d=json_decode($r['payload'],true)?:[];$html.='<tr><th scope="row">'.esc_html($r['period']).'</th>';foreach(['jumlah_siswa','jumlah_guru','jumlah_rombel','ruang_kelas'] as $k){$v=SDP_Portal::num($d,$k);$html.='<td>'.esc_html($v===null?'—':number_format_i18n($v)).'</td>';}$html.='<td>'.esc_html($r['source'].' · '.$r['recorded_at']).'</td></tr>';}
        return $html.'</tbody></table></div></section>';
    }
    public static function nearby($d){
        $geo=SDP_Portal::geo($d);if(!$geo||empty($d['provinsi'])||empty($d['kabupaten']))return '';global $wpdb;[$from,$args]=SDP_Portal::from(['provinsi'=>$d['provinsi'],'kabupaten'=>$d['kabupaten']]);$args[]=$d['npsn'];
        $rows=$wpdb->get_results($wpdb->prepare('SELECT s.npsn,s.nama,s.post_id,s.payload'.$from.' AND s.npsn<>%s ORDER BY s.npsn LIMIT 1001',$args),ARRAY_A)?:[];$limited=count($rows)>1000;$dist=[];
        foreach(array_slice($rows,0,1000) as $r){$g=SDP_Portal::geo(json_decode($r['payload'],true)?:[]);if($g){$r['km']=self::distance($geo,$g);$dist[]=$r;}}
        usort($dist,static fn($a,$b)=>$a['km']<=>$b['km']);if(!$dist)return '';$html='<section><h2>Sekolah sekitar</h2><p>Jarak garis lurus, bukan jarak perjalanan. Kandidat dalam kabupaten/kota yang sama'.($limited?' (dibatasi 1.000 kandidat pertama menurut NPSN)':'').'.</p><ul>';
        foreach(array_slice($dist,0,5) as $r)$html.='<li><a href="'.esc_url(get_permalink((int)$r['post_id'])).'">'.esc_html($r['nama']).'</a> — '.esc_html(number_format_i18n($r['km'],1)).' km</li>';return $html.'</ul></section>';
    }
    public static function profile($content){
        if(!is_singular(SDP_Directory::TYPE)||!is_main_query()||!in_the_loop()||post_password_required()||get_post_status()!=='publish')return $content;$d=SDP_SEO::current();if(!$d||!self::public_rows([$d['npsn']]))return $content;
        return $content.'<div class="sdp-profile sdp41">'.self::toolbar().self::controls($d).'<button type="button" class="sdp41-print" hidden>Cetak / Simpan PDF</button>'.self::history($d['npsn']).self::nearby($d).self::report_form($d).'</div>';
    }
    public static function report_form($d){
        $html='<section class="sdp41-report"><h2>Usulkan koreksi data</h2>';if(isset($_GET['sdp41_sent']))$html.='<p role="status">'.($_GET['sdp41_sent']==='1'?'Laporan diterima untuk pemeriksaan.':'Laporan gagal. Coba lagi nanti.').'</p>';
        if(!is_user_logged_in())return $html.'<p><a href="'.esc_url(wp_login_url(get_permalink())).'">Masuk untuk mengusulkan koreksi</a>. Laporan diperiksa administrator.</p></section>';
        $html.='<p>Jangan sertakan NIK, NISN, nomor pribadi atau data individual siswa. Koreksi tidak langsung mengubah profil.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp41_report"><input type="hidden" name="npsn" value="'.esc_attr($d['npsn']).'">'.wp_nonce_field('sdp41_report_'.$d['npsn'],'_wpnonce',true,false).'<label>Atribut <select name="field">';foreach(SDP_Directory::labels() as $k=>$v)if($k!=='npsn')$html.='<option value="'.esc_attr($k).'">'.esc_html($v).'</option>';
        return $html.'</select></label><label>Usulan koreksi<textarea name="message" required maxlength="1500" rows="4"></textarea></label><label>URL sumber (opsional)<input name="source_url" type="url" maxlength="1000"></label><button type="submit">Kirim koreksi</button></form></section>';
    }
    public static function report(){
        if(!is_user_logged_in())wp_die('Masuk terlebih dahulu.','',['response'=>403]);$ids=self::ids(wp_unslash($_POST['npsn']??''),1);$n=$ids[0]??'';check_admin_referer('sdp41_report_'.$n);$r=self::public_rows($ids);if(!$r)wp_die('Profil tidak tersedia.');
        $field=SDP_Portal::clean(wp_unslash($_POST['field']??''),64);$raw=wp_unslash($_POST['message']??'');$msg=is_string($raw)?sanitize_textarea_field($raw):'';$raw=wp_unslash($_POST['source_url']??'');$url=is_string($raw)?esc_url_raw($raw,['http','https']):'';
        $key='sdp41_report_'.get_current_user_id();$ok=false;if(self::install()&&!get_transient($key)&&$field!=='npsn'&&isset(SDP_Directory::labels()[$field])&&strlen($msg)>=10&&strlen($msg)<=6000&&strlen($url)<=1000&&(!$url||preg_match('~^https?://~i',$url))){global $wpdb;$ok=$wpdb->insert(self::reports_table(),['npsn'=>$n,'field_name'=>$field,'message'=>$msg,'source_url'=>$url,'status'=>'pending','created_at'=>current_time('mysql',true)])!==false;if($ok)set_transient($key,1,60);}
        wp_safe_redirect(add_query_arg(['sdp41_sent'=>$ok?'1':'0'],get_permalink((int)$r[0]['post_id'])));exit;
    }
    public static function menu(){add_submenu_page('sdp-directory','Analitik & Koreksi','Analitik & Koreksi','manage_options','sdp-analytics',[__CLASS__,'admin']);}
    public static function admin(){
        if(!current_user_can('manage_options'))return;global $wpdb;echo '<div class="wrap"><h1>Analitik &amp; Koreksi</h1><p>Gunakan shortcode <code>[bandingkan_sekolah]</code>. Buat halaman khusus untuk tombol perbandingan dan favorit. Favorit disimpan di browser pengunjung.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp41_page">';wp_nonce_field('sdp41_page');submit_button('Buat / aktifkan halaman perbandingan');echo '</form>';if(self::compare_url())echo '<p><a href="'.esc_url(self::compare_url()).'">Buka halaman perbandingan</a></p>';
        if(!self::install()){echo '<p>Gagal menyiapkan tabel analitik.</p></div>';return;}$page=max(1,min(10000,absint($_GET['sdp41_page']??1)));$offset=($page-1)*50;$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::reports_table().' ORDER BY id DESC LIMIT 50 OFFSET %d',$offset),ARRAY_A)?:[];
        echo '<h2>Antrean koreksi</h2><p>Periksa sumber, edit sekolah melalui menu Tambah / perbarui bila benar, kemudian tandai laporan selesai atau hapus. Laporan tidak menerbitkan perubahan secara otomatis.</p><table class="widefat striped"><thead><tr><th>NPSN / waktu</th><th>Usulan</th><th>Status / aksi</th></tr></thead><tbody>';
        foreach($rows as $r){echo '<tr><td><a href="'.esc_url(add_query_arg(['page'=>'sdp-edit','npsn'=>$r['npsn']],admin_url('admin.php'))).'">'.esc_html($r['npsn']).'</a><br>'.esc_html($r['created_at']).'</td><td>'.esc_html(SDP_Directory::labels()[$r['field_name']]??$r['field_name']).'<br>'.nl2br(esc_html($r['message'])).'<br>'.esc_html($r['source_url']).'</td><td>'.esc_html($r['status']).'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp41_review"><input type="hidden" name="id" value="'.(int)$r['id'].'">';wp_nonce_field('sdp41_review_'.(int)$r['id']);echo '<button class="button" name="review" value="done">Tandai selesai</button> <button class="button" name="review" value="delete">Hapus</button></form></td></tr>';}
        echo '</tbody></table><p>';if($page>1)echo '<a href="'.esc_url(add_query_arg(['page'=>'sdp-analytics','sdp41_page'=>$page-1],admin_url('admin.php'))).'">Sebelumnya</a> ';if(count($rows)===50)echo '<a href="'.esc_url(add_query_arg(['page'=>'sdp-analytics','sdp41_page'=>$page+1],admin_url('admin.php'))).'">Berikutnya</a>';echo '</p></div>';
    }
    public static function create_page(){if(!current_user_can('manage_options'))wp_die('Akses ditolak.');check_admin_referer('sdp41_page');if(!self::compare_url()){$id=wp_insert_post(['post_type'=>'page','post_title'=>'Bandingkan Sekolah','post_name'=>'bandingkan-sekolah','post_content'=>'[bandingkan_sekolah]','post_status'=>'publish'],true);if(is_wp_error($id))wp_die(esc_html($id->get_error_message()));update_option('sdp41_compare_page',$id);}wp_safe_redirect(admin_url('admin.php?page=sdp-analytics'));exit;}
    public static function review(){if(!current_user_can('manage_options'))wp_die('Akses ditolak.');$id=absint($_POST['id']??0);check_admin_referer('sdp41_review_'.$id);global $wpdb;if(($_POST['review']??'')==='done')$wpdb->update(self::reports_table(),['status'=>'done'],['id'=>$id]);elseif(($_POST['review']??'')==='delete')$wpdb->delete(self::reports_table(),['id'=>$id]);wp_safe_redirect(admin_url('admin.php?page=sdp-analytics'));exit;}
    public static function delete($id,$post=null){if(($post->post_type??'')!==SDP_Directory::TYPE||get_option('sdp41_schema')!==self::VERSION)return;global $wpdb;$n=$wpdb->get_var($wpdb->prepare('SELECT npsn FROM '.SDP_Directory::table().' WHERE post_id=%d',$id));if($n){$wpdb->delete(self::history_table(),['npsn'=>$n]);$wpdb->delete(self::reports_table(),['npsn'=>$n]);}}
    public static function assets($force=false){if(!$force&&!SDP_Performance::assets_needed('analytics'))return; SDP_Portal::assets(true); $root=dirname(__DIR__).'/sekolah-directory-pro.php';wp_enqueue_style('sdp-analytics',plugins_url('assets/analytics.css',$root),['sdp-portal'],SDP_Directory::VERSION);wp_enqueue_script('sdp-analytics',plugins_url('assets/analytics.js',$root),[],SDP_Directory::VERSION,true); }
}
