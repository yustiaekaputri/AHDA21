<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
} get_header(); ?>
<main id="main-content" class="sdt-wrap sdt-main" tabindex="-1">
<?php
sdtheme_extra();
while ( have_posts() ) :
	the_post();
	?>
<div class="sdt-columns<?php echo sdtheme_sidebar() ? ' has-sidebar' : ''; ?>"><article <?php post_class( 'sdt-panel sdt-prose sdt-school' ); ?>><header class="sdt-page-heading"><p class="sdt-eyebrow">PROFIL SEKOLAH</p><h1><?php the_title(); ?></h1></header>
	<?php
	// Plugin renders statistics, gallery, sources and forms via the_content. Never bypass password protection.
	if ( ! post_password_required() && get_post_status() === 'publish' && has_post_thumbnail() ) {
		the_post_thumbnail(
			'large',
			array(
				'class'   => 'sdt-cover',
				'loading' => 'eager',
			)
		);
	}
	?>
	<?php
	the_content();
	wp_link_pages();
	?>
	</article>
	<?php
	if ( sdtheme_sidebar() ) {
		get_sidebar();}
	?>
</div><?php endwhile; ?></main><?php get_footer(); ?>
