SEKOLAH DIRECTORY — RC5 VPS DEPLOYMENT OPTIMIZATION
Status: kandidat deployment, bukan versi produksi stabil. Plugin RC4 tidak diubah.
Asumsi: satu VPS Linux Debian/Ubuntu, Nginx + PHP-FPM + MariaDB, satu situs.
Contoh PHP 8.3 (ubah versi ke paket yang terpasang dan masih didukung), domain
example.invalid, webroot /var/www/sekolah/public. Tidak ada installer otomatis.
RAM 1 GB, CPU terbatas (jumlah vCPU belum dikonfirmasi), bandwidth 10 GB
(periode kuota belum dikonfirmasi). Gunakan mode micro; angka bukan jaminan kapasitas.

1. PERSIAPAN
- Jalankan tools/check-local.sh pada VPS untuk melihat RAM, load, disk dan versi.
  Periksa juga panel provider untuk kuota transfer dan metrik historis.
- Jika memakai hosting panel, Apache, Caddy, Docker, multisite atau server bersama,
  jangan salin template mentah. Sesuaikan dengan administrator. Pastikan akses
  konsol pemulihan, dan jangan menutup sesi SSH sebelum akses baru berhasil diuji.
- Backup database dan semua file ke luar webroot dan VPS; lindungi dengan enkripsi
  serta akses terbatas. Uji restore pada clone. Simpan salinan konfigurasi aktif
  Nginx, FPM, MariaDB, firewall dan cron. Catat versi layanan.
- Gunakan paket OS yang mendapat pembaruan keamanan; pasang WordPress bersih yang
  didukung beserta ekstensi PHP diperlukan (mysqli, curl, mbstring, zip, xml,
  gd, intl sesuai kebutuhan). Tidak ada Redis/AI lokal yang wajib.
- Gunakan user database khusus situs, bukan root. Batasi hak pada database situs
  dan jangan buka port 3306 ke internet. Aktifkan SSH key, 2FA admin jika tersedia,
  firewall hanya membuka port SSH yang benar, 80 dan 443. Uji akses konsol dahulu.

2. MIGRASI DARI LOCALWP
- Uji RC4 dan theme pada clone LocalWP; jangan migrasi dataset benchmark fiktif.
- Hentikan kampanye, tindakan tulis dan cron selama ekspor konsisten; aktifkan
  SDP_SAFE_MODE sementara. Safe Mode tidak memblokir semua tindakan manual.
- Ekspor database menggunakan wp db export /lokasi-aman/sdp.sql dan salin file
  WordPress termasuk uploads, plugin, tema; jangan masukkan backup ke webroot.
  Verifikasi checksum, izin dan ukuran transfer. Backup DB dan file pada waktu
  yang sama (maintenance; hindari update saat backup).
- Buat database kosong dan user khusus pada server staging; wp db import path
  yang tepat hanya untuk target kosong/clone setelah backup. Atur DB credentials
  dan salts di wp-config.php; jangan menyalin kredensial lokal ke produksi.
- WP-CLI search-replace mendukung serialized data. Mulai dry-run:
  wp search-replace 'http://alamat-lokal.test' 'https://domain-anda' --all-tables-with-prefix --skip-columns=guid --dry-run
  Verifikasi daftar tabel dan hasil, lalu jalankan tanpa --dry-run hanya pada
  target yang benar. Periksa juga URL HTTPS lama, home/siteurl dan permalink.
- Uji dengan staging yang terlindung autentikasi/IP dan noindex. Noindex saja
  bukan perlindungan akses. Jalankan wp sdp readiness --user=ID_ADMIN,
  wp sdp readiness --audit --user=ID_ADMIN, wp sdp integrity-posts --user=ID_ADMIN.
  Ganti ID_ADMIN. Lanjutkan cursor audit sampai selesai. Uji data resmi dan
  akun operator terpisah. Selesaikan pilot 100 sekolah sebelum impor massal.

