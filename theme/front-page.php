<?php if(!defined('ABSPATH')) exit; get_header(); ?>
<main id="main-content" class="sdt-wrap sdt-main" tabindex="-1">
<?php sdtheme_extra(); ?>
<?php if(is_page() && sdtheme_directory_id()===get_queried_object_id()): ?>
    <?php while(have_posts()): the_post(); ?><h1 class="sdt-visually-hidden"><?php the_title(); ?></h1><div class="sdt-directory-content"><?php the_content(); ?></div><?php endwhile; ?>
<?php else: ?>
    <section class="sdt-welcome"><p class="sdt-eyebrow">DIREKTORI PENDIDIKAN</p><h1>Temukan sekolah yang Anda cari.</h1><p>Jelajahi profil, lokasi, akreditasi, fasilitas dan statistik sekolah serta madrasah.</p><?php if($url=sdtheme_directory_url()): ?><a class="sdt-button" href="<?php echo esc_url($url); ?>">Cari sekolah →</a><?php else: ?><p>Direktori belum tersedia.<?php if(current_user_can('manage_options')): ?> Aktifkan plugin dan atur halaman direktori sebagai beranda.<?php endif; ?></p><?php endif; ?></section>
    <?php sdtheme_levels(); if(is_page()): while(have_posts()): the_post(); ?><article class="sdt-panel sdt-prose"><h2><?php the_title(); ?></h2><?php the_content(); wp_link_pages(); ?></article><?php endwhile; elseif(have_posts()): ?><h2>Artikel terbaru</h2><div class="sdt-post-grid"><?php while(have_posts()): the_post(); get_template_part('template-parts/card'); endwhile; ?></div><?php sdtheme_pagination(); endif; ?>
<?php endif; ?>
</main><?php get_footer(); ?>
