<?php
if (!defined('ABSPATH')) exit;
/** Conservative deployment preset. Limits plugin work, not total server traffic. */
final class SDP_Resource {
    public static function enabled(){return !defined('SDP_LOW_RESOURCE') || SDP_LOW_RESOURCE !== false;}
    public static function cap($normal,$low){return self::enabled() ? min($normal,$low) : $normal;}
    public static function scheduled_network(){return !(defined('SDP_SAFE_MODE')&&SDP_SAFE_MODE===true) && (!self::enabled() || (defined('SDP_LOW_RESOURCE_NETWORK_CRON') && SDP_LOW_RESOURCE_NETWORK_CRON === true));}
    public static function revisions($count,$post){return self::enabled() && ($post->post_type??'')===SDP_Directory::TYPE ? ($count<0?2:min(2,$count)) : $count;}
    public static function boot(){
        add_filter('wp_revisions_to_keep',[__CLASS__,'revisions'],10,2);
        add_action('admin_menu',[__CLASS__,'menu'],70);
    }
    public static function menu(){add_submenu_page('sdp-directory','Mode Hemat VPS','Mode Hemat VPS','manage_options','sdp-resource',[__CLASS__,'page']);}
    public static function page(){
        if(!current_user_can('manage_options'))return;
        echo '<div class="wrap"><h1>Mode Hemat VPS — SDP 8.1</h1><p>Mode: <strong>'.(self::enabled()?'Hemat (aktif)':'Standar').'</strong>. Jaringan otomatis: '.(self::scheduled_network()?'diizinkan jika jadwal sumber aktif':'dinonaktifkan').'.</p><p>Target konfigurasi: RAM 1 GB dan CPU terbatas. Batas ini bukan pengukuran kapasitas atau kuota bandwidth server.</p><table class="widefat striped"><tbody>';
        foreach(['Rekaman antrean per putaran'=>self::cap(20,5),'Statistik per putaran'=>self::cap(100,25),'Impor WP-CLI per pemanggilan'=>self::cap(5000,100),'Putaran WP-CLI antrean/statistik'=>self::cap(100,5),'Hasil REST daftar per halaman'=>self::cap(100,20),'Dataset kolektor maksimal (byte)'=>self::cap(5242880,2097152),'Resolusi masukan foto maksimum (piksel total)'=>self::cap(12000000,4000000)] as $k=>$v)echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html((string)$v).'</td></tr>';
        echo '</tbody></table><p>Mode hemat menonaktifkan autocomplete, tetapi pencarian dan filter wilayah tetap tersedia. Impor terjadwal lokal tetap berjalan; pengambilan dari jaringan dijalankan manual secara default. Gunakan LocalWP untuk persiapan dataset dan foto.</p><p>Untuk LocalWP yang lebih kuat, opsional tambahkan <code>define(\'SDP_LOW_RESOURCE\', false);</code> sebelum baris akhir konfigurasi WordPress. Pada VPS biarkan default atau tetapkan <code>true</code>. Untuk mengizinkan jaringan otomatis pada mode hemat, tambahkan <code>define(\'SDP_LOW_RESOURCE_NETWORK_CRON\', true);</code> setelah menilai kuota.</p><p>Jangan menjalankan Ollama di VPS 1 GB. Gunakan cache halaman publik, tidak termasuk portal operator dan halaman akun. Batasi trafik melalui hosting atau reverse proxy; plugin tidak mengukur kuota 10 GB.</p></div>';
    }
}
