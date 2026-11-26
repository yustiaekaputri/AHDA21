# AHDA21

Proyek direktori sekolah Indonesia berbasis WordPress dan NPSN.

| Lokasi | Isi |
| --- | --- |
| `plugin/` | Sekolah Directory Pro 10.0.0 RC4 dan contoh data fiktif |
| `theme/` | Sekolah Directory Theme 7.0.0 |
| `tests/simulasi/` | Uji PHP dan Python dengan simulasi WordPress |
| `tests/localwp/` | Runner dan panduan pengujian LocalWP |
| `tools/` | Validator CSV, konverter XLSX, persiapan pilot, dan benchmark |
| `deployment/vps/` | Konfigurasi VPS RC5 dan petunjuk penggunaan |
| `docs/` | Panduan versi dan laporan uji bawaan |

Kelima ZIP asli tersedia di akar repositori. Untuk instalasi di WordPress, unggah ZIP plugin melalui menu **Plugins** dan ZIP tema melalui **Appearance → Themes**. Plugin memerlukan WordPress 6.4+ dan PHP 8.0+. Gunakan lingkungan staging untuk versi RC4. Petunjuk LocalWP tersedia di [MULAI-DARI-SINI.txt](tests/localwp/MULAI-DARI-SINI.txt) dan petunjuk VPS di [README VPS](deployment/vps/README.txt).

Workflow GitHub Actions mencakup lint PHP, simulasi mode hemat dan standar, serta tes Python. Pengujian penerimaan di WordPress dan basis data nyata dijalankan melalui LocalWP.

## Impor profil sekolah

Di menu **Direktori Sekolah → Impor CSV / TXT**, unggah berkas UTF-8 dengan kolom `npsn` dan `nama`. Gunakan pemisah koma atau titik koma. Berkas TXT dapat menggunakan tab. Daftar NPSN tanpa nama belum dapat digunakan untuk membuat profil.

Di **Direktori Sekolah → Impor Excel**, pilih berkas `.xlsx` dan nomor sheet. **Impor sebagai draf** menyimpan profil langsung melalui aturan impor yang sama. **Konversi dan unduh CSV** tetap tersedia untuk memeriksa isi sebelum mengimpor. Simpan NPSN sebagai teks di Excel agar nol di awal tetap utuh.

## Menjalankan pratinjau

Dengan Docker Compose, OpenSSL, dan Node.js LTS, jalankan `npm ci` lalu `make start`. WordPress pengembangan tersedia di port lokal 3000. Nama login admin lokal adalah `sdp-admin` dan kata sandinya dibuat otomatis di `.env`. Variabel `SDP_HTTP_PORT` dan `SDP_SITE_URL` mengatur alamat lokal. Data demo dibuat melalui API plugin di database pengembangan. Pada komputer yang menjalankan Docker, buka dashboard di `http://127.0.0.1:3000/wp-admin/admin.php?page=sdp-directory`. Jika Docker berjalan di Codex Cloud, gunakan browser Desktop di lingkungan Cloud yang sama atau tautan HTTP Preview dari Codex. Browser di komputer Anda memerlukan penerusan port agar dapat mencapai WordPress Cloud.

Jalankan `npx playwright install chromium` lalu `make test` untuk menjalankan pengujian dengan situs uji sekali pakai. Hasil berada di `artifacts/`. Untuk menghentikan pratinjau, jalankan `make stop`.

### GitHub Codespaces

Buka branch `dev/wordpress-integration` di GitHub, lalu pilih **Code → Codespaces → Create codespace**. Tunggu skrip startup selesai. Pada panel **Ports**, pastikan port 3000 berstatus **Private**, lalu buka alamat yang ditampilkan dan tambahkan `/wp-admin/admin.php?page=sdp-directory`. Login menggunakan `sdp-admin` dan nilai `SDP_ADMIN_PASSWORD` dari file `.env` di Codespace. Hentikan Codespace setelah selesai agar pemakaian kuota berhenti.

Lihat [pengujian](TESTING.md), [arsitektur](ARCHITECTURE.md), [status proyek](PROJECT_STATUS.md), [performa](PERFORMANCE.md), dan [deployment](DEPLOYMENT.md).
