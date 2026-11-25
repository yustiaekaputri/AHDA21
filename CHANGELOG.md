# Changelog

Catatan versi berikut berasal dari dokumentasi plugin.

## Perubahan v10.0 RC4 (staging saja)
Validasi relasi pada simpan, penahanan artikel baru sebelum indeks berhasil, audit balik artikel ke indeks WP-CLI. Tidak ada pemulihan otomatis. Lihat PANDUAN-V104.txt.

## Perubahan v10.0 RC3 (staging saja)
Persiapan pilot CSV per wilayah dan sumber, checksum, preview WP-CLI lokal baca-saja. Tidak ada impor otomatis; lihat PANDUAN-V103.txt.

## Perubahan v10.0 RC2 (staging saja)
Katalog sumber nasional admin-only dan validator CSV lokal dengan whitelist kolom, pemeriksaan NPSN, periode, duplikasi dan hash sumber. Tidak ada konektor langsung baru. Lihat PANDUAN-V102.txt.

## Perubahan v10.0 RC1 (staging saja)
Hak tulis CPT profil khusus administrator; profil demo melalui mesin save ditahan di luar LocalWP nonindeks. Skrip smoke test WP-CLI terpisah dan checklist penerimaan. Belum diuji pada WordPress/MySQL nyata. Lihat PANDUAN-V100.txt.

## Perubahan v9.0 (kandidat produksi; belum tervalidasi pada WordPress nyata)
Pemeriksaan kesiapan server dan schema, audit data terbatas, pemulihan schema inti dengan konfirmasi backup, mode darurat otomatisasi. Lihat PANDUAN-V90.txt.

## Perubahan v8.9
Benchmark lokal melalui CLI dan alat Python terpisah.

## Perubahan v8.8
Cache pilihan filter publik terbatas, aset portal kondisional, cron kolektor sekali jalan sesuai kebutuhan, guard sumber daya dan dashboard performa. Lihat PANDUAN-V88.txt.

## Perubahan v8.7
Scheduler opt-in satu sesi, single event WP-Cron, batas kerja dan anggaran langkah jaringan, guard memori/load, checkpoint dan penghentian untuk peninjauan. Jaringan tetap off pada preset hemat kecuali override eksplisit. Lihat PANDUAN-V87.txt.

## Perubahan v8.6
Kampanye pengayaan multi-sumber manual, seleksi kebutuhan, checkpoint, penguncian kolektor, penelusuran dan pratinjau. Lihat PANDUAN-V86.txt.

## Perubahan v8.5
Penelusuran website resmi sekolah manual: robots.txt, antrean tautan terbatas, checkpoint 24 jam, usulan per halaman ke Website Sekolah v6.1. Tidak ada cron, tabel atau aset frontend tambahan. Lihat PANDUAN-V85.txt.

## Perubahan v8.4
Penemuan website dari tautan pencarian manual atau Brave Search API opsional. Pemeriksaan satu URL HTTPS, skor petunjuk identitas, pratinjau 15 menit, isi website kosong setelah persetujuan admin. Tidak ada cron baru atau perubahan skema. Lihat PANDUAN-V84.txt.

## Perubahan v8.3
Master wilayah opsional Kemendagri dan BPS terpisah per edisi. Impor CSV, validasi induk, normalisasi CSV sekolah sebelum impor. Tidak ada cron baru atau perubahan profil otomatis. Lihat PANDUAN-V83.txt.

## Perubahan v8.2
Impor Excel: konversi XLSX terbatas melalui menu Impor Excel, lanjutkan impor CSV melalui Pengumpul Data. Perlu ZipArchive dan XMLReader. Konverter Python lokal disertakan. Lihat PANDUAN-V82.txt. Tidak ada proses baru otomatis.

## Perubahan v8.1: mode hemat VPS (default)
Baca PANDUAN-V81.txt; batas versi sebelumnya dalam bagian historis tidak berlaku jika mode hemat aktif. Mode hemat: 50 baris impor CSV langsung, kolektor 1.000 rekaman / 2 MiB, antrean 5 rekaman, statistik 25 rekaman, impor CLI 100 baris, CLI statistik/antrean 5 putaran, API daftar 20 hasil, media 1 MiB / 4 megapiksel. Autocomplete dan Ollama nonaktif. Pengambilan jaringan otomatis nonaktif, pemrosesan antrean lokal tetap dijadwalkan. Untuk LocalWP yang cukup kuat, define('SDP_LOW_RESOURCE', false); di wp-config.php. Mode ini bukan jaminan pemenuhan kuota bandwidth atau performa server. Tidak ada perubahan skema baru.

## Pengembangan RC4

- Siapkan konfigurasi WordPress–MariaDB, data DEMO terisolasi, rangkaian PHPUnit dan Playwright, serta CI dua versi PHP.
- Selaraskan kelayakan klaim dengan aturan portal operator agar admin tidak bisa menyetujui klaim profil demo yang ditolak modul peninjauan.
- Validasi metadata unggahan CSV dan XLSX sebelum pemrosesan dan ringkas navigasi tema pada layar kecil.
