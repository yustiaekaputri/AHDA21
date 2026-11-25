<?php
if (!defined('ABSPATH')) exit;
/** Presentation only: no writes to the school database. */
function sdtheme_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('custom-logo', ['height'=>64,'width'=>220,'flex-height'=>true,'flex-width'=>true]);
    add_theme_support('html5', ['search-form','comment-form','comment-list','gallery','caption','style','script']);
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    register_nav_menus(['primary'=>'Navigasi utama','footer'=>'Navigasi footer']);
}
add_action('after_setup_theme','sdtheme_setup');
function sdtheme_assets() {
    $version=wp_get_theme()->get('Version');
    wp_enqueue_style('sdtheme',get_stylesheet_uri(),[], $version);
    wp_enqueue_script('sdtheme',get_template_directory_uri().'/assets/theme.js',[], $version,true);
    if(is_singular() && comments_open() && get_option('thread_comments')) wp_enqueue_script('comment-reply');
}
// Override the plugin presentation, never its logic.
add_action('wp_enqueue_scripts','sdtheme_assets',99);
function sdtheme_widgets() {
    foreach(['sidebar'=>'Sidebar profil / artikel','before-content'=>'Area tambahan di atas konten (opsional)','footer'=>'Widget footer'] as $id=>$name) {
        register_sidebar(['id'=>$id,'name'=>$name,'before_widget'=>'<section id="%1$s" class="sdt-widget %2$s">','after_widget'=>'</section>','before_title'=>'<h2 class="sdt-widget-title">','after_title'=>'</h2>']);
    }
}
add_action('widgets_init','sdtheme_widgets');
function sdtheme_ready() { return shortcode_exists('direktori_sekolah'); }
function sdtheme_public_page($id,$shortcodes=[]) {
    $p=get_post((int)$id);
    if(!$p || $p->post_type!=='page' || $p->post_status!=='publish' || $p->post_password!=='') return 0;
    if(!$shortcodes) return (int)$p->ID;
    foreach($shortcodes as $tag) if(has_shortcode($p->post_content,$tag)) return (int)$p->ID;
    return 0;
}
function sdtheme_directory_id() {
    if(!sdtheme_ready()) return 0;
    foreach([get_option('page_on_front'),get_option('sdp_page_id'),get_theme_mod('sdtheme_directory_page',0)] as $id) {
        $valid=sdtheme_public_page($id,['direktori_sekolah','portal_sekolah']);
        if($valid && !get_post_meta($valid,'_sdp_region_scope',true)) return $valid;
    }
    return 0;
}
function sdtheme_directory_url($level='') {
    $id=sdtheme_directory_id();
    if(!$id) return '';
    $url=get_permalink($id);
    return $level?add_query_arg('sdp_jenjang',$level,$url):$url;
}
function sdtheme_compare_url() {
    if(!shortcode_exists('bandingkan_sekolah')) return '';
    $id=sdtheme_public_page(get_option('sdp41_compare_page'),['bandingkan_sekolah']);
    return $id?get_permalink($id):'';
}
function sdtheme_fallback_menu() {
    echo '<ul class="sdt-menu"><li><a href="'.esc_url(home_url('/')).'">Beranda</a></li>';
    if($url=sdtheme_directory_url()) echo '<li><a href="'.esc_url($url).'">Cari sekolah</a></li>';
    if($url=sdtheme_compare_url()) echo '<li><a href="'.esc_url($url).'">Bandingkan</a></li>';
    echo '</ul>';
}
function sdtheme_customizer($c) {
    $c->add_section('sdtheme_options',['title'=>'Pengaturan direktori','priority'=>35]);
    $c->add_setting('sdtheme_directory_page',['default'=>0,'sanitize_callback'=>'absint']);
    $c->add_control('sdtheme_directory_page',['section'=>'sdtheme_options','type'=>'dropdown-pages','label'=>'Halaman direktori cadangan','description'=>'Halaman publik dengan shortcode [direktori_sekolah] atau [portal_sekolah]. Halaman depan direktori dan halaman yang dibuat plugin mendapat prioritas.']);
    $c->add_setting('sdtheme_tagline',['default'=>'Informasi pendidikan dalam satu direktori.','sanitize_callback'=>'sanitize_text_field']);
    $c->add_control('sdtheme_tagline',['section'=>'sdtheme_options','type'=>'text','label'=>'Teks footer']);
}
add_action('customize_register','sdtheme_customizer');
function sdtheme_notice() {
    if(!current_user_can('manage_options') || sdtheme_directory_id()) return;
    echo '<div class="notice notice-info"><p>Sekolah Directory Theme: aktifkan plugin Sekolah Directory Pro v6.4 dan buat halaman publik berisi shortcode <code>[direktori_sekolah]</code>. Pilih halaman tersebut di Settings → Reading agar pencarian tersedia di beranda. <a href="'.esc_url(admin_url('options-reading.php')).'">Pengaturan beranda</a>.</p></div>';
}
add_action('admin_notices','sdtheme_notice');
function sdtheme_levels() {
    if(!sdtheme_directory_id()) return;
    $levels=['PAUD','TK','RA','SD','MI','SMP','MTs','SMA','MA','SMK','MAK','SLB','PKBM'];
    echo '<nav class="sdt-levelnav" aria-label="Jelajahi jenjang"><h2>Jelajahi jenjang</h2><ul>';
    foreach($levels as $level) echo '<li><a href="'.esc_url(sdtheme_directory_url($level)).'">'.esc_html($level).'</a></li>';
    echo '</ul></nav>';
}
function sdtheme_extra() { if(is_active_sidebar('before-content')) {echo '<div class="sdt-extra">';dynamic_sidebar('before-content');echo '</div>';} }
function sdtheme_sidebar() { return is_active_sidebar('sidebar') || sdtheme_directory_id() || sdtheme_compare_url(); }
function sdtheme_pagination() { the_posts_pagination(['mid_size'=>1,'prev_text'=>'← Sebelumnya','next_text'=>'Berikutnya →']); }
