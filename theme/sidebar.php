<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;} ?>
<aside class="sdt-sidebar" aria-label="Navigasi tambahan">
<?php
if ( $url = sdtheme_directory_url() ) :
	?>
	<section class="sdt-widget"><h2 class="sdt-widget-title">Temukan sekolah lain</h2><p>Telusuri profil berdasarkan wilayah, jenjang dan fasilitas.</p><a class="sdt-button" href="<?php echo esc_url( $url ); ?>">Buka direktori →</a></section><?php endif; ?>
<?php
if ( $url = sdtheme_compare_url() ) :
	?>
	<section class="sdt-widget"><h2 class="sdt-widget-title">Bandingkan sekolah</h2><p>Lihat data hingga empat sekolah berdampingan.</p><a href="<?php echo esc_url( $url ); ?>">Buka perbandingan →</a></section><?php endif; ?>
<?php
sdtheme_levels();
dynamic_sidebar( 'sidebar' );
?>
</aside>
