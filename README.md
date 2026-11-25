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

GitHub Actions menjalankan lint PHP, simulasi PHP dalam mode hemat dan standar, serta tes Python. Pengujian penerimaan di WordPress dan basis data nyata dijalankan melalui LocalWP.
