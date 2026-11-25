# Deployment

## Lokal dan CI

`make start` menyiapkan WordPress, Apache, PHP dan MariaDB lewat Docker Compose. Port pratinjau default hanya mendengarkan loopback. Jalankan `make start` kembali setelah mengubah sumber agar image memuat revisi terbaru. Gunakan `make test` untuk instalasi uji terpisah. Jaga file `.env` dalam workspace lokal. Skrip membuat kata sandi acak dan Git mengabaikan file itu.

## Staging dan produksi

Konfigurasi contoh VPS RC5 tersedia di [deployment/vps](deployment/vps/README.txt). Gunakan preset 1 GB untuk staging awal dan konfirmasi kapasitas CPU, penyimpanan dan bandwidth sebelum penalaan lebih lanjut. Pertahankan Safe Mode saat memverifikasi skema, indeks, laporan reverse audit, cadangan dan pemulihan. Portal operator dan wp-admin memerlukan autentikasi serta harus dikecualikan dari cache halaman bersama.

Gunakan ZIP hasil build pada clone staging terlebih dahulu. Simpan dump database dan cadangan file sebelum migrasi. Verifikasi hasil restore pada database tersendiri dan gunakan prosedur kembali ke versi sebelumnya yang telah diperiksa. Jangan ekspor data demo atau akun uji ke produksi.
