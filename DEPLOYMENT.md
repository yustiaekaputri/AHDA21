# Deployment

## Lokal dan CI

`make start` menyiapkan WordPress, Apache, PHP dan MariaDB lewat Docker Compose. Port pratinjau default hanya mendengarkan loopback. Kontainer WordPress dan MariaDB berjalan setelah terminal ditutup dan Docker memulai ulang layanan setelah aktif kembali. Gunakan `make stop` untuk menghentikan pratinjau. Jalankan `make start` kembali setelah mengubah sumber agar image memuat revisi terbaru. Gunakan `make test` untuk instalasi uji terpisah. Jaga file `.env` dalam workspace lokal. Skrip membuat kata sandi acak dan Git mengabaikan file itu.

## Staging dan produksi

Konfigurasi contoh VPS RC5 tersedia di [deployment/vps](deployment/vps/README.txt). Gunakan preset 1 GB untuk staging awal dan konfirmasi kapasitas CPU, penyimpanan dan bandwidth sebelum penalaan lebih lanjut. Pertahankan Safe Mode saat memverifikasi skema, indeks, laporan reverse audit, cadangan dan pemulihan. Portal operator dan wp-admin memerlukan autentikasi serta harus dikecualikan dari cache halaman bersama.

Gunakan ZIP hasil build pada clone staging terlebih dahulu. Simpan dump database dan cadangan file sebelum migrasi. Verifikasi hasil restore pada database tersendiri dan gunakan prosedur kembali ke versi sebelumnya yang telah diperiksa. Jangan ekspor data demo atau akun uji ke produksi.

Pemasangan plugin membuat indeks `sdp_name_order`, `sdp_school_count`, `sdp_province_order`, dan `sdp_search_count` pada tabel sekolah. Pada instalasi aktif yang diperbarui dengan mengganti ZIP, buat indeks melalui menu **Kesiapan Produksi → Perbaikan schema inti** pada salinan staging atau jendela pemeliharaan setelah backup. Menu ini memakai Safe Mode dan konfirmasi backup. Periksa kolom indeks tanpa pemotongan awalan, khususnya pada tabel InnoDB lama. Latensi, ruang disk, dan biaya pembuatan indeks perlu diukur di staging tujuan. Panel membatasi waktu tunggu metadata lock hingga dua detik per operasi database pada sesi perbaikan. Batas ini berlaku saat menunggu lock. MariaDB tetap dapat memerlukan waktu lebih lama untuk membangun indeks setelah lock didapat. Jika perbaikan gagal, pastikan transaksi dan pekerjaan impor sudah selesai sebelum mencoba lagi.

## Pindahkan situs LocalWP ke staging VPS

1. Pilih **Export** pada situs Local yang berisi hasil kerja. Simpan cadangan di tempat privat. Pastikan arsip menyertakan `wp-content` dan dump SQL lengkap. Ekspor konten XML WordPress tidak membawa katalog NPSN di opsi situs dan tabel indeks Sekolah Directory Pro.
2. Siapkan situs staging berautentikasi dengan database kosong khusus, HTTPS, dan `wp-config.php` milik VPS. Cocokkan `$table_prefix` dengan awalan tabel pada dump serta gunakan kredensial dan salt baru. Setelah mengatur koneksi database tujuan, pulihkan `wp-content` dan impor dump ke database staging.
3. Dari direktori WordPress tujuan, periksa penggantian URL. Gunakan URL asal dan tujuan yang sebenarnya. Jalankan kembali perintah tanpa `--dry-run` setelah hasilnya sesuai, lalu pastikan `wp option get home` dan `wp option get siteurl` menunjukkan domain tujuan.

   ```sh
   wp search-replace 'http://ahda21-staging.local' 'https://staging.example.com' \
     --all-tables-with-prefix --skip-columns=guid --dry-run
   ```

4. Rotasi kata sandi administrator hasil impor dan periksa pengaturan HTTPS, cache, serta Safe Mode. Uji jumlah rekaman katalog, tautan sumber, pencarian NPSN dan halaman profil. Pastikan draf tetap tersembunyi bagi pengunjung dan profil terbit memakai tautan domain tujuan. Simpan backup sebelum mengubah data di tujuan. Gunakan salinan database dan `wp-content` dari satu waktu pencadangan saat memulihkan.

