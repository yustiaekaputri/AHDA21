# WordPress nyata dan preview — AHDA21

Branch ini menambahkan stack pengujian sementara untuk plugin RC4 dan tema v7.0 tanpa mengubah source code utama. Tidak ada deployment VPS. Repo publik: semua kredensial dalam compose adalah dummy khusus DB lokal; jangan gunakan di produksi. Password administrator lokal dibuat acak dan disimpan pada .env.sdp-admin-password (diabaikan Git). Jangan unggah file tersebut atau log database.

## Lokal / Codex dengan Docker
Prasyarat Docker Compose v2, Python 3, curl, port 8080 bebas. Gunakan checkout khusus pengujian.
1. `bash scripts/setup-wordpress.sh DISPOSABLE-LOCAL-ONLY`
2. `bash scripts/smoke-wordpress.sh`
3. `mkdir -p artifacts; docker compose exec -T -u www-data wordpress wp eval-file /sdp-scripts/seed-preview.php --user=sdp_local_admin > artifacts/preview-urls.json`
4. `python3 -m pip install playwright==1.51.0`; `python3 -m playwright install chromium`; `python3 scripts/capture-preview.py`.
5. Buka http://127.0.0.1:8080 dari host lokal (bukan URL publik).
6. Setelah pengujian, hapus stack sekali pakai dengan `docker compose down -v` (menghapus volume pengujian). Jangan jalankan pada stack produksi.

## GitHub Actions
Workflow WordPress integration menguji PHP 8.2 dan 8.3 dengan MariaDB 10.11; smoke test menggunakan runner RC6 yang sudah ada. Screenshot anonim dan hasil JSON tersedia sebagai artifact setelah job berhasil mencapai tahap preview. Halaman statistik mungkin belum memiliki snapshot; halaman operator merupakan tampilan anonim/login. Ini belum menguji semua interaksi browser atau alur operator. Tidak ada public tunnel atau dashboard admin publik.
Jika Docker tidak tersedia di Codex, gunakan Actions dan periksa log kegagalan. Jangan menandai pengujian lulus sebelum run aktual selesai. Pertahankan RAM VPS 1 GB dan bandwidth 10 GB sebagai acuan; semua proses CI ini di luar VPS.

## Bukti CI awal (commit 7aa69aa3578036d7bd5a4c412569d37b0d636f00)
- [Regresi](https://github.com/yustiaekaputri/AHDA21/actions/runs/45711644064): sukses.
- [Integrasi](https://github.com/yustiaekaputri/AHDA21/actions/runs/45711644034): dua job sukses, PHP 8.2 dan 8.3. Preflight 18 pemeriksaan, smoke 40 pemeriksaan per job; fixture dibersihkan. Artifact PHP 8.3 diperiksa: 18 screenshot, 18 respons 200, tidak ada pageerror, dua ZIP valid. SHA-256 artifact: 50fd0a88a9f67bc41968b2caeb298a5e4027c8dc230ab8a02482fdca7ee5a233.
- Keterbatasan: snapshot statistik belum dihasilkan pada run awal; form perbandingan tidak diisi; screenshot operator hanya anonim; belum ada pengujian klaim dua akun, SEO lengkap, audit keamanan menyeluruh, dataset nyata, benchmark nasional atau deployment VPS.

## Perluasan pengujian berikutnya
Seed demo menjalankan snapshot nyata dengan 3 profil demo dikecualikan (bukan statistik pendidikan sebenarnya), mengatur halaman operator/perbandingan. Browser menguji tabel perbandingan berisi dua sekolah, pencarian NPSN/nama via GET tanpa JS, filter wilayah/jenjang, filter AJAX bertingkat, noindex, cache-control operator, login admin dan render portal terautentikasi. Tidak mengambil screenshot sesi admin pada repository publik. Laporan baru mencatat ukuran HTML dan waktu DOMContentLoaded pada runner CI; bukan benchmark VPS. Artifact hanya 14 hari dan run yang digantikan dibatalkan. Hasil perlu diverifikasi pada commit baru; keberhasilan run awal tidak menjamin perubahan baru lulus.

Kesiapan produksi tetap tertahan sampai pengujian penerimaan (termasuk akses operator lintas akun, impor nyata, audit keamanan dan benchmark VPS staging) selesai. Tidak ada merge atau deployment otomatis.
