<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
} get_header(); ?>
<main id="main-content" class="sdt-wrap sdt-main" tabindex="-1"><?php sdtheme_extra(); ?><header class="sdt-page-heading"><h1>
<?php
if ( is_search() ) {
	echo 'Hasil pencarian: ' . esc_html( get_search_query( false ) );
} elseif ( is_archive() ) {
	the_archive_title();
} else {
	echo 'Artikel terbaru';
}
?>
</h1></header>
<?php
if ( have_posts() ) :
	?>
	<div class="sdt-post-grid">
	<?php
	while ( have_posts() ) :
		the_post();
		get_template_part( 'template-parts/card' );
endwhile;
	?>
</div>
	<?php
	sdtheme_pagination(); else :
		?>
	<div class="sdt-panel"><p>Belum ada hasil yang sesuai.</p><?php get_search_form(); ?></div><?php endif; ?></main><?php get_footer(); ?>
