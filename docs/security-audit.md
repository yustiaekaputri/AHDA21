# Pemeriksaan keamanan awal RC4

| Area | Hasil pengamatan |
| --- | --- |
| Otorisasi dan privasi | Smoke RC6 memastikan editor/subscriber tidak bisa mengubah profil lewat REST inti dan profil privat atau bersandi tidak muncul di portal/REST publik. Playwright menjalankan klaim sebagai subscriber dan peninjauan sebagai admin. |
| CSRF dan peninjauan | Formulir klaim, pengajuan dan moderasi memakai nonce yang terikat identitas. Moderasi juga memeriksa perubahan snapshot dan klaim aktif. |
| SQL dan integritas | WPDB menyiapkan nilai query yang diperiksa. Banyak query memasukkan nama tabel internal; perlu triase WPCS per query, khususnya bagian yang menyusun filter dan operasi admin. Tes integrasi memeriksa indeks dan relasi sebelum dan sesudah pembaruan. |
| XSS dan cache | Template mencampurkan HTML terbangun dan fungsi escaping WordPress. Temuan WPCS output escaping perlu triase per lokasi. Uji cache server/CDN dengan sesi pengguna terpisah masih tertunda. |
| SSRF dan egress | Kolektor yang diperiksa menggunakan `wp_safe_remote_get`, batas respons dan aturan redirect. Pratinjau mengaktifkan Safe Mode; tinjau semua jalur outbound dan kebijakan DNS/redirect sebelum otomasi produksi. |
| Upload dan path | Impor CSV/XLSX memeriksa upload dan izin. Konverter XLSX menggunakan pembacaan ZIP berukuran terbatas dan memvalidasi jalur bagiannya. Playwright menguji unggah XLSX, unduh CSV, impor dan impor ulang melalui admin. Uji unggahan media masih tertunda. |
| Rahasia | Kredensial pengembangan dibuat lokal. Lockfile Composer dan npm tidak melaporkan advisori ketika diperiksa. API key, cadangan dan opsi eksternal harus diperiksa lagi di staging. |

Temuan ini mencatat pemeriksaan sumber terarah dan tes integrasi awal. Audit menyeluruh semua endpoint dan modul masih menjadi syarat rilis.
