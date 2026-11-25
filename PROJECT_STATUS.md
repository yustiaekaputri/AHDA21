# Status proyek

Plugin tetap 10.0.0 RC4 dan tema 7.0.0. Pekerjaan ini sudah menghasilkan WordPress nyata dengan 31 profil DEMO, rangkaian pengujian integrasi dan browser, serta paket instalasi. Temuan klaim demo yang tidak kompatibel dengan portal diperbaiki. Tema tetap menggunakan WordPress, dan navigasi mobile diringkas dengan CSS. Metadata unggahan CSV dan XLSX divalidasi sebelum pemrosesan.

| Area | Status |
| --- | --- |
| Runtime yang diuji | WordPress 6.9 dan MariaDB 10.11.19. RC6 dan PHPUnit dijalankan di PHP 8.2.32 dan 8.3.6. Browser dijalankan pada kedua versi PHP dengan Nginx. |
| Docker Compose | `scripts/test.sh` lulus pada PHP 8.3 dengan Apache dan MariaDB. PHP 8.2 telah diuji pada runtime langsung dengan Nginx. |
| Integrasi | RC6 lulus 40 pemeriksaan per versi. PHPUnit lulus 5 tes dan 43 asersi per versi. Deaktivasi/aktivasi, impor CSV dengan checkpoint serta snapshot/pemulihan MariaDB diuji. |
| Browser | 9 skenario lulus per versi termasuk tampilan tiga ukuran, pencarian/filter/pagination, login, tampilan formulir registrasi, administrasi, aksesibilitas dasar, mode gelap, klaim, moderasi, dan unggah XLSX → CSV serta impor draf. |
| Kualitas sumber | Lint PHP dan JavaScript, PHPStan level 0, tes simulasi PHP, 30 tes Python, serta 5 tes runner lulus. WPCS masih melaporkan pelanggaran. Audit dependensi tidak melaporkan advisori. |
| Performa | 100, 1.000 dan 10.000 profil FIKTIF diuji. Latensi direktori tanpa filter naik tajam pada 10.000 profil. Lihat [PERFORMANCE.md](PERFORMANCE.md). |
| GitHub | Branch dan workflow tersedia lokal. Integrasi GitHub tidak menemukan `yustiaekaputri/AHDA21`. PR dan eksekusi Actions cloud tertunda. |
| Pratinjau | Layanan berjalan di Cloud. Lingkungan mencantumkan port 3000 tanpa URL akses. Screenshot Playwright berada di `artifacts/screenshots/`. |

Rilis stabil masih memerlukan triase WPCS, audit keamanan menyeluruh, impor media serta impor skala besar, matriks migrasi dari paket versi lama, uji pengiriman email registrasi, verifikasi staging sitemap/robots, cache proxy dan sanitasi publikasi, serta benchmark dataset lebih besar. Portal menggunakan REST untuk pembaruan daftar. Tidak ditemukan handler plugin `wp_ajax_*` pada pencarian awal. Otomasi jaringan, sumber resmi dan deployment produksi belum diaktifkan.

Tidak ada data sekolah produksi yang dimasukkan. Lakukan pilot sumber resmi dengan bukti izin dan metadata asal, periode, tanggal dan status verifikasi sebelum impor nyata.
