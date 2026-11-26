# Deployment

## Lokal dan CI

`make start` menyiapkan WordPress, Apache, PHP dan MariaDB lewat Docker Compose. Port pratinjau default hanya mendengarkan loopback. Kontainer WordPress dan MariaDB berjalan setelah terminal ditutup dan Docker memulai ulang layanan setelah aktif kembali. Gunakan `make stop` untuk menghentikan pratinjau. Jalankan `make start` kembali setelah mengubah sumber agar image memuat revisi terbaru. Gunakan `make test` untuk instalasi uji terpisah. Jaga file `.env` dalam workspace lokal. Skrip membuat kata sandi acak dan Git mengabaikan file itu.

## Staging dan produksi

Konfigurasi contoh VPS RC5 tersedia di [deployment/vps](deployment/vps/README.txt). Gunakan preset 1 GB untuk staging awal dan konfirmasi kapasitas CPU, penyimpanan dan bandwidth sebelum penalaan lebih lanjut. Pertahankan Safe Mode saat memverifikasi skema, indeks, laporan reverse audit, cadangan dan pemulihan. Portal operator dan wp-admin memerlukan autentikasi serta harus dikecualikan dari cache halaman bersama.

Gunakan ZIP hasil build pada clone staging terlebih dahulu. Simpan dump database dan cadangan file sebelum migrasi. Verifikasi hasil restore pada database tersendiri dan gunakan prosedur kembali ke versi sebelumnya yang telah diperiksa. Jangan ekspor data demo atau akun uji ke produksi.

## Pindahkan situs LocalWP ke staging VPS

1. Pilih **Export** pada situs Local yang berisi hasil kerja. Simpan cadangan di tempat privat. Pastikan arsip menyertakan `wp-content` dan dump SQL lengkap. Ekspor konten XML WordPress tidak membawa katalog NPSN di opsi situs dan tabel indeks Sekolah Directory Pro.
2. Siapkan situs staging berautentikasi dengan database kosong khusus, HTTPS, dan `wp-config.php` milik VPS. Cocokkan `$table_prefix` dengan awalan tabel pada dump serta gunakan kredensial dan salt baru. Setelah mengatur koneksi database tujuan, pulihkan `wp-content` dan impor dump ke database staging.
3. Dari direktori WordPress tujuan, periksa penggantian URL. Gunakan URL asal dan tujuan yang sebenarnya. Jalankan kembali perintah tanpa `--dry-run` setelah hasilnya sesuai, lalu pastikan `wp option get home` dan `wp option get siteurl` menunjukkan domain tujuan.

   ```sh
   wp search-replace 'http://ahda21-staging.local' 'https://staging.example.com' \
     --all-tables-with-prefix --skip-columns=guid --dry-run
   ```

4. Rotasi kata sandi administrator hasil impor dan periksa pengaturan HTTPS, cache, serta Safe Mode. Uji jumlah rekaman katalog, tautan sumber, pencarian NPSN dan halaman profil. Pastikan draf tetap tersembunyi bagi pengunjung dan profil terbit memakai tautan domain tujuan. Simpan backup sebelum mengubah data di tujuan. Gunakan salinan database dan `wp-content` dari satu waktu pencadangan saat memulihkan.
