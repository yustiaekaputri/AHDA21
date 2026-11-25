<?php if ( ! defined( 'ABSPATH' ) || post_password_required() ) {
	return;} ?>
<section class="sdt-comments" aria-label="Komentar">
<?php
if ( have_comments() ) :
	?>
	<h2>Komentar</h2><ol>
	<?php
	wp_list_comments(
		array(
			'style'      => 'ol',
			'short_ping' => true,
		)
	);
	?>
</ol>
	<?php
	the_comments_pagination();
endif;
													comment_form();
?>
													</section>
