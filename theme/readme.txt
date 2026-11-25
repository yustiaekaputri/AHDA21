Sekolah Directory Theme 7.0.0
============================
Tema klasik WordPress, lapisan tampilan untuk Sekolah Directory Pro 6.4.0.
Minimal WordPress 6.4 dan PHP 7.4 (metadata tema; persyaratan plugin tetap berlaku).

INSTALASI LOCALWP
1. Backup situs, database dan wp-content.
2. Aktifkan Sekolah Directory Pro v6.4.0 dari Plugins. Jika sudah aktif, tidak perlu instal ulang.
3. Appearance / Tampilan > Themes / Tema > Add New > Upload Theme.
4. Pilih sekolah-directory-theme-v7.0.0.zip, instal dan aktifkan.
5. Direktori Sekolah > Dashboard: buat halaman direktori, jika belum ada.
   Alternatif: buat halaman publik dengan satu shortcode [direktori_sekolah].
6. Settings > Reading > Your homepage displays > A static page.
   Pilih halaman direktori publik tersebut sebagai homepage. Simpan.
7. Appearance > Customize > Site Identity: atur judul dan logo situs.
   Appearance > Menus: tetapkan navigasi utama dan footer jika diinginkan.
   Customize > Pengaturan direktori: atur halaman cadangan dan teks footer.
8. Direktori Sekolah > Analitik & Koreksi: buat halaman perbandingan apabila belum ada.
9. Buka beranda, profil sekolah, halaman wilayah dan perbandingan untuk uji.

FITUR
- Template homepage, page (termasuk halaman wilayah dan perbandingan), single-sdp_school,
  single artikel, indeks/arsip/pencarian, komentar dan 404.
- Header, footer, menu cadangan, tombol mode gelap dengan penyimpanan lokal.
- Sidebar profil dengan tautan direktori, perbandingan dan jenjang.
- Widget sidebar, footer, dan area sebelum konten (opsional; bukan jaringan iklan).
- CSS responsif dan cetak, memakai markup shortcode dan aset dari plugin.
- Tidak membutuhkan Elementor, font eksternal, CDN atau API berbayar dari tema.
- Tidak menambah tabel, menulis ulang sekolah, atau menjalankan pengumpulan data.
- Plugin tetap bertanggung jawab atas pencarian AJAX, SEO, halaman wilayah, media dan profil.
- Halaman wilayah harus dibuat dari modul SEO & AI plugin dan dipublikasikan setelah diperiksa.
- Shortcode [statistik_sekolah] bisa ditambahkan pada halaman tersendiri; tema tidak menghitung statistik nasional baru.

PENGUJIAN LOKAL YANG WAJIB
- Coba aktivasi tema dengan dan tanpa plugin; pastikan tidak ada fatal error.
- Gunakan data fiktif hanya pada website lokal. Hapus sebelum publikasi.
- Periksa homepage, pencarian/filter AJAX, profil sekolah dan galeri, perbandingan,
  halaman wilayah, menu, widget, mode gelap, tampilan pada 360px dan 1280px, serta cetak.
- Pastikan tema tidak mengekspos profil privat/password-protected dalam direktori.
- Uji pergantian kembali ke tema lama; plugin dan data tetap terpisah.
- Periksa log PHP LocalWP apabila terjadi masalah.

BATASAN
Belum diuji dalam WordPress/MySQL/LocalWP sungguhan atau visual browser.
Pengujian PHP dan JavaScript menggunakan fungsi WordPress/DOM simulasi; tidak membuktikan
seluruh halaman, AJAX, aksesibilitas, kontras, atau performa. Percobaan Chromium headless timeout.
Tema tidak menyediakan konektor pemerintah baru, tema tidak berisi data sekolah nasional,
dan fitur plugin 6.4 masih beta sesuai batasan dokumentasi plugin.
Lisensi tema: GPL-2.0-or-later.
