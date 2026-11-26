# Performa awal

Pengukuran dilakukan dari loopback Cloud pada WordPress 6.9, PHP 8.2.32 FPM, Nginx 1.22.1, MariaDB 10.11.19, Safe Mode dan mode hemat aktif. Dataset 100–10.000 memakai prefix tabel tersendiri dan hanya profil FIKTIF. Angka berikut adalah 10 permintaan HTTP berurutan tanpa kompresi pada halaman direktori tanpa filter.

| Profil | HTTP p50 | HTTP p95 | Rata-rata badan respons |
| ---: | ---: | ---: | ---: |
| 31 DEMO | 26,5 ms | 49,4 ms | 44.967 byte |
| 100 FIKTIF | 36,8 ms | 474,2 ms | 46.503 byte |
| 1.000 FIKTIF | 28,4 ms | 96,1 ms | 46.508 byte |
| 10.000 FIKTIF | 732,2 ms | 835,6 ms | 46.511 byte |

Pada satu sampel HTTP untuk masing-masing dataset 31 dan 10.000 profil, puncak alokasi PHP adalah 8 MiB. Jumlah query termasuk WordPress adalah 51 dan 22 pada kondisi cache yang berbeda. WP-CLI mencatat puncak alokasi 53,3 MiB termasuk bootstrap dan WP-CLI. Angka tidak mencakup memori MariaDB, OPcache bersama, RSS proses, aset susulan, maupun total bandwidth.

Pada 10.000 profil, `EXPLAIN` menunjukkan pemindaian tabel indeks sekolah dan filesort pada daftar tanpa filter. Query provinsi memakai tabel sementara dan filesort. Median panggilan internal daftar 499 ms, sementara profil yang dibatasi provinsi 5,7 ms. Query facet pertama sangat lebih mahal daripada hit cache dalam proses yang sama. Audit cache dan perubahan SQL harus menjaga invalidasi ketika profil menjadi privat, terlindungi sandi, atau dihapus.

Cache facet terbatas, pemuatan aset kondisional, pagination SQL, batch, checkpoint dan scheduler sesuai permintaan sudah tersedia pada sumber. Ukur lagi pengurutan dan agregasi direktori tanpa filter setelah perubahan query. Uji 50.000–500.000 profil, metrik RSS dan CPU, beban serentak, bandwidth serta efektivitas WebP masih tertunda. Ukur pada host uji terpisah sebelum memilih konfigurasi VPS 1 GB.

## Menjalankan ulang

Gunakan database uji kosong dengan Compose. Skrip menjalankan impor fixture bertahap, profil query WP-CLI, dan pengukuran HTTP loopback.

```sh
export COMPOSE_PROJECT_NAME=sdp-test-perf SDP_HTTP_PORT=3001
scripts/setup.sh
scripts/install.sh
scripts/profile-loopback.sh DISPOSABLE-LOCAL-ONLY
scripts/reset-test-db.sh --confirm
```

Atur `SDP_BENCHMARK_COUNTS` untuk memilih jumlah profil yang sesuai kapasitas host uji.

Pengukuran ulang pada 10.000 profil menghasilkan p50 706,1 ms dan p95 3.236,4 ms ketika host Cloud juga menjalankan build. Sampel SQL terpisah menemukan varian pengambilan ID lalu payload menghasilkan urutan identik, dengan waktu query hangat yang serupa. Prioritaskan pengukuran agregasi dan facet pada host sepi sebelum mengganti query.

MariaDB pada runtime langsung memakai pengaturan default yang teramati, termasuk buffer InnoDB 128 MiB dan 151 koneksi maksimum. RSS proses MariaDB sekitar 219 MiB pada satu sampel setelah pengujian. Pengukuran ini tidak memvalidasi preset Compose 64 MiB atau kapasitas VPS. Beranda diperiksa tanpa aset plugin direktori, sedangkan halaman direktori memuat aset plugin yang dibutuhkan. Sumber media menerima WebP dan sudah memberi atribut lazy loading pada galeri. Penghematan byte gambar masih perlu diukur.

## Pengukuran per kueri pada 10.000 profil

Pengukuran ulang di Codex Cloud menggunakan dataset FIKTIF yang sudah tersedia, PHP 8.2.32, dan MariaDB 10.11.19. Angka berikut adalah median delapan eksekusi setelah satu pemanasan per kueri, diukur melalui WP-CLI tanpa cache facet aplikasi. Nilai ini mengukur kueri tunggal.

| Kueri | Median |
| --- | ---: |
| Hitung total dan rekap status tanpa filter | 808,1 ms |
| Ambil 20 profil halaman pertama tanpa filter | 5,6 ms |
| Ambil pilihan provinsi dari data yang dapat ditampilkan | 826,5 ms |
| Ambil 20 hasil dengan filter satu provinsi | 1,0 ms |

Percobaan terpisah memaksa tabel artikel diproses lebih dulu dengan `STRAIGHT_JOIN`. Hash hasilnya identik untuk kedua kueri. Median empat eksekusi setelah pemanasan naik dari 750,8 ke 763,9 ms untuk ringkasan, dan dari 611,2 ke 952,2 ms untuk pilihan provinsi. Urutan join bawaan dipertahankan. Prioritas perbaikan adalah penghitungan total dan opsi filter ketika cache kosong. Pengukuran PHP pada proses ini mencapai puncak alokasi 51,3 MiB termasuk WP-CLI. Benchmark lebih besar ditunda karena ruang disk lingkungan uji terbatas.
