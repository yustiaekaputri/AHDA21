=== Sekolah Directory Pro ===
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 10.0.0-rc.4
License: GPLv2 or later

Direktori sekolah Indonesia berbasis NPSN dengan impor CSV, generator profil, dashboard dan pencarian berfilter.

== Perubahan v10.0 RC4 (staging saja) ==
Validasi relasi pada simpan, penahanan artikel baru sebelum indeks berhasil, audit balik artikel ke indeks WP-CLI. Tidak ada pemulihan otomatis. Lihat PANDUAN-V104.txt.

== Perubahan v10.0 RC3 (staging saja) ==
Persiapan pilot CSV per wilayah dan sumber, checksum, preview WP-CLI lokal baca-saja. Tidak ada impor otomatis; lihat PANDUAN-V103.txt.

== Perubahan v10.0 RC2 (staging saja) ==
Katalog sumber nasional admin-only dan validator CSV lokal dengan whitelist kolom, pemeriksaan NPSN, periode, duplikasi dan hash sumber. Tidak ada konektor langsung baru. Lihat PANDUAN-V102.txt.

== Perubahan v10.0 RC1 (staging saja) ==
Hak tulis CPT profil khusus administrator; profil demo melalui mesin save ditahan di luar LocalWP nonindeks. Skrip smoke test WP-CLI terpisah dan checklist penerimaan. Belum diuji pada WordPress/MySQL nyata. Lihat PANDUAN-V100.txt.

== Perubahan v9.0 (kandidat produksi; belum tervalidasi pada WordPress nyata) ==
Pemeriksaan kesiapan server dan schema, audit data terbatas, pemulihan schema inti dengan konfirmasi backup, mode darurat otomatisasi. Lihat PANDUAN-V90.txt.

== Perubahan v8.9 ==
Benchmark lokal melalui CLI dan alat Python terpisah.

== Perubahan v8.8 ==
Cache pilihan filter publik terbatas, aset portal kondisional, cron kolektor sekali jalan sesuai kebutuhan, guard sumber daya dan dashboard performa. Lihat PANDUAN-V88.txt.

== Perubahan v8.7 ==
Scheduler opt-in satu sesi, single event WP-Cron, batas kerja dan anggaran langkah jaringan, guard memori/load, checkpoint dan penghentian untuk peninjauan. Jaringan tetap off pada preset hemat kecuali override eksplisit. Lihat PANDUAN-V87.txt.

== Perubahan v8.6 ==
Kampanye pengayaan multi-sumber manual, seleksi kebutuhan, checkpoint, penguncian kolektor, penelusuran dan pratinjau. Lihat PANDUAN-V86.txt.

== Perubahan v8.5 ==
Penelusuran website resmi sekolah manual: robots.txt, antrean tautan terbatas, checkpoint 24 jam, usulan per halaman ke Website Sekolah v6.1. Tidak ada cron, tabel atau aset frontend tambahan. Lihat PANDUAN-V85.txt.

== Perubahan v8.4 ==
Penemuan website dari tautan pencarian manual atau Brave Search API opsional. Pemeriksaan satu URL HTTPS, skor petunjuk identitas, pratinjau 15 menit, isi website kosong setelah persetujuan admin. Tidak ada cron baru atau perubahan skema. Lihat PANDUAN-V84.txt.

== Perubahan v8.3 ==
Master wilayah opsional Kemendagri dan BPS terpisah per edisi. Impor CSV, validasi induk, normalisasi CSV sekolah sebelum impor. Tidak ada cron baru atau perubahan profil otomatis. Lihat PANDUAN-V83.txt.

== Perubahan v8.2 ==
Impor Excel: konversi XLSX terbatas melalui menu Impor Excel, lanjutkan impor CSV melalui Pengumpul Data. Perlu ZipArchive dan XMLReader. Konverter Python lokal disertakan. Lihat PANDUAN-V82.txt. Tidak ada proses baru otomatis.

== Perubahan v8.1: mode hemat VPS (default) ==
Baca PANDUAN-V81.txt; batas versi sebelumnya dalam bagian historis tidak berlaku jika mode hemat aktif. Mode hemat: 50 baris impor CSV langsung, kolektor 1.000 rekaman / 2 MiB, antrean 5 rekaman, statistik 25 rekaman, impor CLI 100 baris, CLI statistik/antrean 5 putaran, API daftar 20 hasil, media 1 MiB / 4 megapiksel. Autocomplete dan Ollama nonaktif. Pengambilan jaringan otomatis nonaktif, pemrosesan antrean lokal tetap dijadwalkan. Untuk LocalWP yang cukup kuat, define('SDP_LOW_RESOURCE', false); di wp-config.php. Mode ini bukan jaminan pemenuhan kuota bandwidth atau performa server. Tidak ada perubahan skema baru.

