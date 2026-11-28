<?php
/** Populate an isolated demo via the same save API used by the plugin. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || (int) get_option('blog_public') !== 0 || !current_user_can('manage_options')
    || DB_NAME !== 'sdp_development') {
    exit(1);
}

$pages = [
    'beranda' => ['Beranda', 'Seluruh profil di situs ini adalah data DEMO FIKTIF untuk pengujian.'],
    'direktori-sekolah' => ['Direktori Sekolah', '[portal_sekolah]', 'sdp_page_id'],
    'bandingkan-sekolah' => ['Bandingkan Sekolah', '[bandingkan_sekolah]', 'sdp41_compare_page'],
    'statistik-pendidikan' => [
        'Statistik Pendidikan',
        "Ringkasan data DEMO untuk pengujian.\n\n[statistik_sekolah]\n\n[statistik_pendidikan]\n\n[perbandingan_periode_pendidikan slots=\"1,2\"]",
    ],
    'portal-operator' => ['Portal Operator', '[portal_operator_sekolah]', SDP_Operator::PAGE],
];

$menu = wp_get_nav_menu_object('Navigasi DEMO');
if (!$menu) {
    $menuId = wp_create_nav_menu('Navigasi DEMO');
    if (is_wp_error($menuId)) {
        WP_CLI::error($menuId->get_error_message());
    }
} else {
    $menuId = $menu->term_id;
}
$menuPages = array_map('intval', wp_list_pluck(wp_get_nav_menu_items($menuId) ?: [], 'object_id'));
foreach ($pages as $slug => [$title, $content]) {
    $existing = get_page_by_path($slug);
    $id = $existing ? $existing->ID : wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => $content,
    ], true);
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
    if ($slug === 'statistik-pendidikan' && $existing
        && $existing->post_content === "[statistik_sekolah]\n\n[statistik_pendidikan]") {
        $updated = wp_update_post(['ID' => $id, 'post_content' => $content], true);
        if (is_wp_error($updated)) {
            WP_CLI::error($updated->get_error_message());
        }
    }
    if (isset($pages[$slug][2])) {
        update_option($pages[$slug][2], $id);
    }
    if ($slug === 'beranda') {
        update_option('page_on_front', $id);
        update_option('show_on_front', 'page');
    }
    if (!in_array((int) $id, $menuPages, true)) {
        $item = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-object-id' => $id,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
        ]);
        if (is_wp_error($item)) {
            WP_CLI::error($item->get_error_message());
        }
    }
}
set_theme_mod('nav_menu_locations', ['primary' => $menuId]);
update_option('users_can_register', 1);
update_option('default_role', 'subscriber');

$guardWas = get_option('sdp_seo_config', []);
update_option('sdp_seo_config', array_merge((array) $guardWas, ['guard' => 0]));
try {
    for ($index = 0; $index <= 30; $index++) {
        $west = $index % 2 === 1;
        $result = SDP_Directory::save([
            // This extra fixture exercises operator workflows without relaxing the reserved NPSN prefixes.
            'npsn' => $index === 0 ? 'FIK00001' : sprintf('DEMO%04d', $index),
            'nama' => $index === 0 ? 'Sekolah DEMO 00' : sprintf('Sekolah DEMO FIKTIF %02d', $index),
            'jenjang' => $west ? 'SD' : 'MI',
            'status' => $index % 3 ? 'Negeri' : 'Swasta',
            'provinsi' => $west ? 'Jawa Barat' : 'Jawa Tengah',
            'kabupaten' => $west ? 'Kota Bandung' : 'Kota Semarang',
            'kecamatan' => $west ? 'Coblong' : 'Banyumanik',
            'jumlah_siswa' => (string) (100 + $index),
            'jumlah_guru' => '12',
            'tahun_data' => '2025/2026',
            'sumber' => $index === 0 ? 'Dataset DEMO untuk pengujian' : 'Dataset DEMO FIKTIF untuk pengujian',
        ], true, 'fill');
        if (is_wp_error($result) || get_post_status($result['id']) !== 'publish') {
            throw new RuntimeException(is_wp_error($result) ? $result->get_error_message() : 'Profil demo belum terbit.');
        }
    }
} finally {
    update_option('sdp_seo_config', $guardWas);
}
foreach ([1 => '2025/2026', 2 => '2024/2025'] as $slot => $period) {
    if ((get_option(SDP_Education::key($slot), [])['status'] ?? '') === 'complete') {
        continue;
    }
    $started = SDP_Education::start(['period' => $period], $slot);
    if (is_wp_error($started)) {
        WP_CLI::error($started->get_error_message());
    }
    for ($batch = 0; $batch < 3; $batch++) {
        $snapshot = SDP_Education::step();
        if (is_wp_error($snapshot)) {
            WP_CLI::error($snapshot->get_error_message());
        }
        if ($snapshot['status'] === 'complete') {
            break;
        }
    }
    if (($snapshot['status'] ?? '') !== 'complete') {
        WP_CLI::error('Snapshot DEMO belum selesai pada batas batch lokal.');
    }
}
WP_CLI::success('31 profil dan snapshot DEMO siap.');
