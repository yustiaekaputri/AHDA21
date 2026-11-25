<?php
/**
 * Plugin Name: Sekolah Directory Pro
 * Description: Direktori sekolah Indonesia, impor CSV dan generator profil otomatis berdasarkan NPSN.
 * Version: 10.0.0-rc.4
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Sekolah Directory Project
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;
final class SDP_Directory {
    const VERSION = '10.0.0-rc.4';
    const TYPE = 'sdp_school';
    const SHORTCODE = 'direktori_sekolah';
    const MAX_ROWS = 200;
    public static function table() { global $wpdb; return $wpdb->prefix . 'sdp_schools'; }
    public static function fields() {
        return [
            'Identitas dan kelembagaan' => ['npsn'=>'NPSN','nama'=>'Nama sekolah','jenjang'=>'Jenjang','status'=>'Status sekolah','nss'=>'NSS','nsm'=>'NSM','yayasan'=>'Yayasan','naungan'=>'Naungan','kepala_sekolah'=>'Kepala sekolah','kurikulum'=>'Kurikulum','waktu_belajar'=>'Waktu belajar'],
            'Legalitas dan akreditasi' => ['akreditasi'=>'Akreditasi','tahun_akreditasi'=>'Tahun akreditasi','nomor_sk_akreditasi'=>'Nomor SK akreditasi','sertifikat_url'=>'Tautan sertifikat','sk_pendirian'=>'SK pendirian','tanggal_pendirian'=>'Tanggal pendirian','sk_operasional'=>'SK operasional','tanggal_operasional'=>'Tanggal operasional'],
            'Alamat dan lokasi' => ['alamat'=>'Alamat','desa'=>'Desa / kelurahan','kecamatan'=>'Kecamatan','kabupaten'=>'Kabupaten / kota','provinsi'=>'Provinsi','kode_pos'=>'Kode pos','latitude'=>'Latitude','longitude'=>'Longitude'],
            'Kontak resmi' => ['telepon'=>'Telepon','email'=>'Email','website'=>'Website','media_sosial'=>'Media sosial resmi'],
            'Sejarah, visi dan misi' => ['sejarah'=>'Sejarah','visi'=>'Visi','misi'=>'Misi','tujuan'=>'Tujuan sekolah'],
            'Peserta didik dan tenaga pendidik' => ['tahun_data'=>'Tahun / semester data','jumlah_siswa'=>'Jumlah siswa','siswa_laki_laki'=>'Siswa laki-laki','siswa_perempuan'=>'Siswa perempuan','jumlah_guru'=>'Jumlah guru','jumlah_tendik'=>'Tenaga kependidikan','guru_sertifikasi'=>'Guru bersertifikasi','jumlah_rombel'=>'Rombongan belajar'],
            'Sarana dan prasarana' => ['luas_tanah'=>'Luas tanah (m²)','luas_bangunan'=>'Luas bangunan (m²)','kepemilikan_tanah'=>'Status tanah','ruang_kelas'=>'Ruang kelas','laboratorium'=>'Laboratorium','perpustakaan'=>'Perpustakaan','toilet'=>'Toilet','uks'=>'UKS','tempat_ibadah'=>'Tempat ibadah','lapangan'=>'Lapangan','kondisi_bangunan'=>'Kondisi bangunan','internet'=>'Akses internet','listrik'=>'Sumber listrik','air'=>'Sumber air'],
            'Program, prestasi dan penerimaan' => ['jurusan'=>'Jurusan / kompetensi keahlian','mitra_industri'=>'Mitra industri','ekstrakurikuler'=>'Ekstrakurikuler','prestasi'=>'Prestasi','spmb'=>'Informasi SPMB','kuota'=>'Kuota penerimaan','biaya'=>'Biaya pendidikan','beasiswa'=>'Beasiswa'],
            'Sumber informasi' => ['sumber'=>'Nama sumber','sumber_url'=>'Tautan sumber','tanggal_data'=>'Tanggal data']
        ];
    }
    public static function labels() { return array_merge(...array_values(self::fields())); }
    public static function indexed() { return ['nama','jenjang','status','akreditasi','provinsi','kabupaten','kecamatan']; }
    public static function integers() { return ['tahun_akreditasi','jumlah_siswa','siswa_laki_laki','siswa_perempuan','jumlah_guru','jumlah_tendik','guru_sertifikasi','jumlah_rombel','ruang_kelas','laboratorium','perpustakaan','toilet','kuota']; }
    public static function levels() { return ['PAUD','TK','RA','KB','TPA','SPS','SD','MI','SMP','MTs','SMA','MA','SMK','MAK','SLB','PKBM','SKB','PESANTREN','LAINNYA']; }
    public static function boot() {
        add_action('init',[__CLASS__,'register']);
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_post_sdp_import',[__CLASS__,'import_action']);
        add_action('admin_post_sdp_page',[__CLASS__,'create_page_action']);
        add_action('admin_post_sdp_save_school',[__CLASS__,'save_action']);
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
        add_shortcode(self::SHORTCODE,[__CLASS__,'directory']);
        add_action('deleted_post',[__CLASS__,'delete_post'],10,2);
        add_filter('the_content',[__CLASS__,'protect_school_content'],8);
        add_filter('get_the_excerpt',[__CLASS__,'protect_school_content'],8);
    }
    // School content must not inherit the ordinary editor/author write privileges.
    public static function post_capabilities() {
        $caps=['create_posts'=>'do_not_allow'];
        foreach(['edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts'] as $cap) $caps[$cap]='manage_options';
        return $caps;
    }
    public static function register() {
        register_post_type(self::TYPE,[ 'labels'=>['name'=>'Profil Sekolah','singular_name'=>'Profil Sekolah','add_new_item'=>'Tambah profil (gunakan menu Direktori Sekolah)'], 'public'=>true,'show_in_rest'=>true,'show_in_menu'=>false,'has_archive'=>false,'rewrite'=>['slug'=>'sekolah','with_front'=>false],'supports'=>['title','editor','excerpt','revisions'],'capabilities'=>self::post_capabilities(),'map_meta_cap'=>true ]);
    }
    public static function activate() {
        if (!self::install_schema()) wp_die('Tabel inti SDP belum siap. Periksa izin database dan pulihkan cadangan bila diperlukan.');
        self::register(); flush_rewrite_rules();
    }
    public static function install_schema() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            npsn varchar(8) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            nama varchar(191) NOT NULL,
            jenjang varchar(32) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT '',
            akreditasi varchar(32) NOT NULL DEFAULT '',
            provinsi varchar(120) NOT NULL DEFAULT '',
            kabupaten varchar(120) NOT NULL DEFAULT '',
            kecamatan varchar(120) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (npsn),
            KEY post_id (post_id),
            KEY wilayah (provinsi(60),kabupaten(60),kecamatan(60)),
            KEY jenjang (jenjang),
            KEY status (status),
            KEY akreditasi (akreditasi),
            KEY nama (nama(100))
        ) $collate;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table || $wpdb->last_error) return false;
        $columns=$wpdb->get_col("SHOW COLUMNS FROM `$table`");
        if ($wpdb->last_error||!is_array($columns)||array_diff(array_merge(['npsn','post_id','payload','updated_at'],self::indexed()),$columns)) return false;
        update_option('sdp_db_version',self::VERSION,false);
        return get_option('sdp_db_version')===self::VERSION;
    }
    public static function delete_post($id,$post=null) {
        if (!$post || $post->post_type!==self::TYPE) return;
        global $wpdb; $wpdb->delete(self::table(),['post_id'=>(int)$id],['%d']);
    }
    public static function assets($force=false) { if(!$force&&!SDP_Performance::assets_needed())return; wp_enqueue_style('sdp-directory',plugins_url('assets/directory.css',__FILE__),[],self::VERSION); }
    public static function normalize($raw) {
        $data=[];
        $long=['sejarah','visi','misi','tujuan','spmb','prestasi','ekstrakurikuler','jurusan','mitra_industri','biaya','beasiswa'];
        foreach(self::labels() as $key=>$label) {
            if (!isset($raw[$key]) || !is_scalar($raw[$key])) continue;
            $v=trim((string)$raw[$key]); if ($v==='') continue;
            if (in_array($key,self::integers(),true)) {
                if (!preg_match('/^\d{1,9}$/D',$v)) return new WP_Error('invalid',"$label harus berupa bilangan bulat nonnegatif tanpa pemisah ribuan.");
                $v=(string)(int)$v;
            } elseif (in_array($key,['luas_tanah','luas_bangunan','latitude','longitude'],true)) {
                if (!preg_match('/^-?\d{1,12}(?:\.\d{1,8})?$/D',$v)) return new WP_Error('invalid',"$label harus berupa angka dengan titik desimal.");
                $num=(float)$v;
                if (($key==='latitude' && abs($num)>90)||($key==='longitude' && abs($num)>180)||(str_starts_with($key,'luas_') && $num<0)) return new WP_Error('invalid',"$label di luar rentang yang valid.");
            } elseif (in_array($key,['website','sumber_url','sertifikat_url'],true)) {
                $v=esc_url_raw($v,['http','https']); if (!$v || !preg_match('~^https?://~i',$v)) return new WP_Error('invalid',"$label harus berupa URL HTTP/HTTPS.");
            } elseif ($key==='email') {
                if (!is_email($v)) return new WP_Error('invalid','Email tidak valid.'); $v=sanitize_email($v);
            } else { $v=in_array($key,$long,true)?sanitize_textarea_field($v):sanitize_text_field($v); }
            if ($key==='jenjang') { $levels=array_combine(array_map('strtoupper',self::levels()),self::levels()); $v=$levels[strtoupper($v)]??$v; }
            if ($key==='status') { $v=ucfirst(strtolower($v)); }
            if ($key==='akreditasi') { $v=strtoupper($v); }
            $max=in_array($key,$long,true)?10000:1000;
            if ($key==='nama') $max=191; elseif (in_array($key,['provinsi','kabupaten','kecamatan'],true)) $max=120; elseif (in_array($key,['jenjang','akreditasi'],true)) $max=32; elseif ($key==='status') $max=24;
            if (strlen($v)>$max) return new WP_Error('invalid',"$label terlalu panjang (maksimum $max byte).");
            if ($v!=='') $data[$key]=$v;
        }
        if (!preg_match('/^[A-Z0-9]{8}$/D',strtoupper($data['npsn']??''))) return new WP_Error('invalid','NPSN harus terdiri dari 8 karakter alfanumerik.');
        $data['npsn']=strtoupper($data['npsn']);
        return $data;
    }
    // Imported text is data, not executable WordPress shortcode syntax.
    public static function literal_text($html) { return str_replace(['[',']'],['&#91;','&#93;'],$html); }
    public static function protect_school_content($html,$post=null) {
        return get_post_type($post?:null)===self::TYPE ? self::literal_text($html) : $html;
    }
    public static function value($d,$key) { return isset($d[$key]) && $d[$key]!=='' ? (string)$d[$key] : 'Data belum tersedia'; }
    public static function profile($d) {
        $name=self::value($d,'nama'); $place=implode(', ',array_filter([$d['kecamatan']??'', $d['kabupaten']??'', $d['provinsi']??'']));
        $intro='Berikut profil '. $name .' (NPSN '. $d['npsn'] .')'.($place?' di '.$place:'').'. Informasi disusun berdasarkan data yang tercatat pada direktori.';
        $html='<div class="sdp-profile"><p>'.esc_html($intro).'</p><div class="sdp-metrics">';
        foreach(['akreditasi'=>'Akreditasi','jumlah_siswa'=>'Jumlah siswa','jumlah_guru'=>'Jumlah guru','luas_tanah'=>'Luas tanah (m²)'] as $key=>$label) $html.='<div class="sdp-metric"><small>'.esc_html($label).'</small><strong>'.esc_html(self::value($d,$key)).'</strong></div>';
        $html.='</div><p class="sdp-note">Periode umum tidak menjamin semua atribut berasal dari tahun yang sama. Periksa periode atribut bila tercantum.</p>';
        foreach(self::fields() as $section=>$fields) {
            $html.='<h2>'.esc_html($section).'</h2><div class="sdp-table-wrap"><table class="sdp-table"><tbody>';
            foreach($fields as $key=>$label) {
                $value=self::value($d,$key); $out=nl2br(esc_html($value));
                if (isset($d[$key]) && in_array($key,['website','sumber_url','sertifikat_url'],true)) $out='<a href="'.esc_url($value).'" rel="nofollow noopener">'.esc_html($value).'</a>';
                $out.=esc_html(SDP_Intelligence::period_note($d,$key));
                $html.='<tr><th scope="row">'.esc_html($label).'</th><td>'.$out.'</td></tr>';
            }
            $html.='</tbody></table></div>';
        }
        if(isset($d['latitude'],$d['longitude'])) { $url='https://www.openstreetmap.org/?mlat='.rawurlencode($d['latitude']).'&mlon='.rawurlencode($d['longitude']).'#map=17/'.rawurlencode($d['latitude']).'/'.rawurlencode($d['longitude']); $html.='<p><a href="'.esc_url($url).'">Lihat lokasi pada OpenStreetMap</a></p>'; }
        if(!empty($d['_field_sources'])&&is_array($d['_field_sources'])){
            $groups=[]; $labels=self::labels();
            foreach($d['_field_sources'] as $k=>$src){if(!isset($labels[$k])||!is_array($src))continue;$label=$src['label']??'';$url=$src['url']??'';$key=md5($label.'|'.$url);$groups[$key]['label']=$label;$groups[$key]['url']=$url;$groups[$key]['fields'][]=$labels[$k];}
            if($groups){$html.='<h2>Asal data per kelompok informasi</h2><ul>';foreach($groups as $g){$label=esc_html($g['label']);if($g['url'])$label='<a href="'.esc_url($g['url']).'" rel="nofollow noopener">'.$label.'</a>';$html.='<li>'.$label.': '.esc_html(implode(', ',$g['fields'])).'</li>';}$html.='</ul><p class="sdp-note">Sumber dicatat saat data dimasukkan, bukan bukti verifikasi independen.</p>';}
        }
        return self::literal_text($html.'<p class="sdp-note">Informasi dapat berubah. Data kosong bukan berarti fasilitas atau layanan tersebut tidak ada. Periksa sumber resmi sebelum mengambil keputusan.</p></div>');
    }
    public static function save($raw,$publish=false,$policy='overwrite',$provenance=null,$expected=null,$review=null) {
        global $wpdb; $clean=self::normalize($raw); if(is_wp_error($clean)) return $clean;
        $table=self::table(); $npsn=$clean['npsn'];
        $lock='sdp_'.md5($table.$npsn);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$lock))!=='1') return new WP_Error('busy','Data sedang diproses. Coba lagi.');
        try {
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE npsn=%s",$npsn),ARRAY_A);
            if(!empty($wpdb->last_error))return new WP_Error('database','Gagal membaca indeks sekolah. Data tidak diubah.');
            $old=$existing?json_decode($existing['payload'],true):[];
            if(!is_array($old))return new WP_Error('database','Payload sekolah rusak. Pulihkan data sebelum impor ulang.');
            $relation=SDP_Integrity::check_write($npsn,$existing,$old);if(is_wp_error($relation))return $relation;
            // Enrichment may only update a still-existing, unchanged identity; never recreate a deleted profile.
            if($expected!==null){
                if(!$existing||empty($existing['post_id'])||get_post_type((int)$existing['post_id'])!==self::TYPE)return new WP_Error('identity','Profil asal sudah tidak tersedia.');
                foreach($expected as $k=>$v)if(!isset($old[$k])||$old[$k]!==$v)return new WP_Error('identity','Identitas profil berubah saat diproses.');
                $p=get_post((int)$existing['post_id']);if(!$p||!in_array($p->post_status,['publish','draft','pending'],true)||$p->post_password!=='')return new WP_Error('identity','Status atau privasi profil berubah saat diproses.');
            }
            $guard=SDP_Intelligence::guard($old,$clean,$policy,$review); if(is_wp_error($guard))return $guard;
            $d=$old;
            $sources=is_array($old['_field_sources']??null)?$old['_field_sources']:[];
            foreach($clean as $k=>$v){
                if($policy==='fill' && isset($old[$k]) && $old[$k]!=='')continue;
                if($k!=='npsn' && !in_array($k,['sumber','sumber_url','tanggal_data'],true) && (!isset($old[$k]) || $old[$k]!==$v || !isset($sources[$k]))) {
                    $sources[$k]=['label'=>$provenance['label']??($clean['sumber']??'Input admin / CSV'),'url'=>$provenance['url']??($clean['sumber_url']??''),'retrieved'=>current_time('mysql',true)];
                }
                $d[$k]=$v;
            }
            if($sources)$d['_field_sources']=$sources;
            $d=SDP_Intelligence::record($old,$d,$review,$provenance,$clean,$policy);
            if(empty($d['nama'])) return new WP_Error('missing','Nama sekolah wajib diisi.');
            $id=(int)($existing['post_id']??0);
            $old_status=$id?get_post_status($id):'';
            $status=SDP_SEO::status($d,in_array($old_status,['publish','draft','pending','private','future','trash'],true)?$old_status:'draft',$publish);
            if($provenance!==null && $existing && $id && $id===(int)$existing['post_id'] && $old===$d && $old_status===$status) { SDP_SEO::sync($id,$d); SDP_Analytics::record($clean,$provenance); return ['id'=>$id,'updated'=>true,'unchanged'=>true,'held'=>($publish&&$status!=='publish')]; }
            $post=['post_type'=>self::TYPE,'post_title'=>'Profil '.$d['nama'].' – NPSN '.$npsn,'post_content'=>self::profile($d),'post_excerpt'=>self::literal_text(SDP_SEO::description($d)),'post_status'=>$id?$status:'draft','meta_input'=>['_sdp_npsn'=>$npsn]];
            if($id) $post['ID']=$id; else $post['post_name']=sanitize_title($d['nama'].'-'.$npsn);
            // wp_update_post merges omitted fields from the existing post, including password and author.
            $result=$id?wp_update_post(wp_slash($post),true):wp_insert_post(wp_slash($post),true);
            if(is_wp_error($result)) return $result;
            if(!(int)$result)return new WP_Error('post','WordPress tidak mengembalikan ID profil yang valid.');
            $row=['npsn'=>$npsn,'post_id'=>(int)$result,'payload'=>wp_json_encode($d,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),'updated_at'=>current_time('mysql',true)];
            foreach(self::indexed() as $key) $row[$key]=$d[$key]??'';
            if($wpdb->replace($table,$row)===false) return new WP_Error('database','Artikel tersimpan tetapi indeks gagal. Jangan impor ulang sebelum audit dan pemulihan.');
            // Never publish a new post before the index write succeeds.
            if(!$id && $status!=='draft'){
                $published=wp_update_post(['ID'=>(int)$result,'post_status'=>$status],true);
                if(is_wp_error($published))return $published;
                if(!(int)$published||get_post_status((int)$result)!==$status)return new WP_Error('post','Indeks tersimpan, tetapi status publikasi belum sesuai. Periksa artikel sebelum mencoba lagi.');
            }
            SDP_SEO::sync($result,$d);
            SDP_Analytics::record($clean,$provenance);
            do_action('sdp_school_saved',$npsn);
            return ['id'=>$result,'updated'=>(bool)$id,'held'=>($publish&&$status!=='publish')];
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function menu() {
        add_menu_page('Direktori Sekolah','Direktori Sekolah','manage_options','sdp-directory',[__CLASS__,'admin'],'dashicons-welcome-learn-more',26);
        add_submenu_page('sdp-directory','Impor CSV','Impor CSV','manage_options','sdp-import',[__CLASS__,'admin_import']);
        add_submenu_page('sdp-directory','Tambah / perbarui','Tambah / perbarui','manage_options','sdp-edit',[__CLASS__,'admin_edit']);
        add_submenu_page('sdp-directory','Artikel profil','Artikel profil','manage_options','edit.php?post_type='.self::TYPE);
    }
    private static function guard($nonce) { if(!current_user_can('manage_options')) wp_die('Akses ditolak.'); check_admin_referer($nonce); }
    private static function admin_url($page='sdp-directory') { return admin_url('admin.php?page='.$page); }
    private static function redirect($page,$message,$errors=[]) {
        set_transient('sdp_notice_'.get_current_user_id(),['message'=>$message,'errors'=>array_slice($errors,0,30)],120);
        wp_safe_redirect(self::admin_url($page)); exit;
    }
    private static function notice() {
        $key='sdp_notice_'.get_current_user_id(); $notice=get_transient($key); if(!$notice) return; delete_transient($key);
        echo '<div class="notice '.(!empty($notice['errors'])?'notice-warning':'notice-success').' is-dismissible"><p>'.esc_html($notice['message']).'</p>';
        foreach($notice['errors'] as $error) echo '<p>'.esc_html($error).'</p>';
        echo '</div>';
    }
    public static function admin() {
        if(!current_user_can('manage_options')) return;
        global $wpdb; $table=self::table(); $posts=$wpdb->posts;
        $stats=$wpdb->get_row("SELECT COUNT(*) total, SUM(p.post_status='publish') published, SUM(p.post_status='draft') drafts FROM $table s LEFT JOIN $posts p ON p.ID=s.post_id",ARRAY_A);
        echo '<div class="wrap"><h1>Sekolah Directory Pro <small>v'.esc_html(self::VERSION).'</small></h1>'; self::notice();
        echo '<p>Direktori nasional berbasis NPSN. Impor data yang sah, periksa profil, lalu terbitkan.</p><div style="display:flex;gap:16px;flex-wrap:wrap">';
        foreach(['total'=>'Total sekolah','published'=>'Terbit','drafts'=>'Draf'] as $k=>$label) echo '<div style="background:white;border:1px solid #ccd0d4;padding:16px;min-width:160px"><span>'.esc_html($label).'</span><h2>'.esc_html(number_format_i18n((int)($stats[$k]??0))).'</h2></div>';
        echo '</div><h2>Mulai</h2><p><a class="button button-primary" href="'.esc_url(self::admin_url('sdp-import')).'">Impor CSV</a> <a class="button" href="'.esc_url(self::admin_url('sdp-edit')).'">Tambah sekolah</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type='.self::TYPE)).'">Kelola artikel</a></p>';
        echo '<h2>Halaman pencarian publik</h2><p>Tempel shortcode <code>['.esc_html(self::SHORTCODE).']</code> pada halaman WordPress, atau buat halaman otomatis.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp_page">'; wp_nonce_field('sdp_page'); submit_button('Buat halaman direktori','secondary'); echo '</form>';
        $pid=(int)get_option('sdp_page_id'); if($pid && get_post_status($pid)==='publish') echo '<p><a href="'.esc_url(get_permalink($pid)).'">Buka halaman direktori</a></p>';
        echo '<h2>Sekolah terakhir diperbarui</h2><table class="widefat striped"><thead><tr><th>NPSN</th><th>Nama</th><th>Jenjang</th><th>Wilayah</th><th>Artikel</th></tr></thead><tbody>';
        foreach($wpdb->get_results("SELECT * FROM $table ORDER BY updated_at DESC LIMIT 20",ARRAY_A) as $r) { $edit=add_query_arg('npsn',$r['npsn'],self::admin_url('sdp-edit')); echo '<tr><td><a href="'.esc_url($edit).'">'.esc_html($r['npsn']).'</a></td><td>'.esc_html($r['nama']).'</td><td>'.esc_html($r['jenjang']).'</td><td>'.esc_html($r['kabupaten'].', '.$r['provinsi']).'</td><td>'.esc_html(get_post_status((int)$r['post_id'])?:'Tidak tersedia').'</td></tr>'; }
        echo '</tbody></table><p>Gunakan menu Pengumpul Data untuk mengimpor CSV/JSON dari sumber berizin dan menjalankan sinkronisasi terjadwal.</p></div>';
    }
    public static function create_page_action() {
        self::guard('sdp_page'); $id=(int)get_option('sdp_page_id');
        if($id && get_post_type($id)==='page' && get_post_status($id)!=='trash') self::redirect('sdp-directory','Halaman direktori sudah ada.');
        $id=wp_insert_post(['post_type'=>'page','post_title'=>'Direktori Sekolah Indonesia','post_name'=>'direktori-sekolah','post_content'=>'['.self::SHORTCODE.']','post_status'=>'publish'],true);
        if(is_wp_error($id)) self::redirect('sdp-directory','Gagal membuat halaman.',[$id->get_error_message()]);
        update_option('sdp_page_id',$id); self::redirect('sdp-directory','Halaman direktori berhasil dibuat.');
    }
    public static function admin_import() {
        if(!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Impor CSV sekolah</h1>'; self::notice();
        echo '<p>CSV UTF-8, pemisah koma atau titik koma. Maksimal '.SDP_Resource::cap(self::MAX_ROWS,50).' baris data per impor dan maksimal '.SDP_Resource::cap(5,2).' MiB. Kolom wajib: <code>npsn,nama</code>. NPSN yang sama memperbarui profil lama; nilai kosong tidak menghapus data lama.</p><p><a href="'.esc_url(plugins_url('contoh/template-sekolah.csv',__FILE__)).'">Unduh template kosong</a> | <a href="'.esc_url(plugins_url('contoh/demo-fiktif.csv',__FILE__)).'">Unduh contoh fiktif</a></p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp_import">'; wp_nonce_field('sdp_import');
        echo '<p><input type="file" name="csv" accept=".csv,text/csv" required></p><p><label><input type="checkbox" name="publish" value="1"> Terbitkan profil baru langsung (hanya jika data benar dan boleh dipublikasikan)</label></p><p>Tanpa centang, profil baru menjadi draf. Profil yang sudah terbit tetap terbit saat diperbarui.</p>'; submit_button('Impor dan buat artikel'); echo '</form><h2>Kolom yang didukung</h2><p><code style="overflow-wrap:anywhere">'.esc_html(implode(', ',array_keys(self::labels()))).'</code></p></div>';
    }
    public static function parse_csv($path) {
        $fp=fopen($path,'rb'); if(!$fp) return new WP_Error('file','CSV tidak dapat dibuka.');
        try {
            $first=fgets($fp); if($first===false) return new WP_Error('empty','CSV kosong.'); rewind($fp);
            $first=preg_replace('/^\xEF\xBB\xBF/','',$first);
            $delimiter=substr_count($first,';')>substr_count($first,',')?';':',';
            $header=fgetcsv($fp,0,$delimiter,'"',''); if(!is_array($header)) return new WP_Error('header','Header CSV tidak valid.');
            $header=array_map(static fn($v)=>strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$v))),$header);
            if(count(array_unique($header))!==count($header) || !in_array('npsn',$header,true) || !in_array('nama',$header,true)) return new WP_Error('header','Header harus unik dan memuat npsn,nama.');
            $unknown=array_diff($header,array_keys(self::labels())); if($unknown) return new WP_Error('header','Kolom tidak dikenal: '.implode(', ',$unknown));
            $rows=[]; $line=1;
            while(($values=fgetcsv($fp,0,$delimiter,'"',''))!==false) {
                $line++; if(count($values)===1 && trim((string)$values[0])==='') continue;
                if(count($rows)>=SDP_Resource::cap(self::MAX_ROWS,50)) return new WP_Error('limit','CSV melebihi '.SDP_Resource::cap(self::MAX_ROWS,50).' baris data. Pecah berkas; belum ada baris diimpor.');
                if(count($values)!==count($header)) return new WP_Error('columns',"Rekaman $line memiliki jumlah kolom berbeda. Belum ada baris diimpor.");
                $rows[$line]=array_combine($header,$values);
            }
            return $rows;
        } finally { fclose($fp); }
    }
    public static function import_action() {
        self::guard('sdp_import'); $f=$_FILES['csv']??[];
        $path=$f['tmp_name']??null; $name=$f['name']??null; $size=$f['size']??null;
        if(!is_string($path) || !is_string($name) || !is_int($size) || ($f['error']??1)!==UPLOAD_ERR_OK || !is_uploaded_file($path) || $size>SDP_Resource::cap(5242880,2097152) || strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='csv') self::redirect('sdp-import','Impor gagal.',['Pilih file CSV valid sesuai batas mode aktif.']);
        $rows=self::parse_csv($path); if(is_wp_error($rows)) self::redirect('sdp-import','Impor dibatalkan.',[$rows->get_error_message()]);
        $created=0; $updated=0; $held=0; $errors=[]; $publish=isset($_POST['publish']) && current_user_can('publish_posts');
        $run=function()use($rows,$publish,&$created,&$updated,&$held,&$errors){foreach($rows as $line=>$row) { $r=self::save($row,$publish); if(is_wp_error($r)) $errors[]="Rekaman $line: ".$r->get_error_message(); else {if($r['updated']) $updated++; else $created++; if(!empty($r['held']))$held++;} }};
        $r=SDP_Resource::enabled()?SDP_Collector::lock($run):$run();if(is_wp_error($r))self::redirect('sdp-import','Impor dibatalkan.',[$r->get_error_message()]);
        self::redirect('sdp-import',"Selesai: $created profil baru, $updated diperbarui, $held publikasi ditahan, ".count($errors).' gagal. Maksimal 30 detail kesalahan ditampilkan.',$errors);
    }
    public static function admin_edit() {
        if(!current_user_can('manage_options')) return; global $wpdb;
        $npsn=sanitize_text_field(wp_unslash($_GET['npsn']??'')); $d=[];
        if($npsn!=='') { $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE npsn=%s',strtoupper($npsn)),ARRAY_A); if($row) $d=json_decode($row['payload'],true)?:[]; }
        echo '<div class="wrap"><h1>Tambah / perbarui sekolah</h1>'; self::notice();
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'"><input type="hidden" name="page" value="sdp-edit"><label>Cari NPSN <input name="npsn" value="'.esc_attr($npsn).'"></label> <button class="button">Muat profil</button></form><p>Isi NPSN yang sama untuk memperbarui profil. Nilai kosong tidak menghapus nilai sebelumnya. Perubahan artikel manual akan ditimpa saat regenerasi.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdp_save_school">'; wp_nonce_field('sdp_save_school');
        foreach(self::fields() as $section=>$fields) { echo '<h2>'.esc_html($section).'</h2><table class="form-table">'; foreach($fields as $key=>$label) { $v=$d[$key]??($key==='npsn'?$npsn:''); $id='sdp-'.$key; echo '<tr><th><label for="'.esc_attr($id).'">'.esc_html($label).'</label></th><td>'; if(in_array($key,['visi','misi','sejarah','tujuan','prestasi','spmb','ekstrakurikuler','jurusan','mitra_industri','biaya','beasiswa'],true)) echo '<textarea class="large-text" rows="3" id="'.esc_attr($id).'" name="school['.esc_attr($key).']">'.esc_textarea($v).'</textarea>'; else echo '<input class="regular-text" id="'.esc_attr($id).'" name="school['.esc_attr($key).']" value="'.esc_attr($v).'" '.(in_array($key,['npsn','nama'],true)?'required':'').($key==='npsn'&&!empty($d)?' readonly':'').'>'; echo '</td></tr>'; } echo '</table>'; }
        echo '<p><label><input type="checkbox" name="publish" value="1"> Terbitkan profil baru</label></p>'; submit_button('Simpan dan buat profil'); echo '</form></div>';
    }
    public static function save_action() {
        self::guard('sdp_save_school'); $raw=wp_unslash($_POST['school']??[]); if(!is_array($raw)) self::redirect('sdp-edit','Data tidak valid.');
        $r=self::save($raw,isset($_POST['publish'])&&current_user_can('publish_posts')); if(is_wp_error($r)) self::redirect('sdp-edit','Gagal menyimpan.',[$r->get_error_message()]); self::redirect('sdp-directory',!empty($r['held'])?'Profil disimpan; publikasi ditahan oleh aturan SEO.':'Profil sekolah berhasil disimpan.');
    }
    public static function directory() { return SDP_Portal::render(); }

}
require_once __DIR__.'/includes/class-sdp-integrity.php';
SDP_Directory::boot();
register_activation_hook(__FILE__,['SDP_Directory','activate']);
require_once __DIR__.'/includes/class-sdp-collector.php';
SDP_Collector::boot();
register_activation_hook(__FILE__,['SDP_Collector','install']);
register_deactivation_hook(__FILE__,['SDP_Collector','deactivate']);
register_deactivation_hook(__FILE__,'flush_rewrite_rules');

require_once __DIR__.'/includes/class-sdp-sources.php';
SDP_Sources::boot();

require_once __DIR__.'/includes/class-sdp-seo.php';
require_once __DIR__.'/includes/class-sdp-ai.php';
SDP_SEO::boot();

require_once __DIR__.'/includes/class-sdp-portal.php';
SDP_Portal::boot();

require_once __DIR__.'/includes/class-sdp-analytics.php';
SDP_Analytics::boot();
register_activation_hook(__FILE__,['SDP_Analytics','install']);

require_once __DIR__.'/includes/class-sdp-national.php';
SDP_National::boot();
register_activation_hook(__FILE__,['SDP_National','install']);

require_once __DIR__.'/includes/class-sdp-engine.php';
SDP_Engine::boot();

require_once __DIR__.'/includes/class-sdp-website.php';
SDP_Website::boot();

require_once __DIR__.'/includes/class-sdp-missing.php';
SDP_Missing::boot();

require_once __DIR__.'/includes/class-sdp-intelligence.php';
SDP_Intelligence::boot();

require_once __DIR__.'/includes/class-sdp-media.php';
SDP_Media::boot();

require_once __DIR__.'/includes/class-sdp-operator.php';
SDP_Operator::boot();
register_activation_hook(__FILE__,['SDP_Operator','install']);

require_once __DIR__.'/includes/class-sdp-education.php';
SDP_Education::boot();

require_once __DIR__.'/includes/class-sdp-resource.php';
SDP_Resource::boot();

require_once __DIR__.'/includes/class-sdp-excel.php';
SDP_Excel::boot();

require_once __DIR__.'/includes/class-sdp-region.php';
SDP_Region::boot();

require_once __DIR__.'/includes/class-sdp-discovery.php';
SDP_Discovery::boot();

require_once __DIR__.'/includes/class-sdp-crawler.php';
SDP_Crawler::boot();

require_once __DIR__.'/includes/class-sdp-automation.php';
SDP_Automation::boot();

require_once __DIR__.'/includes/class-sdp-scheduler.php';
SDP_Scheduler::boot();
register_deactivation_hook(__FILE__,['SDP_Scheduler','deactivate']);

require_once __DIR__.'/includes/class-sdp-performance.php';
SDP_Performance::boot();

// Benchmark code is loaded only in WP-CLI; never on a frontend request.
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__.'/includes/class-sdp-benchmark.php';
    WP_CLI::add_command('sdp benchmark',['SDP_Benchmark','cli'],['synopsis'=>'--yes [--repeats=<n>] [--q=<name>] [--provinsi=<name>] [--kabupaten=<name>] [--kecamatan=<name>] [--jenjang=<name>] [--facets]']);
}

// Production checks are loaded only for admin or CLI.
if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
    require_once __DIR__.'/includes/class-sdp-production.php';
    SDP_Production::boot();
}

// Source catalog is admin-only; no frontend hook or cron.
if (is_admin()) {
    require_once __DIR__.'/includes/class-sdp-source-catalog.php';
    SDP_Source_Catalog::boot();
}

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__.'/includes/class-sdp-pilot.php';
    WP_CLI::add_command('sdp pilot-preview',['SDP_Pilot','cli'],['synopsis'=>'<file> --kind=<kind> --sha256=<hash>']);
}

if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command('sdp integrity-posts',['SDP_Integrity','cli'],['synopsis'=>'[--after=<id>]']);
