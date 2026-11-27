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

### Pengukuran HTTP setelah cache ringkasan

Pengujian loopback pada WordPress 6.9, PHP 8.2.32, dan MariaDB 10.11 memakai server pengembangan bawaan PHP dan satu klien. Kontrol mematikan cache ringkasan dan opsi sekaligus melalui `SDP_FACET_CACHE=false`. Semua 16 permintaan terukur mendapat HTTP 200. Ringkasan HTML menunjukkan 10.000 sekolah dalam kedua kondisi dan badan respons tanpa kompresi sebesar 46.549 byte.

| Kondisi | Permintaan | HTTP p50 | HTTP p95 | Puncak alokasi PHP |
| --- | ---: | ---: | ---: | ---: |
| Cache ringkasan dan opsi nonaktif | 5 | 4.171 ms | 4.499 ms | 6 MiB |
| Permintaan pertama setelah invalidasi | 1 | 4.016 ms | 4.016 ms | 8 MiB |
| Permintaan berikutnya, cache aktif | 10 | 856 ms | 888 ms | 6 MiB |

Jejak SQL terpisah setelah invalidasi berikutnya mencatat kueri pilihan jenjang 1.328 ms, status 800 ms, ringkasan 656 ms, dan provinsi 639 ms pada permintaan pertama. Pada dua permintaan berikutnya, jejak tersebut tidak mencatat ulang kueri ringkasan maupun opsi. Perbedaan antarpengulangan perlu ditelusuri sebelum memakai angka ini untuk memperkirakan kapasitas VPS. Pengukuran tidak mencakup aset susulan, cache proxy, atau beban serentak.

## Kueri pilihan filter tanpa cakupan wilayah

Untuk provinsi, jenjang, dan status tanpa cakupan tambahan, kueri mengambil kandidat unik dari indeks sekolah lalu mencari satu nilai dari profil yang terbit dan tidak bersandi untuk setiap kandidat. Pengambilan nilai dari profil yang terlihat juga berlaku ketika dua ejaan dianggap sama oleh kolasi database. Pilihan dengan cakupan dan opsi akreditasi memakai kueri sebelumnya.

Pada 10.000 profil FIKTIF ditambah tiga profil uji yang disembunyikan, urutan dan nilai ketiga daftar identik. Median empat eksekusi setelah pemanasan turun dari 1.540,3 ke 0,7 ms untuk jenjang, dari 883,4 ke 0,7 ms untuk status, dan dari 757,7 ke 7,6 ms untuk provinsi. Angka ini mengukur kueri SQL terpisah di MariaDB. Tambahan pemeriksaan MySQL 8 mencocokkan hasil bentuk kueri baru pada fixture SQL.

### Permintaan HTTP serentak pada 10.000 profil

Server pengembangan PHP 8.2.32 menggunakan empat proses anak dan hanya mendengarkan loopback Cloud. WordPress 6.9 dan MariaDB 10.11.19 melayani 129 permintaan direktori tanpa kesalahan. Setiap tingkat konkurensi memakai tiga pengulangan. Setiap pengulangan memulai satu batch setelah invalidasi cache, lalu mengirim 12 permintaan tambahan. Nilai berikut adalah median statistik tiap pengulangan.

| Klien serentak | p50 batch pertama | p50 berikutnya | p95 berikutnya |
| ---: | ---: | ---: | ---: |
| 1 | 820 ms | 34 ms | 53 ms |
| 2 | 627 ms | 45 ms | 73 ms |
| 4 | 595 ms | 59 ms | 88 ms |

Batch pertama hanya berisi satu permintaan per klien. Selisih nilai antarlevel bisa dipengaruhi variasi host dan cache database. Puncak alokasi PHP per permintaan mencapai 6 MiB. RSS empat proses anak setelah uji sekitar 41 MiB per proses, termasuk memori bersama. Angka ini belum mencakup RSS MariaDB, PHP-FPM atau Nginx pada preset VPS, aset susulan, ataupun beban yang berlangsung lama.

