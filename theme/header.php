<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;} ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class( 'sdt' ); ?>>
<?php wp_body_open(); ?>
<a class="sdt-skip" href="#main-content">Lewati ke konten</a>
<header class="sdt-header"><div class="sdt-wrap sdt-header-inner">
	<div class="sdt-brand">
	<?php
	if ( has_custom_logo() ) {
		the_custom_logo();} else {
		?>
		<a class="sdt-brand-icon" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Beranda"><svg viewBox="0 0 40 40" width="32" height="32" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linejoin="round"><path d="M3 15 20 6l17 9-17 9zM10 19v10l10 5 10-5V19M36 16v12"/></svg></a><?php } ?><div><a class="sdt-brand-name" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a><small>Direktori pendidikan</small></div></div>
	<nav class="sdt-primary" aria-label="Navigasi utama">
	<?php
	wp_nav_menu(
		array(
			'theme_location' => 'primary',
			'container'      => false,
			'menu_class'     => 'sdt-menu',
			'fallback_cb'    => 'sdtheme_fallback_menu',
			'depth'          => 2,
		)
	);
	?>
	</nav>
	<div class="sdt-actions"><button type="button" class="sdt-mode" data-sdt-mode hidden aria-pressed="false" aria-label="Ganti mode warna"><span aria-hidden="true">◐</span><span data-sdt-mode-label>Gelap</span></button></div>
</div></header>
