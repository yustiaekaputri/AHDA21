<?php
/** Bulk synthetic data for a read-only benchmark on the disposable MariaDB instance. */
global $wpdb;
$schools = SDP_Directory::table();
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || DB_NAME !== 'sdp_development'
    || (int) get_option('blog_public') !== 0 || !current_user_can('manage_options')
    || getenv('SDP_BUDGET_PROFILES') !== '100000' || ($args[0] ?? '') !== 'DISPOSABLE-LOCAL-ONLY') {
    exit(1);
}
$prototype = (int) $wpdb->get_var("SELECT post_id FROM $schools WHERE npsn='TEST0000'");
if (!$prototype || (int) $wpdb->get_var("SELECT COUNT(*) FROM $schools") !== 1) {
    WP_CLI::error('Satu profil FIKTIF diperlukan sebagai contoh.');
}

$run = static function (string $sql) use ($wpdb): void {
    if ($wpdb->query($sql) === false) {
        throw new RuntimeException($wpdb->last_error);
    }
};
$started = hrtime(true);
$run('CREATE TEMPORARY TABLE sdp_budget_fixture (id bigint unsigned PRIMARY KEY, npsn varchar(8), nama varchar(191), provinsi varchar(120)) ENGINE=InnoDB');
for ($first = 1; $first < 100000; $first += 10000) {
    $last = min($first + 9999, 99999);
    $run('START TRANSACTION');
    try {
        $run('DELETE FROM sdp_budget_fixture');
        $run("INSERT INTO sdp_budget_fixture SELECT 100000 + seq, CONCAT('TEST', LPAD(CONV(seq,10,36),4,'0')),
            CONCAT('Sekolah FIKTIF ', LPAD(seq,6,'0')), CONCAT('Provinsi Fiktif ',LPAD(MOD(seq,38),2,'0')) FROM seq_{$first}_to_{$last}");
        $run("INSERT INTO {$wpdb->posts} (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,
            comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,
            post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
            SELECT f.id,p.post_author,p.post_date,p.post_date_gmt,CONCAT('Profil FIKTIF ',f.npsn),f.nama,'','publish',
            'closed','closed','',LOWER(CONCAT('sekolah-fiktif-',f.npsn)),'','',p.post_modified,p.post_modified_gmt,'',0,
            CONCAT('http://127.0.0.1:8080/?post_type=sdp_school&p=',f.id),0,'sdp_school','',0
            FROM sdp_budget_fixture f JOIN {$wpdb->posts} p ON p.ID=$prototype");
        $run("INSERT INTO $schools (npsn,post_id,nama,jenjang,status,akreditasi,provinsi,kabupaten,kecamatan,payload,updated_at)
            SELECT f.npsn,f.id,f.nama,i.jenjang,i.status,i.akreditasi,f.provinsi,i.kabupaten,i.kecamatan,
            JSON_SET(i.payload,'$.npsn',f.npsn,'$.nama',f.nama,'$.provinsi',f.provinsi),i.updated_at
            FROM sdp_budget_fixture f JOIN $schools i ON i.post_id=$prototype");
        $run("INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value)
            SELECT f.id,m.meta_key,IF(m.meta_key='_sdp_npsn',f.npsn,m.meta_value)
            FROM sdp_budget_fixture f JOIN {$wpdb->postmeta} m ON m.post_id=$prototype");
        $run('COMMIT');
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        WP_CLI::error($error->getMessage());
    }
}

$count = $wpdb->get_row("SELECT COUNT(*) AS total, COUNT(DISTINCT p.ID) AS posts,
    SUM(p.ID IS NULL OR p.post_type<>'sdp_school' OR p.post_status<>'publish' OR p.post_password<>''
        OR JSON_UNQUOTE(JSON_EXTRACT(s.payload,'$.npsn'))<>s.npsn) AS invalid
    FROM $schools s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id", ARRAY_A);
if (!$count || (int) $count['total'] !== 100000 || (int) $count['posts'] !== 100000 || (int) $count['invalid'] !== 0) {
    WP_CLI::error('Relasi fixture dengan artikel WordPress tidak sesuai.');
}
SDP_Performance::invalidate();
WP_CLI::log(wp_json_encode(['fixtures' => 100000, 'method' => 'synthetic SQL after one WordPress profile',
    'seconds' => round((hrtime(true) - $started) / 1e9, 3)], JSON_PRETTY_PRINT));