### Nginx dan PHP-FPM dengan dua worker

Pengujian loopback berikutnya memakai Nginx 1.22.1, PHP-FPM 8.2.32 dan MariaDB 10.11 dengan 10.000 profil FIKTIF yang sama. PHP-FPM mengikuti preset mikro untuk proses `ondemand`, maksimum dua worker, dan `memory_limit=128M` per proses PHP. Nginx tidak memakai cache halaman. Semua 42 permintaan terukur berhasil.

| Skenario | Permintaan | p50 | p95 |
| --- | ---: | ---: | ---: |
| Batch awal setelah invalidasi, dua klien | 2 | 723 ms | 723 ms |
| Cache terisi, dua klien | 20 | 50 ms | 57 ms |
| Cache terisi, empat klien | 20 | 53 ms | 100 ms |

Puncak alokasi PHP mencapai 6 MiB per permintaan. Sesaat setelah uji, RSS worker PHP-FPM tercatat 41–44 MiB per proses dan MariaDB sekitar 237 MiB. RSS mencakup memori bersama. Database menggunakan buffer InnoDB 128 MiB dan 151 koneksi maksimum yang sudah ada di Cloud. Batas cgroup lingkungan adalah 8 GiB. Hasil ini memvalidasi jalur Nginx/PHP-FPM dengan dua worker, sementara uji seluruh stack dalam batas 1 GiB, CPU VPS, dan beban berdurasi panjang masih terbuka.

## Pengukuran hingga 500.000 profil

WordPress 6.9, PHP-FPM 8.2.32 dengan dua worker, Nginx 1.22.1, dan MariaDB 10.11.19 diuji di loopback. MariaDB memakai database terpisah pada tmpfs, buffer InnoDB 64 MiB dan 25 koneksi maksimum. Cache halaman Nginx nonaktif. Safe Mode, mode hemat dan `noindex` aktif. Satu profil dibuat melalui plugin, sedangkan profil berikutnya disalin dengan SQL ke skema WordPress dan indeks plugin untuk mengukur pembacaan. Semua ID artikel unik dan NPSN pada JSON cocok dengan indeks. Angka ini mengukur pembacaan direktori, filter, dan pencarian dengan satu klien.

| Profil FIKTIF | Pembukaan setelah invalidasi, p50 | Direktori, p50/p95 | Filter provinsi, p50/p95 | Nama, p50/p95 | NPSN lengkap, p50/p95 |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 10.000 | 141,5 ms | 69,2 / 82,6 ms | 21,7 / 25,0 ms | 79,8 / 87,5 ms | 76,4 / 86,4 ms |
| 50.000 | 342,0 ms | 78,7 / 93,0 ms | 51,9 / 56,8 ms | 111,7 / 121,7 ms | 97,5 / 115,0 ms |
| 100.000 | 861,0 ms | 125,2 / 161,1 ms | 57,5 / 122,7 ms | 419,3 / 467,1 ms | 390,3 / 438,0 ms |
| 500.000 | 4.306,5 ms | 3.748,0 / 3.853,3 ms | 268,2 / 399,4 ms | 2.171,6 / 2.330,5 ms | 2.030,2 / 2.060,4 ms |

Setiap pembukaan setelah invalidasi memakai tiga sampel. Empat skenario lain memakai 12 sampel setelah satu pemanasan. Seluruh 204 respons terukur mendapat HTTP 200. Badan HTML direktori 500.000 profil sekitar 46,9 kB tanpa kompresi. Puncak alokasi PHP 6 MiB per permintaan terukur. Playwright membuka direktori di tiga ukuran, menemukan satu profil melalui NPSN dan membuka halaman profilnya.

Pada 500.000 profil, pengulangan dengan tiga generasi cache berbeda mempercepat permintaan hangat direktori menjadi 609–670 ms ketika ringkasan dan opsi berhasil dipertahankan. Dengan cache kosong, jejak SQL mencatat sekitar 1,5 detik untuk ringkasan, 1,6–1,7 detik untuk opsi akreditasi dan 0,5–0,7 detik untuk daftar halaman pertama. Waktu tersebut mengukur kueri pada sampel terpisah.

