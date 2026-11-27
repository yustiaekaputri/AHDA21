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
| Registrasi dan email akun | Registrasi melalui portal membuat akun subscriber pada clone terisolasi. Email WordPress diterima melalui SMTP loopback dan tautan penetapan kata sandi dapat digunakan untuk login. Menu SEO & AI menolak akun tersebut dengan HTTP 403. Pengiriman melalui SMTP produksi masih perlu diperiksa. |
| Media sekolah | Unggah JPEG melalui dashboard lulus pada clone loopback dengan gambar sintetis. Mode hemat menolak gambar 4,6 megapiksel dan mengubah gambar 1.800 × 1.000 menjadi WebP 1.000 × 556. Galeri memakai alt text dan lazy loading. Uji URL dengan respons simulasi lulus. Penghapusan terkonfirmasi menghilangkan entri Media Library dan empat file WebP, termasuk turunannya. Pengambilan dari situs sekolah langsung masih perlu diuji dengan media yang berizin. |
| Pembuatan dan pembaruan profil | Katalog memuat 689 NPSN resmi Tasikmalaya. Impor TXT, CSV, dan XLSX telah diuji. Pada pilot terbaru, administrator meninjau dan mengonfirmasi 200 NPSN sebagai draf melalui dashboard. Dasar izin publikasi dan periode data masih perlu diverifikasi. |
| Migrasi dan cadangan | Penggantian URL dan pemulihan 19 tabel serta `wp-content` lulus pada clone terpisah. Katalog 689 NPSN tetap identik, 200 profil tetap draf, dan tidak ada profil terbit. Login HTTPS juga lulus pada uji sebelumnya dengan sertifikat lokal. VPS tujuan belum diuji. |
| Cache Nginx | Snippet cache opsional diuji dengan WordPress dan PHP-FPM di loopback. Halaman statis yang diizinkan memberi MISS lalu HIT. Permintaan dengan cookie, Authorization, Range, query, serta login, portal operator, REST, admin dan SEO & AI melewati cache. Pratinjau draf memberi 200 BYPASS bagi admin dan 404 BYPASS tanpa login. Cache HTML tetap nonaktif pada template bawaan. |
| Integritas setelah pemulihan | `wp sdp readiness --audit` dan `wp sdp integrity-posts` memeriksa seluruh 200 profil dalam delapan batch per arah di mode hemat. Kedua audit tidak menemukan konflik. Pemeriksaan kesiapan melaporkan 21 pemeriksaan lulus dan tidak ada error. Peringatan mencakup URL loopback HTTP, noindex, Safe Mode, dan master wilayah opsional. |
| Konfigurasi staging | `WP_DEBUG` dan `WP_DEBUG_DISPLAY` dimatikan pada clone hasil pemulihan. Pemeriksaan HTTP menunjukkan homepage memakai `noindex, nofollow`, robots.txt membatasi wp-admin, dan sitemap dinonaktifkan. Saat sitemap diaktifkan sementara pada loopback, halaman biasa tercantum dan sitemap profil sekolah tetap mengembalikan 404. Pengindeksan dikembalikan ke nonaktif. Safe Mode aktif dan master wilayah opsional belum dimuat. Ulangi pemeriksaan pada VPS tujuan. |
| Bukti CI dan dashboard | Matriks PHP 8.2 dan 8.3 serta pemeriksaan indeks MySQL 8 lulus untuk revisi fitur terbaru. ChatGPT Sites versi 2 menyediakan dashboard pendamping privat dengan hasil uji dan tangkapan WordPress. [GitHub Actions](https://github.com/yustiaekaputri/AHDA21/actions/runs/45923824227) · [Dashboard](https://ahda21-status.adoredmound8.chatgpt.site) |

Status penerimaan resmi tetap dicatat di [checklist RC6](tests/localwp/acceptance.json). Pilot dan pengujian di atas berlangsung pada clone terisolasi. Verifikasi VPS aktual, izin publikasi dan pemeriksaan lain pada checklist diperlukan sebelum menandai rilis stabil.

## Penerimaan Claude di LocalWP

Alur impor satu sekolah dari sumber pemerintah, pengambilan daftar model, dan pembuatan pratinjau privat telah diuji melalui browser pada WordPress uji. Respons API disimulasikan. Profil NPSN 20210822 mempertahankan ID, isi artikel, sumber, dan status draf setelah pratinjau. Koneksi HTTPS ke Anthropic telah terverifikasi hingga respons autentikasi, dan pengujian akses model serta narasi dengan kunci akun pengguna masih terbuka. [Referensi NPSN](https://referensi.data.kemendikdasmen.go.id/satpen/detail/20210822) · [Panduan pengaturan](README.md#claude-untuk-usulan-narasi)
