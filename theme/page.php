<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
} get_header(); ?>
<main id="main-content" class="sdt-wrap sdt-main" tabindex="-1">
<?php
sdtheme_extra();
while ( have_posts() ) :
	the_post();
	?>
<article <?php post_class( 'sdt-page' ); ?>><header class="sdt-page-heading"><p class="sdt-eyebrow">DIREKTORI PENDIDIKAN</p><h1><?php the_title(); ?></h1></header><div class="sdt-page-body sdt-prose">
	<?php
	the_content();
	wp_link_pages();
	?>
</div></article>
	<?php
	if ( comments_open() || get_comments_number() ) {
		comments_template();
	} endwhile;
?>
</main><?php get_footer(); ?>