Percobaan terkontrol memilih generasi cache yang menempatkan ringkasan, opsi status, dan opsi akreditasi pada slot yang sama. Permintaan kedua masih mengulang ketiga kueri karena setiap nilai menggantikan nilai sebelumnya. Waktu PHP pada permintaan kedua 3.783 ms, sejalan dengan lonjakan pada pengukuran HTTP utama. Hasil tetap sesuai karena cache memeriksa hash kueri sebelum memakai nilainya.

Eksperimen satu NPSN menemukan identitas yang sama melalui pencarian substring maupun `s.npsn = ...`. Median lima eksekusi kueri turun dari 1.580,6 ms menjadi 0,178 ms. `EXPLAIN` beralih dari pemindaian tabel artikel ke dua pencarian kunci primer. Ini menunjukkan peluang untuk pencarian NPSN persis, sementara perubahan aplikasi perlu mempertahankan pencarian nama, NPSN parsial, filter, dan visibilitas profil. Latensi kueri tidak mewakili waktu muat halaman.

Basis data mencapai sekitar 1,6 GiB termasuk ruang kerja InnoDB. Benchmark ini memakai data sintetis pada tmpfs dan host dengan batas memori 8 GiB. Pengujian VPS 1 GB, beban serentak pada 500.000 profil, dan waktu impor massal melalui plugin masih terbuka.

### Perbaikan tabrakan slot cache

Ringkasan publik dan opsi provinsi, jenjang, status, serta akreditasi tanpa cakupan tersendiri kini memakai lima slot khusus. Filter bercakupan memakai 27 slot lainnya. Semua entri tetap terikat pada hash SQL dan generasi cache serta batas masa berlaku 300 detik. Penyimpanan sekolah, perubahan status atau kata sandi, dan penghapusan memicu invalidasi.

Uji ulang memakai 500.000 profil dan generasi cache yang sebelumnya menempatkan ringkasan, status, dan akreditasi pada slot yang sama. Lima permintaan setelah satu pemanasan turun dari median 3.760 ms menjadi 565 ms. Jejak SQL mencatat kueri ringkasan dan opsi pada kelima permintaan versi awal dan nol permintaan setelah perbaikan. Satu permintaan setelah invalidasi memerlukan 4.718 ms sebelum perbaikan dan 4.652 ms sesudahnya. Badan HTML tetap 46.858 byte dan puncak alokasi PHP tetap 6 MiB. Pengukuran memakai satu klien loopback dan database pada tmpfs seperti benchmark sebelumnya.

Uji visibilitas menambahkan tiga profil FIKTIF dan mengisi cache sebelum mengubah satu profil menjadi privat, melindungi satu profil dengan kata sandi, dan menghapus satu profil. Total kembali dari 500.003 ke 500.000, ketiga opsi wilayah hilang dan ketiga permintaan REST anonim mendapat 404.

### Pencarian NPSN lengkap

Pencarian direktori sekarang mengutamakan NPSN delapan karakter yang cocok persis pada profil publik dalam filter aktif. Jika tidak ada kecocokan yang dapat ditampilkan, pencarian memakai aturan substring lama. Aturan ini menjaga pencarian nama, NPSN parsial, dan kata alfanumerik yang juga dapat muncul dalam nama sekolah. Jalur yang sama melayani formulir direktori dan hasil REST portal.

Pada 500.000 profil FIKTIF, sepuluh permintaan HTTP loopback setelah pemanasan menurunkan p50 dari 2.147 ms menjadi 20 ms dan p95 dari 2.517 ms menjadi 28 ms untuk satu NPSN yang tersedia. Seluruh permintaan berhasil dengan satu hasil identik dan badan respons 29.454 byte. Pada sampel terpisah, puncak alokasi PHP 4 MiB sebelum dan sesudah. MariaDB memilih kunci primer tabel sekolah dan artikel untuk kueri NPSN persis. Pengukuran ini memakai satu klien, cache opsi filter yang sudah terisi, dan database pada tmpfs. Pencarian substring masih dapat memindai tabel besar.