3. PRESET SERVER (pilih hanya SATU preset)
Preset            FPM children  PHP limit   OPcache  InnoDB pool  DB connections
micro-1gb         2             128 MiB     64 MiB   128 MiB      15
small-2gb         3             128 MiB     96 MiB   256 MiB      25
growth-4gb        5             192 MiB     128 MiB  512 MiB      40
high-8gb          8             192 MiB     192 MiB  1024 MiB     60
- Salin konfigurasi FPM terpilih ke pool.d dan sesuaikan socket/user. Jika ini
  server satu situs, hindari pool www default yang masih menganggur atau memakai
  worker tambahan; jangan menonaktifkan pool yang dipakai situs lain.
- Salin file -opcache.ini ke conf.d SAPI FPM, bukan sebagai konfigurasi per pool.
  OPcache adalah memori bersama; 64 MiB bisa kurang jika banyak plugin.
- Salin konfigurasi MariaDB terpilih ke direktori include distro (misalnya
  /etc/mysql/mariadb.conf.d/90-sdp.cnf). Periksa konflik nilai lain. Matikan
  performance_schema hanya bila tidak dibutuhkan pemantauan. Tidak mengubah
  pengaturan durability atau buffer per koneksi secara agresif.
- Uji php-fpm8.3 -t dan mariadbd --help --verbose (untuk melihat konfigurasi;
  bukan jaminan service akan start). Restart/reload satu layanan pada satu waktu
  di maintenance, cek status dan log. Tidak ada perintah otomatis berisiko.
- FPM ondemand menutup worker idle setelah 15 detik. RAM limit PHP BUKAN batas
  RSS; MariaDB juga memakai memori di luar buffer pool. Dua worker bukan
  jaminan muat 1 GB. Ukur RSS/PSS, MemAvailable, swap, load, latensi dan error.
  Jika OOM, kurangi worker menjadi 1, pindahkan impor ke lokal, periksa log.
  CLI memakai php.ini berbeda, jadi limit FPM tidak membatasi impor CLI.
- Set worker_processes auto pada Nginx jika sesuai; jangan menaikkan worker
  tanpa ukuran beban dan CPU yang diketahui.

4. NGINX DAN CACHE
- Gunakan nginx/sdp-site.conf sebagai template situs. Ganti domain pada semua
  tempat, webroot, socket, sertifikat. Buat sertifikat TLS yang valid terlebih
  dahulu (misalnya DNS challenge atau konfigurasi HTTP sementara); jangan
  aktifkan redirect HTTPS hingga sertifikat dan HTTPS bekerja. Pastikan domain
  dan DNS benar. Template tidak menangani ACME HTTP challenge otomatis.
- Letakkan di sites-available dan aktifkan seperti kebijakan distro; jangan
  overwrite konfigurasi situs lain. nginx -t harus berhasil sebelum reload.
- Cache browser 7 hari pada aset publik di wp-content/wp-includes. Jangan simpan
  dokumen privat di uploads publik. Untuk aset sensitif, gunakan jalur akses
  terautentikasi di luar webroot. File backup tidak boleh berada di webroot
  meskipun ada aturan deny. Uji plugin yang mungkin membutuhkan entrypoint PHP
  langsung; aturan deny wp-content/wp-includes sengaja konservatif.
- FastCGI HTML cache OFF secara default. Opsional: setelah halaman /tentang-kami/
  dipastikan publik statis dan bebas form/nonce/personalisasi/preview, pasang
  00-sdp-http.conf dalam http{} dan siapkan cache directory milik user Nginx.
  Ganti HANYA fastcgi_cache off; di location PHP dengan isi snippet opsional.
  Batas cache disk 64 MiB (target soft, bukan hard quota), zone 4 MiB, TTL fallback
  60 detik; header origin dapat menentukan TTL lain. Cookie apa pun, Authorization,
  query, Range, POST, semua URI lain bypass. Header private/no-store/Set-Cookie
  tetap dihormati. Tidak ada stale serving yang dikonfigurasi.
- Tidak ada purge otomatis pada perubahan WordPress. Nonaktifkan cache dan
  bersihkan file cache khusus situs saat maintenance sebelum mengubah halaman
  menjadi privat/draf, mengganti permalink, atau memperbarui kebijakan akses.
  Pastikan proses worker lama selesai; jangan aktifkan lagi tanpa uji. Jangan
  cache halaman login, operator, profil sekolah (form koreksi), REST, pencarian,
  favorit, preview dan halaman dinamis. Jangan aktifkan cache pada staging privat.
