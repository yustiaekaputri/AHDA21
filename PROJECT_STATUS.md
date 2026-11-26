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
| GitHub | PR #1 berstatus draft. Pada run awal, integrasi PHP 8.2 dan 8.3, build ZIP, dan unggahan artefak lulus. Formatter dan perbaikan perbandingan mengurangi temuan WPCS menjadi 1.287. CI menolak temuan baru dan menyimpan laporan lengkap. WPCS penuh masih menjadi syarat rilis stabil. [Hasil Actions](https://github.com/yustiaekaputri/AHDA21/actions/runs/45705455267) |
| Pratinjau | Layanan berjalan di Cloud. Lingkungan mencantumkan port 3000 tanpa URL akses. Screenshot Playwright berada di `artifacts/screenshots/`. |

Rilis stabil masih memerlukan triase WPCS, audit keamanan menyeluruh, impor media serta impor skala besar, matriks migrasi dari paket versi lama, uji pengiriman email registrasi, verifikasi staging sitemap/robots, cache proxy dan sanitasi publikasi, serta benchmark dataset lebih besar. Portal menggunakan REST untuk pembaruan daftar. Tidak ditemukan handler plugin `wp_ajax_*` pada pencarian awal. Otomasi jaringan, sumber resmi dan deployment produksi belum diaktifkan.

## Bukti penerimaan terkini

| Pemeriksaan | Bukti dan pekerjaan tersisa |
| --- | --- |
| Paket LocalWP | Akun staging berhasil masuk ke WordPress hasil pemulihan ZIP. Menu katalog, CSV/TXT dan Excel terbuka. Impor paket dan login lewat aplikasi LocalWP pada komputer pengguna masih perlu dikonfirmasi. |
| Pembuatan dan pembaruan profil | Katalog NPSN, CSV, XLSX dan TXT sudah diuji. Pilot Tasikmalaya mencakup 100 NPSN resmi. Impor ulang mempertahankan ID profil, dan seluruh profil uji tetap berstatus draf. Dasar izin publikasi dan periode data masih perlu diverifikasi. |
| Migrasi dan cadangan | Penggantian domain serta pemulihan database dan `wp-content` lulus pada clone Codex Cloud berisi 100 draf. Login HTTPS lulus dengan sertifikat uji. VPS tujuan belum diuji. |
| Integritas setelah pemulihan | `wp sdp readiness --audit` dijalankan sampai cursor habis. Empat batch memeriksa 100 profil, tanpa temuan relasi indeks ke artikel. Pemeriksaan skema dan runtime tidak melaporkan error. |
| Konfigurasi staging | `WP_DEBUG` dan `WP_DEBUG_DISPLAY` dimatikan pada clone hasil pemulihan. Pemeriksaan HTTP menunjukkan homepage memakai `noindex, nofollow`, robots.txt membatasi wp-admin, dan sitemap dinonaktifkan. Saat sitemap diaktifkan sementara pada loopback, halaman biasa tercantum dan sitemap profil sekolah tetap mengembalikan 404. Pengindeksan dikembalikan ke nonaktif. Safe Mode aktif dan master wilayah opsional belum dimuat. Ulangi pemeriksaan pada VPS tujuan. |
| Bukti CI dan dashboard | Matriks PHP 8.2 dan 8.3 lulus untuk perubahan validasi pilot. ChatGPT Sites menyediakan dashboard pendamping privat dengan hasil uji dan tangkapan layar WordPress. [GitHub Actions](https://github.com/yustiaekaputri/AHDA21/actions/runs/45744264690) · [Dashboard](https://ahda21-status.adoredmound8.chatgpt.site) |

Status penerimaan resmi tetap dicatat di [checklist RC6](tests/localwp/acceptance.json). Pilot dan pengujian di atas berlangsung pada clone terisolasi. Verifikasi VPS aktual, izin publikasi dan pemeriksaan lain pada checklist diperlukan sebelum menandai rilis stabil.