### Opsi akreditasi setelah invalidasi cache

Opsi akreditasi tanpa cakupan wilayah memakai kandidat dari indeks akreditasi. Setiap kandidat hanya ditampilkan jika ada profil publik tanpa kata sandi yang memiliki nilai tersebut. Daftar dengan cakupan wilayah tetap memakai kueri bercakupan.

Pada 500.000 profil FIKTIF yang sama, lima permintaan HTTP setelah invalidasi cache per kondisi menurunkan median pembukaan direktori dari 4.135 ms menjadi 2.543 ms. Median kueri pilihan akreditasi turun dari 1.590 ms menjadi 0,4 ms. Sepuluh permintaan berhasil, pilihan filter tetap A, B, C dan badan HTML tetap 46.858 byte. Puncak alokasi PHP 8 MiB dengan pencatatan kueri aktif. Ringkasan direktori dan daftar halaman pertama masih berkontribusi pada latensi. Lingkungan uji memakai satu klien loopback dan MariaDB pada tmpfs.

### Indeks pengurutan direktori

Indeks `sdp_name_order (nama,npsn)` pada tabel sekolah memungkinkan MariaDB mengambil hasil pertama dalam urutan direktori tanpa `filesort`. Pengurutan tetap memakai nama lengkap lalu NPSN. Kueri tetap memeriksa status publikasi, jenis artikel dan kata sandi lewat tabel artikel WordPress. Indeks nama awalan tetap tersedia bagi instalasi lama.

Pada 500.000 profil FIKTIF, median delapan permintaan dengan cache terisi turun dari 564 ms menjadi 18 ms. Median tiga permintaan setelah invalidasi cache turun dari 2.767 ms menjadi 2.010 ms. Satu sampel kueri halaman pertama turun dari 588 ms menjadi 0,7 ms. Respons tetap 46.858 byte dan puncak alokasi PHP pada pengukuran setelah invalidasi turun dari 8 MiB menjadi 6 MiB. Indeks tambahan berukuran sekitar 20,6 MiB pada InnoDB di dataset uji. Angka ini memakai satu klien, database pada tmpfs, dan PHP-FPM dua worker.

Pemilihan NPSN sebelum mengambil payload memberi urutan identik dengan waktu yang hampir sama pada tiga posisi halaman. Percobaan join untuk ringkasan juga memberi total identik. Indeks tambahan pada tabel artikel kurang membantu pada dataset ini sehingga dihapus dari database uji. Kueri penghitungan total saat cache kosong masih terbuka.

### Indeks ringkasan sekolah

Indeks `sdp_school_count (post_id,status)` memungkinkan MariaDB membaca ID artikel dan status negeri/swasta dari indeks sekolah. Kueri tetap memeriksa publikasi, jenis artikel, dan kata sandi melalui join WordPress. Pemilihan indeks mengikuti optimizer database tanpa petunjuk paksa.

Lima permintaan HTTP setelah invalidasi cache per kondisi pada 500.000 profil FIKTIF menurunkan median respons dari 2.092 ms menjadi 1.399 ms. Median kueri ringkasan dari jejak HTTP turun dari 1.512 ms menjadi 838 ms. Semua respons berukuran 46.858 byte dan puncak alokasi PHP terukur 8 MiB pada kedua kondisi. Indeks menambah sekitar 16,5 MiB pada penyimpanan dataset uji. Uji memakai satu klien loopback dan MariaDB pada tmpfs.

Enam variasi kueri menghasilkan total yang sama, yakni 500.000 profil yang terbagi rata antara negeri dan swasta. Penambahan indeks uji kedua pada tabel artikel menghasilkan penghematan tambahan yang lebih kecil dengan biaya sekitar 18,6 MiB, sehingga skema rilis menggunakan indeks pada tabel sekolah saja. Uji VPS 1 GB masih terbuka.
