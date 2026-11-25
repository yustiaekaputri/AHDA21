<?php
if (!defined('ABSPATH')) exit;
final class SDP_Source_Catalog {
    public static function boot(){add_action('admin_menu',[__CLASS__,'menu'],22);}
    public static function menu(){add_submenu_page('sdp-directory','Validasi Sumber Nasional','Validasi Sumber Nasional','manage_options','sdp-source-catalog',[__CLASS__,'page']);}
    public static function entries(){return [
        ['Data Induk Kemendikdasmen','https://data.kemendikdasmen.go.id/data-induk/satpen/daftar','Identitas per NPSN','Unduh manual; pastikan provinsi dan bentuk pendidikan tersedia.'],
        ['Referensi Data Pendidikan','https://referensi.data.kemendikdasmen.go.id/satpen/cari','Verifikasi per NPSN','Pencarian publik; konektor lama eksperimental, tidak divalidasi ulang.'],
        ['Dataset Kemendikdasmen','https://data.kemendikdasmen.go.id/dataset','Statistik','Hanya data per NPSN boleh memperbarui profil sekolah.'],
        ['Dapodik','https://dapo.kemendikdasmen.go.id/','Pendataan','Gunakan ekspor yang diizinkan; jangan mengakses data individual.'],
        ['Satu Data Kemenag','https://satudata.kemenag.go.id/','Madrasah dan pendidikan keagamaan','Periksa metadata, granularitas dan izin setiap dataset.'],
        ['BAN-PDM','https://ban-pdm.id/','Akreditasi','Periksa NPSN, keputusan dan tahun; konversi dokumen secara manual.'],
        ['PELITA Kemendagri','https://pelita.kemendagri.go.id/','Wilayah administratif','Gunakan Master Wilayah, bukan importer profil.'],
        ['BPS','https://www.bps.go.id/','Wilayah statistik dan agregat','Pisahkan sistem kode BPS dari Kemendagri.']
    ];}
    public static function page(){if(!current_user_can('manage_options'))return;
        echo '<div class="wrap"><h1>Validasi Sumber Nasional — RC3</h1><p>Pilot lokal: gunakan tools/prepare_pilot.py dan wp sdp pilot-preview sesuai PANDUAN-V103.txt. Katalog referensi, bukan status koneksi langsung atau izin publikasi. Tidak ada pengunduhan otomatis dari menu ini.</p><table class="widefat striped"><thead><tr><th>Sumber</th><th>Cakupan</th><th>Jalur penggunaan</th></tr></thead><tbody>';
        foreach(self::entries() as $e)echo '<tr><td><a href="'.esc_url($e[1]).'" target="_blank" rel="noopener noreferrer">'.esc_html($e[0]).'</a></td><td>'.esc_html($e[2]).'</td><td>'.esc_html($e[3]).'</td></tr>';
        echo '</tbody></table><h2>Prosedur sebelum impor</h2><ol><li>Unduh dataset institusi yang diizinkan. Catat URL sumber, tanggal/edisi dan dasar izin publikasi.</li><li>Jika XLSX, konversi dengan Impor Excel atau alat lokal, lalu periksa header.</li><li>Jalankan tools/validate_source.py secara lokal pada CSV per wilayah (maksimal 1.000 baris/2 MiB). Simpan laporan JSON bersama file asal.</li><li>Periksa hasil dan bandingkan sampel NPSN terhadap sumber resmi. Jangan menganggap validasi format sebagai verifikasi fakta.</li><li>Gunakan Sumber Resmi dengan jenis dataset yang sesuai, mode isi kosong, jadwal mati dan publikasi langsung mati. Impor pelengkap hanya setelah identitas tersedia.</li></ol><p><a href="'.esc_url(admin_url('admin.php?page=sdp-sources')).'">Sumber Resmi</a> | <a href="'.esc_url(admin_url('admin.php?page=sdp-collector')).'">Pengumpul Data</a></p></div>';
    }
}