== Instalasi LocalWP ==
1. Buka LocalWP, jalankan situs WordPress (Start site), lalu buka WP Admin.
2. Plugins > Add New Plugin > Upload Plugin. Pilih ZIP sekolah-directory-pro-v8.6.0.zip, Install Now, Activate.
3. Menu Direktori Sekolah > Buat halaman direktori. Alternatif: buat halaman dan isi [direktori_sekolah].
4. Menu Direktori Sekolah > Impor CSV. Unduh template kosong atau demo-fiktif.csv.
5. Untuk uji lokal saja, unggah demo sebagai draf. Aturan SEO menahan publikasi data fiktif; untuk uji tampilan, terbitkan beberapa draf secara manual hanya pada situs lokal privat. Semua lima profil demo fiktif, bukan data resmi. Jangan gunakan di produksi.
6. Buka halaman direktori, cari nama atau NPSN dan terapkan kombinasi filter. Versi 4.0 memperbarui filter secara interaktif jika REST tersedia, dengan formulir GET sebagai cadangan.
7. Uji pembaruan: edit jumlah_siswa salah satu baris, impor ulang; jumlah profil tetap 5 dan profil terkait berubah.
8. Hapus seluruh profil fiktif sebelum mengimpor data nyata (hapus permanen dari Trash agar indeks ikut dihapus).
9. Jika URL profil 404, buka Settings > Permalinks > Save Changes. Tema WordPress menentukan layout halaman luar.

== CSV ==
UTF-8, koma atau titik koma, maksimum 200 rekaman dan 5 MB per berkas. Header wajib npsn,nama. Kolom lain opsional; template memuat 68 kolom. Nilai kosong mempertahankan nilai lama. NPSN 8 karakter alfanumerik (simpan sebagai teks). Bilangan tanpa pemisah ribuan; desimal memakai titik. URL harus HTTP/HTTPS. Jangan impor data pribadi siswa. Gunakan nama wilayah konsisten dan data yang sah. Tidak ada pencocokan kode wilayah otomatis. Data baru menjadi draf kecuali opsi publikasi dipilih. Data yang sudah terbit tetap terbit ketika diperbarui. Impor yang terhenti dapat diulang berdasarkan NPSN; cek kesalahan di halaman impor. Gunakan data yang sudah diverifikasi karena sistem tidak memverifikasi fakta terhadap sumber eksternal.

== Perilaku dan batasan ==
Profil merupakan Custom Post Type sdp_school, bukan Post biasa. URL /sekolah/nama-npsn/ jika pretty permalinks diaktifkan. Database khusus {prefix}sdp_schools; indeks NPSN unik. Isi artikel digenerasi ulang pada impor/pembaruan melalui menu plugin, sehingga edit langsung artikel akan ditimpa. Field kosong ditampilkan sebagai Data belum tersedia. Penghapusan permanen profil menghapus indeks sekolah; Trash tidak ditampilkan di direktori publik. Menonaktifkan plugin mempertahankan data. Cadangkan WordPress dan database sebelum pengembangan lanjutan.
Versi 1.0 tidak mempunyai pengambil data pemerintah, sinkronisasi terjadwal, API AI, antrean impor skala nasional, verifikasi sumber otomatis, peta interaktif, riwayat statistik atau fitur klaim sekolah. Filter wilayah bukan dropdown AJAX; perlu menerapkan filter. Belum ada pengujian integrasi di WordPress/LocalWP atau benchmark nasional. Pengujian yang dilakukan: lint PHP dan unit smoke test dengan stub WordPress untuk parser CSV, validasi dan generator HTML. MySQL/MariaDB diperlukan; GET_LOCK digunakan untuk mengurangi duplikasi saat simpan bersamaan. Uji di LocalWP sebelum produksi.

== Versi 2.0.0 ==
Lihat PANDUAN-V2.txt untuk upgrade, pengumpul data CSV/JSON URL publik HTTPS atau unggahan lokal, pratinjau, alias/mapping kolom, filter, antrean persisten, WP-Cron, pencatatan sumber per atribut, dan batasan. Keterangan tentang versi 1.0 di atas adalah historis. Tidak ada konektor khusus situs pemerintah yang telah diuji atau disertakan.

== Versi 2.1.0 ==
Lihat PANDUAN-V21.txt. Multi-sumber hingga 30 konfigurasi, preset statistik/akreditasi/madrasah, filter wilayah pelengkap berdasarkan profil lama, parser profil NPSN eksperimental dengan pratinjau. Belum diuji pada instalasi WordPress nyata atau respons HTTP langsung.

== Versi 3.0.0 ==
Lihat PANDUAN-V3.txt. SEO native, FAQ, schema, indeks kesiapan, halaman wilayah, asisten AI privat opsional.

== Versi 4.0.0 ==
Lihat PANDUAN-V4.txt. Portal AJAX, filter bertingkat, autocomplete awalan nama/NPSN, grafik profil, plot koordinat tanpa basemap, statistik jumlah profil per jenjang, dan breadcrumb. Tidak ada perubahan skema database.

