# Pengujian

## Otomatis

- `npm ci && npx playwright install chromium` menyiapkan browser. Gunakan `SDP_CHROMIUM=/path/to/chromium` bila memakai Chromium sistem.
- `make test` menggunakan Docker Compose, port lokal 3001 dan proyek `sdp-test-*`. Uji menjalankan preflight dan smoke RC6 pada instalasi kosong, deaktivasi/aktivasi, pemulihan opsi dari dump MariaDB, PHPUnit, kemudian pengujian browser dengan fixture WordPress. Volumenya dibuang setelah pengujian.
- `SDP_PHP_VERSION=8.2 make test` menguji PHP 8.2. Target default menggunakan 8.3. WordPress 6.9, MariaDB 10.11, WP-CLI 2.12.0 dan PHPUnit 10.5.67 digunakan dalam konfigurasi.
- `composer install && scripts/lint-analysis.sh` memeriksa WPCS dan PHPStan. WPCS melaporkan utang gaya dan keamanan untuk ditinjau. PHPStan level 0 menggunakan stub WordPress dan WP-CLI dari lockfile.
- `npm run lint`, `python3 -m unittest discover -s tests/simulasi`, dan `python3 tests/localwp/test-runner.py` menjalankan pemeriksaan aset JavaScript dan uji Python. Runner LocalWP membutuhkan PHP dalam PATH.
- `make packages` menghasilkan ZIP instalasi plugin serta tema, tanpa mengubah ZIP asli.

GitHub Actions menjalankan matriks PHP 8.2/8.3 dan mengarsipkan screenshot, laporan dan ZIP. WPCS tetap menghasilkan laporan meskipun masih ada pelanggaran. Versi paket Composer dan npm dikunci dalam lockfile.

## Cakupan

Smoke RC6 menguji blokir data privat/dilindungi sandi, invalidasi cache, role pengguna, REST, audit relasi dan Safe Mode. PHPUnit mencakup ekstensi wajib, skema dan indeks, konversi XLSX → CSV, penyimpanan dan pembaruan, pemulihan indeks hilang, dan query REST. Tes browser memeriksa navigasi, filter wilayah bertingkat, pencarian nama dan NPSN, pagination, profil, perbandingan, formulir akun, dashboard, aksesibilitas dasar, mode gelap, klaim, moderasi, unggah XLSX, konversi unduhan, dan impor CSV melalui administrator.