Untuk instalasi standar dengan `home` dan `siteurl` yang sama, jalankan pemeriksaan setelah migrasi. Salin [skrip verifikasi](deployment/vps/tools/verify-migration.php) ke direktori di luar webroot. Jalankan dari direktori WordPress tujuan dengan URL tujuan, jumlah rekaman katalog dari situs asal, dan NPSN yang sudah tersimpan sebagai draf sebelum ekspor.

```sh
wp eval-file /jalur/verify-migration.php https://staging.example.com 689 20210822
```

Skrip memeriksa URL tersimpan dan efektif, jumlah katalog, relasi draf dengan indeks sekolah, dan status REST anonim. Periksa juga melalui browser untuk memastikan Nginx, HTTPS, autentikasi staging, dan permalink publik bekerja dari luar WordPress.

### Lindungi staging Nginx

Untuk staging yang memakai Nginx, buat berkas `htpasswd` di luar webroot. Simpan dengan akses baca terbatas untuk proses Nginx. Tambahkan [konfigurasi staging privat](deployment/vps/nginx/sdp-staging-private.conf) ke blok `server` HTTPS staging dan sesuaikan lokasi berkas autentikasi. Gunakan `nginx -t` sebelum memuat ulang konfigurasi.

Periksa bahwa beranda, `wp-admin`, dan REST staging meminta autentikasi HTTP. Setelah melewati autentikasi HTTP, `wp-admin` masih meminta login WordPress. Pertahankan cache HTML nonaktif pada staging privat. Sediakan sertifikat yang valid untuk domain staging.

### Verifikasi cron di staging privat

Pada staging dengan HTTP Basic, kunjungan berautentikasi dapat membuka situs, tetapi permintaan internal WordPress ke `/wp-cron.php` tidak membawa kredensial tersebut. Nginx mengembalikan 401 dan event yang jatuh tempo tetap menunggu. Gunakan cron sistem melalui WP-CLI sambil mempertahankan autentikasi HTTP.

1. Dari akun sistem pemilik situs, sesuaikan jalur dan siapkan direktori lock privat seperti pada [contoh crontab](deployment/vps/wordpress/cron.example). Jalankan perintah pada contoh tanpa awalan `*/5 * * * *` dan `--quiet` untuk melihat hasilnya.
2. Pasang crontab, lalu konfirmasi event yang jatuh tempo benar-benar berjalan melalui jadwal tersebut. Setelah berhasil, aktifkan `DISABLE_WP_CRON` pada `wp-config.php`. Pertahankan `SDP_SAFE_MODE` saat memeriksa konfigurasi. Uji pemrosesan antrean dengan fixture DEMO hanya pada salinan terisolasi dan matikan Safe Mode sementara pada salinan itu.
3. Periksa log Nginx dan status antrean. Kunjungan biasa seharusnya tidak lagi memicu permintaan internal `/wp-cron.php`. Saat antrean selesai dan akses jaringan terjadwal mati, `wp cron event list --fields=hook,next_run_gmt` seharusnya tidak lagi menampilkan `sdp_collector_tick`. Event WordPress lain dapat tetap terjadwal.

Contoh crontab telah dijalankan pada salinan staging privat oleh Supercronic sebagai pengguna non-root. Interval pengujian dipersingkat menjadi satu menit. Pada VPS gunakan interval lima menit pada contoh dan periksa log daemon yang tersedia di server tersebut.

### Verifikasi email dan pemulihan akun

Siapkan SMTP melalui relay server atau integrasi email WordPress yang didukung penyedia. Gunakan pengaturan TLS, domain pengirim terverifikasi, dan kredensial dari penyedia. Simpan kredensial di konfigurasi privat atau secret store di luar Git. Untuk staging gunakan kotak masuk uji yang Anda kendalikan.

Dari jendela privat, buka **Lupa kata sandi** untuk akun uji. Periksa apakah pesan tiba dan tautan mengarah ke domain HTTPS yang sedang diuji. Gunakan autentikasi HTTP staging sebelum membuka tautan jika diminta. Tetapkan kata sandi baru, verifikasi login, lalu pastikan tautan lama tidak dapat digunakan lagi. Untuk registrasi terbuka, uji juga email penetapan kata sandi akun baru. Simpan hasil uji tanpa isi email atau tautan reset.
