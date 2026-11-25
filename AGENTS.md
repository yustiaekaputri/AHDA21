# Panduan kerja

- Pertahankan lima ZIP asli sebagai arsip. Ubah sumber di direktori yang sesuai.
- Gunakan data fiktif untuk pengujian dan jangan commit kredensial WordPress atau VPS.
- Jalankan pemeriksaan pada `.github/workflows/tests.yml` untuk perubahan plugin, tema, atau pengujian.

## Produk dan data

- Lanjutkan plugin WordPress Sekolah Directory Pro dan tema yang ada. Pelihara modul impor, sumber nasional, NPSN, penemuan situs, crawler, media, wilayah, statistik, portal operator, dan scheduler.
- Utamakan bahasa Indonesia, aksesibilitas, tampilan mobile-first, SEO, dan JavaScript ringan. Gunakan browser nyata untuk menguji perubahan antarmuka.
- Pakai sumber resmi dan operator yang telah diverifikasi sesuai izin. Simpan asal, periode, tanggal, dan status verifikasi. Tampilkan “Data belum tersedia” bila sumber belum tersedia. Hormati robots.txt, hak akses, batas jaringan, hak cipta, dan privasi siswa.
- Dataset fiktif hanya untuk pengembangan atau pengujian terisolasi. Beri label DEMO atau FIKTIF. Jaga portal dan panel administrator di balik autentikasi.

## Pengujian dan rilis

- Jalankan WordPress 6.4+ dengan PHP 8.2/8.3 dan MariaDB 10.11 atau MySQL 8 dalam pengembangan. Gunakan Docker Compose atau lingkungan nyata yang setara jika batas runtime menghalangi build.
- Pisahkan database pengembangan, uji, dan produksi. Jalankan lint, WPCS, PHPStan, PHPUnit, WP-CLI, uji Python, Playwright, pemindaian dependensi, dan build paket. Simulasi tidak menggantikan pengujian integrasi nyata.
- Periksa skema dan upgrade, impor CSV/XLSX, identitas NPSN, indeks dan relasi artikel, REST dan AJAX yang benar-benar terdaftar, otorisasi, nonce, klaim dan moderasi, statistik, SEO, cache, scheduler, Safe Mode, serta pemulihan cadangan.
- Simpan log, hasil pengujian dan tangkapan browser 390×844, 768×1024, dan 1440×900 di artifacts. Laporkan hasil yang lulus, gagal, dan tertunda secara terpisah.
- Buat branch dan PR untuk perubahan penting. Pertahankan riwayat Git dan versi sampai ada dasar penerbitan. Deployment produksi memerlukan persetujuan. Jangan merge otomatis ketika pengujian gagal.
- Audit SQL injection, XSS, CSRF, SSRF, unggahan, traversal path, autentikasi dan otorisasi, kebocoran cache, rahasia dan privasi. Simpan kredensial di lingkungan lokal atau secret store.

## Performa

- Pertahankan mode hemat default dan mode standar yang dapat diaktifkan setelah upgrade. Target awal VPS 1 GB RAM, bandwidth 10 GB, dan 1 vCPU yang perlu dikonfirmasi.
- Muat aset dan modul sesuai kebutuhan. Gunakan indeks, cache terbatas dengan invalidasi, pagination database, batching, checkpoint, dan cron sesuai permintaan. Hentikan penjadwalan saat antrean kosong.
- Ukur memori PHP, query, waktu respons, dan ukuran respons melalui data fiktif bertahap di database lokal. Catat kondisi pengukuran. Jangan menjalankan benchmark berat di VPS produksi.
- Redis, Elasticsearch, Ollama, dan AI berbayar bersifat opsional. Pertahankan jalur inti tanpa ketergantungan layanan tersebut.
