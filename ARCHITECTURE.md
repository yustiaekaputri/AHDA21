# Arsitektur

WordPress menyimpan profil sebagai custom post type `sdp_school` dan mengindeksnya pada tabel `sdp_schools` dengan prefiks situs. Identitas NPSN menjadi kunci utama dan `post_id` menghubungkan indeks ke artikel. Validasi dan operasi tulis melewati `SDP_Directory::save`; audit dua arah berada di `SDP_Integrity`.

Portal membaca profil yang terbit tanpa perlindungan sandi. REST membaca model publik yang sama. Tema mengurus presentasi dan tautan, sementara plugin memiliki pencarian, statistik, kontrol akses, klaim, dan moderasi. Penyaringan lintas wilayah mengombinasikan indeks dengan cache facet berumur terbatas.

Kolektor, konverter dan persiapan pilot mengolah data secara bertahap. Scheduler beroperasi sesuai permintaan dan tunduk pada Safe Mode dan preset sumber daya. Bukti operator dan catatan usulan tetap di tabel yang diawasi admin. Keputusan peninjauan dan sumber terverifikasi disimpan per atribut ketika tersedia.
