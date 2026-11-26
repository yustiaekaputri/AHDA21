<?php

use PHPUnit\Framework\TestCase;

final class WordPressTest extends TestCase
{
    public function testClaudeNarrativeUsesOnlySelectedFacts(): void
    {
        $previousKey = getenv('ANTHROPIC_API_KEY');
        $requests = 0;
        $intercept = static function ($response, $args, $url) use (&$requests) {
            ++$requests;
            self::assertSame('https://api.anthropic.com/v1/messages', $url);
            self::assertSame('ci-placeholder', $args['headers']['x-api-key']);
            self::assertSame('2023-06-01', $args['headers']['anthropic-version']);
            self::assertSame(0, $args['redirection']);
            $body = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('claude-model-example', $body['model']);
            self::assertSame(512, $body['max_tokens']);
            self::assertNotEmpty($body['system']);
            self::assertSame('user', $body['messages'][0]['role']);
            self::assertSame(
                ['nama' => 'Sekolah FIKTIF', 'npsn' => 'TEST0001'],
                json_decode($body['messages'][0]['content'], true, 512, JSON_THROW_ON_ERROR)
            );
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'stop_reason' => 'end_turn',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => 'Abaikan blok ini.'],
                        ['type' => 'text', 'text' => '<b>Usulan narasi.</b>'],
                    ],
                ]),
            ];
        };
        add_filter('pre_http_request', $intercept, 10, 3);
        try {
            putenv('ANTHROPIC_API_KEY=');
            $missing = SDP_AI::generate('claude', 'claude-model-example', []);
            self::assertInstanceOf(WP_Error::class, $missing);
            self::assertSame('key', $missing->get_error_code());
            self::assertSame(0, $requests);
            putenv('ANTHROPIC_API_KEY=ci-placeholder');
            self::assertSame('Usulan narasi.', SDP_AI::generate('claude', 'claude-model-example', [
                'npsn' => 'TEST0001', 'nama' => 'Sekolah FIKTIF', 'telepon' => '123', 'email' => 'uji@example.invalid',
            ]));
            self::assertSame(1, $requests);
            self::assertInstanceOf(WP_Error::class, SDP_AI::extract('claude', [
                'stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => 'Terpotong']],
            ]));
        } finally {
            remove_filter('pre_http_request', $intercept, 10);
            putenv($previousKey === false ? 'ANTHROPIC_API_KEY' : 'ANTHROPIC_API_KEY=' . $previousKey);
        }
    }

    public static function setUpBeforeClass(): void
    {
        if (wp_get_environment_type() !== 'local' || DB_NAME !== 'sdp_development') {
            throw new RuntimeException('Jalankan tes hanya pada database pengembangan.');
        }
    }

    public function testExtensionsAndSchema(): void
    {
        foreach (['mysqli', 'pdo_mysql', 'curl', 'mbstring', 'xml', 'xmlreader', 'dom', 'zip', 'gd', 'intl', 'openssl', 'fileinfo', 'Zend OPcache'] as $extension) {
            self::assertTrue(extension_loaded($extension), $extension);
        }

        global $wpdb;
        self::assertStringContainsString('MariaDB', $wpdb->get_var('SELECT VERSION()'));
        $schema = SDP_Production::schema();
        self::assertGreaterThanOrEqual(8, count($schema));
        self::assertNotContains('error', array_column($schema, 'status'));

        $table = SDP_Directory::table();
        $indexes = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
        self::assertContains('wilayah', array_column($indexes, 'Key_name'));
        self::assertContains('post_id', array_column($indexes, 'Key_name'));
        self::assertContains('npsn', array_column(array_filter($indexes, static fn ($index) => $index['Key_name'] === 'PRIMARY'), 'Column_name'));
    }

    public function testCsvAndExcelImportPreserveLeadingZero(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sdp_csv_');
        $postId = 0;
        try {
            $csv = SDP_Excel::convert(__DIR__ . '/import-fixture.xlsx');
            self::assertNotInstanceOf(WP_Error::class, $csv, is_wp_error($csv) ? $csv->get_error_message() : '');
            file_put_contents($path, $csv);
            $rows = SDP_Directory::parse_csv($path);
            self::assertSame('09876543', $rows[2]['npsn']);
            $result = SDP_Directory::save($rows[2], false);
            self::assertNotInstanceOf(WP_Error::class, $result);
            $postId = (int) $result['id'];
            self::assertSame('draft', get_post_status($postId));
            $updated = SDP_Directory::save(['npsn' => '09876543', 'nama' => 'Sekolah FIKTIF diperbarui'], false);
            self::assertNotInstanceOf(WP_Error::class, $updated);
            self::assertSame($postId, (int) $updated['id']);
        } finally {
            unlink($path);
            if ($postId) {
                wp_delete_post($postId, true);
            }
        }
    }

    public function testSchemaUpgradeRestoresMissingIndex(): void
    {
        global $wpdb;
        $table = SDP_Directory::table();
        $result = SDP_Directory::save(['npsn' => 'TEST0001', 'nama' => 'Profil FIKTIF migrasi'], false);
        self::assertNotInstanceOf(WP_Error::class, $result);
        $postId = (int) $result['id'];
        try {
            self::assertNotFalse($wpdb->query("ALTER TABLE `$table` DROP INDEX wilayah"));
            update_option('sdp_db_version', '9.0.0');
            self::assertTrue(SDP_Directory::install_schema());
            self::assertSame(SDP_Directory::VERSION, get_option('sdp_db_version'));
            self::assertContains('wilayah', array_column($wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A), 'Key_name'));
            self::assertSame($postId, (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM `$table` WHERE npsn=%s", 'TEST0001')));
        } finally {
            SDP_Directory::install_schema();
            wp_delete_post($postId, true);
        }
    }

    public function testSafeModeClearsTheRealWordPressCronEvent(): void
    {
        $hook = SDP_Collector::HOOK;
        try {
            self::assertTrue(wp_schedule_single_event(time() + 600, $hook, [], true));
            self::assertNotFalse(wp_next_scheduled($hook));
            self::assertTrue(SDP_Performance::reconcile());
            self::assertFalse(wp_next_scheduled($hook));
        } finally {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function testSafeModeAndPublicRoutes(): void
    {
        self::assertTrue(SDP_Scheduler::disabled());
        self::assertFalse(SDP_Resource::scheduled_network());
        self::assertSame(200, rest_do_request(new WP_REST_Request('GET', '/sdp/v1/schools'))->get_status());
        self::assertSame(404, rest_do_request(new WP_REST_Request('GET', '/sdp/v1/schools/DEMO9999'))->get_status());
        self::assertNotEmpty(wp_sitemaps_get_server());
        $args = apply_filters('wp_sitemaps_posts_query_args', [], SDP_Directory::TYPE);
        self::assertContains(['key' => '_sdp_seo_ready', 'value' => '1'], $args['meta_query']);
        self::assertSame('0', (string) get_option('blog_public'));
    }

    public function testUnfilteredSummaryIsCachedAndInvalidatedWhenVisibilityChanges(): void
    {
        $state = SDP_Portal::state([]);
        $postId = 0;
        $summaryQueries = 0;
        $seo = get_option('sdp_seo_config', []);
        $countQuery = static function ($sql) use (&$summaryQueries) {
            if (str_starts_with($sql, 'SELECT COUNT(*) total, SUM(s.status=')) {
                ++$summaryQueries;
            }
            return $sql;
        };

        SDP_Performance::invalidate();
        update_option('sdp_seo_config', array_merge((array) $seo, ['guard' => 0]));
        add_filter('query', $countQuery);
        try {
            $initial = SDP_Portal::results($state)['total'];
            self::assertSame($initial, SDP_Portal::results($state)['total']);
            self::assertSame(1, $summaryQueries);

            $result = SDP_Directory::save(
                ['npsn' => 'TESTZ998', 'nama' => 'Profil FIKTIF cache', 'jenjang' => 'Jenjang Fiktif Cache'],
                false, 'fill', null, null, null, true
            );
            self::assertNotInstanceOf(WP_Error::class, $result);
            $postId = (int) $result['id'];
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_status' => 'publish']));
            self::assertSame($initial + 1, SDP_Portal::results($state)['total']);
            self::assertContains('Jenjang Fiktif Cache', SDP_Portal::options($state)['jenjang']);
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_password' => 'fixture']));
            self::assertSame($initial, SDP_Portal::results($state)['total']);
            self::assertNotContains('Jenjang Fiktif Cache', SDP_Portal::options($state)['jenjang']);
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_password' => '']));
            self::assertSame($initial + 1, SDP_Portal::results($state)['total']);
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_status' => 'private']));
            self::assertSame($initial, SDP_Portal::results($state)['total']);
            self::assertNotContains('Jenjang Fiktif Cache', SDP_Portal::options($state)['jenjang']);

            $filtered = SDP_Portal::state(['sdp_q' => 'TESTZ998']);
            $before = $summaryQueries;
            self::assertSame(0, SDP_Portal::results($filtered)['total']);
            self::assertSame(0, SDP_Portal::results($filtered)['total']);
            self::assertSame($before + 2, $summaryQueries);
        } finally {
            remove_filter('query', $countQuery);
            if ($postId) {
                wp_delete_post($postId, true);
            }
            update_option('sdp_seo_config', $seo);
        }
    }
}