- Untuk Cloudflare opsional: Full (strict) dengan sertifikat origin valid;
  cache standar aset statis saja, jangan Cache Everything/APO tanpa audit.
  Bypass HTML/REST/admin/login/operator/preview serta request autentikasi.
  Jangan percaya X-Forwarded-For atau CF-Connecting-IP dari internet langsung;
  jika memakai real IP, gunakan daftar proxy resmi terkini dan firewall yang tepat.
  CDN bukan kuota tak terbatas dan tidak mengurangi transfer impor/backup.

5. CRON DAN MODE HEMAT
- wp-config-fragment.php.txt disalin sebagai potongan, bukan mengganti file.
  Jangan menduplikasi konstanta. Mode hemat aktif. Jalankan impor besar di LocalWP.
- Default WP-Cron boleh dipertahankan. Jika memilih cron sistem, pastikan WP-CLI
  berjalan dengan user situs non-root dan direktori lock privat. Jalankan manual
  wp cron event run --due-now dulu. Setelah teruji, tambahkan cron.example dan
  baru set DISABLE_WP_CRON true. Jika ingin menutup HTTP /wp-cron.php, uji dulu
  bahwa semua job berjalan via CLI dan tidak ada integrasi yang membutuhkannya.
  Contoh cron membangunkan WordPress tiap 5 menit walau tidak ada event; bukan
  zero idle. Flock hanya mencegah overlap proses dari cron yang sama, bukan semua
  job manual. Jika beban meningkat, periksa jadwal dan jobs yang berjalan.
- Safe Mode SDP_SAFE_MODE true untuk menghentikan otomatisasi terjadwal SDP
  saat masalah; bukan kill untuk proses berjalan. Pulihkan dengan menghapusnya
  dan periksa jadwal. Tidak ada cron baru dari RC5.

6. BACKUP, ROLLBACK, DAN PELUNCURAN
- Cadangan terenkripsi di luar VPS; jadwal dan retensi sesuai disk/kuota, misalnya
  harian database dan mingguan file, incremental jika tersedia; uji restore.
- Jika konfigurasi gagal, kembalikan konfigurasi sebelumnya dari backup,
  test lagi lalu reload/restart layanan terkait. Jika data rusak, hentikan tulis
  dan pulihkan pasangan backup DB + file ke clone; verifikasi sebelum cutover.
  Jangan restore ke produksi secara otomatis atau menimpa transaksi baru.
- Uji HTTPS, 404, profil, filter, REST publik, portal 2 akun, cache HIT/BYPASS,
  data privat, backup restore, scheduler, SEO sitemap, 100 sekolah nyata.
  Aktifkan indeks mesin pencari hanya setelah data dan akses tervalidasi.
- Monitor saat trafik rendah dan pada jam ramai. Upgrade ketika bukti bottleneck
  berkelanjutan (RAM rendah, swap aktif, OOM, antrean FPM, P95 tinggi, CPU tinggi,
  kuota mendekati batas). Naikkan preset satu tahap dan benchmark ulang, bukan
  otomatis menaikkan batch. Mode standar SDP hanya setelah kapasitas diuji.

7. UJI YANG TERSEDIA
- tools/test-nginx.py menguji Nginx terpisah pada loopback dengan FastCGI sintetis
  dan sertifikat sementara, bukan PHP-FPM atau WordPress. Jalankan di lingkungan
  uji dengan python3, nginx, openssl; tidak perlu menjalankan pada produksi.
- tools/check-local.sh hanya membaca indikator server. Tidak melakukan perubahan.
- Plugin RC4 identik byte demi byte; RC5 adalah bundel deployment eksternal.
  Belum ada pengujian FPM/MariaDB nyata, TLS domain asli, CDN, LocalWP atau VPS.
  Target 100 sekolah nyata belum dicapai. Belum layak diberi label stable.

REFERENSI TEKNIS
https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html
https://www.php.net/manual/en/install.fpm.configuration.php
https://www.php.net/manual/en/opcache.configuration.php
https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/
https://developer.wordpress.org/cli/commands/cron/event/run/
