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

### Indeks MariaDB sementara

Pada 10.000 profil FIKTIF yang sama, indeks tabel sekolah `(status, post_id)` dan `(provinsi, post_id)` dipilih oleh MariaDB, tetapi median naik dari 722 ke 777 ms untuk ringkasan dan dari 785 ke 927 ms untuk opsi provinsi. Kueri dengan pemaksaan indeks sekolah juga lebih lambat dari kondisi awal.

Indeks sementara `(post_type, post_status, post_password, ID)` pada tabel artikel WordPress menghasilkan median 21 ms untuk ringkasan tanpa petunjuk kueri. MariaDB tetap memilih rencana lambat untuk opsi provinsi, sekitar 843 ms. Ketika kueri opsi provinsi diarahkan memakai indeks artikel dan urutan join yang sesuai, mediannya 25 ms. Hash ringkasan dan daftar provinsi tetap identik di semua varian. Setiap median memakai empat eksekusi setelah pemanasan.

Ketiga indeks eksperimen dihapus dari database uji. Sebelum mempertimbangkan konfigurasi opsional, ukur dampak indeks artikel terhadap penulisan, ukuran database, rencana kueri pada data campuran, dan kompatibilitas MySQL 8. Periksa hasil ketika profil privat, terlindungi sandi, dan dihapus pada skema yang sama.

### Visibilitas dan biaya penulisan

Pada benchmark MariaDB yang sama, 30 profil FIKTIF tambahan dibuat lewat plugin. Melalui API WordPress, 10 profil dijadikan privat, 10 dilindungi kata sandi, dan 10 dihapus. Dengan dan tanpa indeks artikel, total direktori kembali dari 10.030 ke 10.000, tiga pilihan provinsi uji hilang walaupun cache telah terisi, dan semua 30 permintaan REST anonim mengembalikan 404. Pada data campuran, hint indeks artikel menghasilkan total dan daftar provinsi yang identik dengan kueri standar.

| Operasi | Sampel per kondisi | Median tanpa indeks | Median dengan indeks |
| --- | ---: | ---: | ---: |
| Buat profil melalui plugin | 30 | 50,05 ms | 49,20 ms |
| Ubah status atau kata sandi via WordPress | 20 | 5,15 ms | 5,43 ms |
| Hapus profil via WordPress | 10 | 12,46 ms | 13,09 ms |

`INDEX_LENGTH` tabel artikel bertambah sekitar 1,5 MiB selama eksperimen. Setiap kondisi diukur sekali dengan sampel kecil, sehingga angka penulisan belum cukup untuk mengukur dampak impor massal atau variasi antar-run. Indeks dan fixture tambahan dihapus setelah pengujian.

### Kompatibilitas MySQL 8

Job `mysql-index` menguji pembuatan dan penghapusan indeks artikel pada InnoDB dengan `utf8mb4`. Fixture SQL mengecek ringkasan sebelum dan setelah memakai hint, urutan pilihan provinsi, serta pengecualian profil privat, draf, profil bersandi, artikel dengan tipe lain, dan artikel yang sudah dihapus. Fixture lulus pada MariaDB 10.11 dan pada MySQL 8.0 di GitHub Actions. Pemeriksaan ini belum mengukur latensi pada MySQL atau menguji seluruh plugin pada mesin tersebut. [Job MySQL 8](https://github.com/yustiaekaputri/AHDA21/actions/runs/45757538868/job/139699589858)

## Cache ringkasan direktori

Ringkasan tanpa filter memakai cache facet yang sudah dibatasi jumlah slot dan masa berlakunya. Penyimpanan profil, perubahan status atau kata sandi, dan penghapusan membatalkan cache yang sama. Kueri dengan filter menjalankan ringkasan langsung dan tidak menambah variasi kunci cache.

Pada 10.000 profil FIKTIF, panggilan daftar pertama setelah invalidasi memerlukan 707,8 ms. Tiga proses WP-CLI berikutnya memperoleh hasil identik dalam 8,1–8,8 ms, termasuk pengambilan 20 profil. Pengukuran ini tidak mencakup render halaman, opsi filter, atau akses HTTP.