== Versi 4.1.0 ==
Lihat PANDUAN-V41.txt untuk perbandingan, favorit lokal, riwayat observasi periode, filter fasilitas dan jurusan, rekomendasi koordinat, dan moderasi koreksi. Dua tabel tambahan dibuat tanpa mengubah tabel sekolah.

== Versi 5.0.0 ==
Lihat PANDUAN-V5.txt: WP-CLI CSV streaming dengan checkpoint, indeks opsional, API publik cursor, dashboard kesehatan dan klaim termoderasi. Belum benchmark skala nasional.

== 5.1.0 ==
Perlindungan shortcode pada profil, pemeliharaan status privat/trash/future dan password saat impor, penanganan kegagalan database pencarian/antrean, serta larangan redirect otomatis URL dataset. Lihat PANDUAN-V51.txt.

== Versi 6.0.0 ==
Lihat PANDUAN-V60.txt. Kampanye pengayaan profil NPSN yang telah diimpor, filter wilayah, checkpoint, jeda dan log, akses otomatis opt-in. Parser sumber tetap eksperimental. Tidak ada API pemerintah baru atau penemuan NPSN otomatis.

== Versi 6.1.0 ==
Lihat PANDUAN-V61.txt. Kolektor satu halaman website sekolah terdaftar, HTTPS domain persis, pratinjau privat, peninjauan admin dan pengisian kolom kosong dengan sumber per atribut. Eksperimental, bukan crawler otomatis; tidak ada integrasi jaringan nyata yang diuji.

== Versi 6.2.0 ==
Lihat PANDUAN-V62.txt. Audit lokal per batch 1–100 profil, statistik 68 atribut, urutan prioritas, umur perubahan lokal, rekomendasi sumber, ekspor CSV dan pratinjau kandidat Mesin Pengayaan. Tidak ada pemindaian nasional penuh atau permintaan jaringan baru.

== Versi 6.3.0 ==
Lihat PANDUAN-V63.txt. Peninjauan bukti per atribut, kunci nilai terverifikasi, riwayat perubahan terbatas, prioritas kategori sumber dan periode, serta skor kelengkapan heuristik berdasarkan jenjang.

== Versi 6.4.0 ==
Media Sekolah: unggah foto berizin atau ambil URL gambar HTTPS satu per satu dari domain website resmi tercatat. Untuk profil nyata terbit tanpa sandi; maksimum 12 gambar per sekolah, 5 MB, 12 MP, 5.000 piksel per sisi. PHP GD wajib. Raster baru maksimal 800 px (logo) atau 1.600 px (foto); WebP jika didukung GD, jika tidak JPEG/PNG. Tidak menyimpan file sumber ke uploads. Hak, kredit, sumber dan catatan izin disimpan sebagai metadata attachment. Galeri dengan lazy loading, atribusi, placeholder, satu logo dan satu foto utama; featured image mengikuti dukungan tema. Tidak menimpa thumbnail lama. File Media Library bersifat publik; jangan gunakan foto pribadi atau tanpa izin. Tidak ada crawler, pencarian gambar, atau verifikasi independen lisensi. Hapus file melalui Media Library jika izin ditarik atau profil diprivatkan; cache/cadangan mungkin masih menyimpan salinan. Lihat PANDUAN-V64.txt. Pengujian 381 pemeriksaan simulasi; GD/WordPress/LocalWP nyata belum diuji.

== Versi 7.1.0 ==
Portal operator frontend dengan akun WordPress, klaim yang sudah disetujui, antrean perubahan satu atribut, bukti dan periode, moderasi administrator melalui Data Intelligence Engine, usulan media berupa URL tanpa unduhan otomatis, riwayat terbatas, serta noindex dan permintaan pengecualian cache. Lihat PANDUAN-V71.txt. Tema v7.0 terpisah dan tidak perlu diganti. Status beta; wajib diuji WordPress/MySQL/LocalWP nyata sebelum digunakan publik.

== Versi 8.0.0 ==
Statistik pendidikan: snapshot agregat bertahap atas profil publik, filter wilayah/jenjang, periode eksplisit per atribut, distribusi, fasilitas, kelengkapan relevan, rasio siswa/guru pada subset periode cocok, ekspor CSV, shortcode [statistik_pendidikan slot="1"] dan [perbandingan_periode_pendidikan slots="1,2"], serta WP-CLI wp sdp education. Maksimum 10 snapshot, 100 rekaman per batch, satu pekerjaan aktif. Statistik merupakan cakupan direktori yang telah dipindai, bukan sensus resmi. Lihat PANDUAN-V80.txt untuk metodologi, batasan, instalasi dan pengujian.

== Versi 8.9.0 ==
Benchmark lokal WP-CLI opt-in, generator CSV fiktif streaming, dan penguji HTTP loopback terpisah. Lihat PANDUAN-V89.txt. Tidak ada cron, endpoint, tabel atau aset frontend baru. Hasil uji simulasi bukan ukuran kapasitas VPS.
