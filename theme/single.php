<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
} get_header(); ?>
<main id="main-content" class="sdt-wrap sdt-main" tabindex="-1">
<?php
sdtheme_extra();
while ( have_posts() ) :
	the_post();
	?>
	<div class="sdt-columns<?php echo sdtheme_sidebar() ? ' has-sidebar' : ''; ?>"><article <?php post_class( 'sdt-panel sdt-prose' ); ?>><header class="sdt-page-heading"><p class="sdt-eyebrow"><?php echo esc_html( get_the_date() ); ?></p><h1><?php the_title(); ?></h1></header>
	<?php
	if ( ! post_password_required() && has_post_thumbnail() ) {
		the_post_thumbnail( 'large', array( 'class' => 'sdt-cover' ) );
	} the_content();
	wp_link_pages();
	if ( comments_open() || get_comments_number() ) {
		comments_template();}
	?>
</article>
	<?php
	if ( sdtheme_sidebar() ) {
		get_sidebar();}
	?>
</div><?php endwhile; ?></main><?php get_footer(); ?>
