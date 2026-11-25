# Panduan kerja

- Pertahankan lima ZIP asli sebagai arsip. Ubah sumber di direktori yang sesuai.
- Gunakan data fiktif untuk pengujian dan jangan commit kredensial WordPress atau VPS.
- Jalankan pemeriksaan pada `.github/workflows/tests.yml` untuk perubahan plugin, tema, atau pengujian.
- Jangan merge, deploy atau menggunakan database produksi tanpa persetujuan. VPS awal RAM 1 GB, CPU terbatas (1 vCPU belum dikonfirmasi), bandwidth 10 GB (periode belum dikonfirmasi). Pertahankan mode hemat, proses on-demand, dan konfigurasi yang dapat ditingkatkan.
- Jalankan CI integrasi WordPress pada PHP 8.2/8.3 dengan MariaDB serta Playwright. Pisahkan bukti pengujian simulasi dan runtime. Jangan mengklaim rilis stabil sampai pengujian penerimaan nyata lulus.
- Preview lokal saja: data fiktif diberi label, noindex, statistik mengecualikannya. Jangan unggah screenshot autentikasi, cookies, log kredensial, atau konfigurasi produksi.
