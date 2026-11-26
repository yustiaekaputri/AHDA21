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

### Membuat profil dengan mengetik NPSN

Di **Direktori Sekolah → Cari NPSN dari katalog**, administrator dapat mengunggah CSV berizin dengan kolom `npsn,nama` dan mencatat sumber, periode serta tanggal data. Setiap unggahan valid mengganti katalog lokal sebelumnya. Katalog mendukung hingga 50 baris dalam mode hemat atau 200 dalam mode standar, dengan batas ukuran 512 KiB.

Ketik NPSN, klik **Cari NPSN**, lalu tinjau data dan sumbernya. **Simpan draf dari katalog** membuat profil baru untuk diperiksa sebelum publikasi. Gunakan tautan profil untuk mengedit profil yang sudah ada atau mengisi data secara manual jika NPSN belum ada dalam katalog. Pencarian memakai CSV yang sudah diunggah dan berjalan tanpa koneksi ke sumber resmi.

### Memeriksa sampel resmi sebelum unggah

Gunakan [panduan persiapan pilot](docs/plugin/PANDUAN-V103.txt) untuk memvalidasi CSV berizin dengan `tools/prepare_pilot.py` dan mencatat sumber, edisi, serta dasar izin. Pada clone LocalWP yang tidak diindeks, jalankan `wp sdp pilot-preview` dengan SHA-256 dari manifest. WordPress akan menandai calon profil baru, konflik, dan kondisi lain tanpa mengubah profil.

Untuk mencoba pencarian NPSN setelah peninjauan, unggah hanya `identity.csv` yang sudah diperiksa. Gunakan `--limit 50` saat menyiapkan pilot dalam mode hemat. Statistik dan akreditasi mengikuti jalur pemeriksaan terpisah di panduan pilot. Simpan file sumber dan manifest secara privat. Validasi format dan pemeriksaan konflik perlu dilengkapi verifikasi izin, periode dan fakta dari sumber resmi.

## Claude untuk usulan narasi

Untuk menyiapkan Claude, set `ANTHROPIC_API_KEY` pada environment proses PHP atau tetapkan `SDP_CLAUDE_API_KEY` di konfigurasi WordPress lokal. Di **Direktori Sekolah → SEO & AI → Asisten narasi AI**, masukkan NPSN dari profil yang sudah tersimpan, pilih **Claude (Anthropic)** dan isi ID model dari akun Anthropic Anda. Konfirmasi izin pemrosesan sebelum membuat usulan. Pratinjau privat tersedia selama sepuluh menit untuk ditinjau. Permintaan dilakukan atas tindakan administrator dan dapat menimbulkan biaya.

## Menjalankan pratinjau

Dengan Docker Compose, OpenSSL, dan Node.js LTS, jalankan `npm ci` lalu `make start`. WordPress pengembangan tersedia di port lokal 3000. Nama login admin lokal adalah `sdp-admin` dan kata sandinya dibuat otomatis di `.env`. Variabel `SDP_HTTP_PORT` dan `SDP_SITE_URL` mengatur alamat lokal. Data demo dibuat melalui API plugin di database pengembangan. Pada komputer yang menjalankan Docker, buka dashboard di `http://127.0.0.1:3000/wp-admin/admin.php?page=sdp-directory`. Jika Docker berjalan di Codex Cloud, gunakan browser Desktop di lingkungan Cloud yang sama atau tautan HTTP Preview dari Codex. Browser di komputer Anda memerlukan penerusan port agar dapat mencapai WordPress Cloud.

Jalankan `npx playwright install chromium` lalu `make test` untuk menjalankan pengujian dengan situs uji sekali pakai. Hasil berada di `artifacts/`. Untuk menghentikan pratinjau, jalankan `make stop`.

### GitHub Codespaces

Buka branch `dev/wordpress-integration` di GitHub, lalu pilih **Code → Codespaces → Create codespace**. Tunggu skrip startup selesai. Pada panel **Ports**, pastikan port 3000 berstatus **Private**, lalu buka alamat yang ditampilkan dan tambahkan `/wp-admin/admin.php?page=sdp-directory`. Login menggunakan `sdp-admin` dan nilai `SDP_ADMIN_PASSWORD` dari file `.env` di Codespace. Hentikan Codespace setelah selesai agar pemakaian kuota berhenti.

Lihat [pengujian](TESTING.md), [arsitektur](ARCHITECTURE.md), [status proyek](PROJECT_STATUS.md), [performa](PERFORMANCE.md), dan [deployment](DEPLOYMENT.md).
