# Roadmap

1. Jalankan workflow pada repositori GitHub target dan tinjau laporan CI pada PHP 8.2 dan 8.3.
2. Triase temuan WPCS serta audit endpoint, media, cache proxy dan jaringan scheduler. Jalankan pengujian migrasi dari paket versi lama dan latihan pemulihan staging.
3. Optimalkan query direktori tanpa filter berdasarkan `EXPLAIN`. Ukur ulang 10.000 profil dan lanjutkan hingga dataset lebih besar pada host uji yang memadai.
4. Konfirmasi kapasitas VPS dan persyaratan backup sebelum uji staging. Pertahankan Safe Mode serta preset hemat selama validasi awal.
5. Impor pilot sekolah nyata hanya dari sumber resmi yang bisa digunakan sesuai izin. Tinjau NPSN, data wilayah, periode, kualitas dan provenance sebelum perluasan.
6. Buat kandidat rilis stabil setelah pengujian utama lulus dan bukti staging terdokumentasi.
