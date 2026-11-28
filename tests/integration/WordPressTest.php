<?php

use PHPUnit\Framework\TestCase;

final class WordPressTest extends TestCase
{
    public function testClaudeModelCheckSendsNoPromptAndHandlesAccessDenial(): void
    {
        $previousKey = getenv('ANTHROPIC_API_KEY');
        $requests = 0;
        $intercept = static function ($response, $args, $url) use (&$requests) {
            ++$requests;
            self::assertSame('GET', $args['method']);
            self::assertEmpty($args['body']);
            self::assertSame('ci-placeholder', $args['headers']['x-api-key']);
            self::assertSame(0, $args['redirection']);
            if ($requests === 3) {
                self::assertSame('https://api.anthropic.com/v1/models?limit=20', $url);
                return ['response' => ['code' => 200], 'body' => wp_json_encode(['data' => [
                    ['type' => 'model', 'id' => 'claude-model-example'],
                    ['type' => 'model', 'id' => 'claude-model-example'],
                    ['type' => 'model', 'id' => 'another/model'],
                ]])];
            }
            self::assertSame('https://api.anthropic.com/v1/models/claude-model-example', $url);
            return $requests === 1
                ? ['response' => ['code' => 200], 'body' => '{"type":"model","id":"claude-model-example"}']
                : ['response' => ['code' => 403], 'body' => '{"error":"provider-only-detail"}'];
        };
        add_filter('pre_http_request', $intercept, 10, 3);
        try {
            foreach (['' => 'key', 'GANTI_DENGAN_KUNCI_ANDA' => 'key', 'ci invalid' => 'key_format'] as $value => $code) {
                putenv('ANTHROPIC_API_KEY=' . $value);
                $status = SDP_AI::claude_status();
                self::assertInstanceOf(WP_Error::class, $status);
                self::assertSame($code, $status->get_error_code());
            }
            putenv('ANTHROPIC_API_KEY=ci-placeholder');
            $status = SDP_AI::claude_status();
            if ((new WP_Http())->block_request('https://api.anthropic.com')) {
                self::assertInstanceOf(WP_Error::class, $status);
                self::assertSame('http_blocked', $status->get_error_code());
            } else {
                self::assertTrue($status);
            }
            self::assertInstanceOf(WP_Error::class, SDP_AI::check_claude_model('another/model'));
            self::assertSame(0, $requests);
            self::assertTrue(SDP_AI::check_claude_model('claude-model-example'));
            $denied = SDP_AI::check_claude_model('claude-model-example');
            self::assertInstanceOf(WP_Error::class, $denied);
            self::assertStringContainsString('HTTP 403', $denied->get_error_message());
            self::assertStringNotContainsString('provider-only-detail', $denied->get_error_message());
            self::assertSame(2, $requests);
            self::assertSame(['claude-model-example'], SDP_AI::list_claude_models());
            self::assertSame(3, $requests);
        } finally {
            remove_filter('pre_http_request', $intercept, 10);
            putenv($previousKey === false ? 'ANTHROPIC_API_KEY' : 'ANTHROPIC_API_KEY=' . $previousKey);
        }
    }

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
        $orderIndex = array_values(array_filter($indexes, static fn ($index) => $index['Key_name'] === 'sdp_name_order'));
        self::assertSame(['nama', 'npsn'], array_column($orderIndex, 'Column_name'));
        self::assertSame([null, null], array_column($orderIndex, 'Sub_part'));
        $countIndex = array_values(array_filter($indexes, static fn ($index) => $index['Key_name'] === 'sdp_school_count'));
        self::assertSame(['post_id', 'status'], array_column($countIndex, 'Column_name'));
        $provinceIndex = array_values(array_filter($indexes, static fn ($index) => $index['Key_name'] === 'sdp_province_order'));
        self::assertSame(['provinsi', 'nama', 'npsn', 'post_id', 'status', 'kabupaten'], array_column($provinceIndex, 'Column_name'));
        $searchIndex = array_values(array_filter($indexes, static fn ($index) => $index['Key_name'] === 'sdp_search_count'));
        self::assertSame(['post_id', 'status', 'nama', 'npsn'], array_column($searchIndex, 'Column_name'));
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
        $result = SDP_Directory::save(['npsn' => 'FIKMIG01', 'nama' => 'Profil FIKTIF migrasi'], false);
        self::assertNotInstanceOf(WP_Error::class, $result);
        $postId = (int) $result['id'];
        try {
            self::assertNotFalse($wpdb->query("ALTER TABLE `$table` DROP INDEX wilayah"));
            self::assertNotFalse($wpdb->query("ALTER TABLE `$table` DROP INDEX sdp_name_order"));
            self::assertNotFalse($wpdb->query("ALTER TABLE `$table` DROP INDEX sdp_school_count"));
            self::assertNotFalse($wpdb->query("ALTER TABLE `$table` DROP INDEX sdp_province_order"));
            update_option('sdp_db_version', '9.0.0');
            $skipIndex = static function (string $query) use ($table): string {
                return str_starts_with(str_replace('`', '', $query), "ALTER TABLE $table ADD KEY sdp_province_order ")
                    ? 'SELECT 1'
                    : $query;
            };
            add_filter('query', $skipIndex);
            try {
                self::assertFalse(SDP_Directory::install_schema());
                self::assertSame('9.0.0', get_option('sdp_db_version'));
            } finally {
                remove_filter('query', $skipIndex);
            }
            self::assertTrue(SDP_Directory::install_schema());
            self::assertSame(SDP_Directory::VERSION, get_option('sdp_db_version'));
            self::assertContains('wilayah', array_column($wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A), 'Key_name'));
            self::assertContains('sdp_name_order', array_column($wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A), 'Key_name'));
            self::assertContains('sdp_school_count', array_column($wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A), 'Key_name'));
            self::assertContains('sdp_province_order', array_column($wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A), 'Key_name'));
            self::assertSame($postId, (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM `$table` WHERE npsn=%s", 'FIKMIG01')));
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

    public function testPublicSchoolDetailUsesTheNpsnInTheRoute(): void
    {
        $seo = get_option('sdp_seo_config', []);
        $postId = 0;
        update_option('sdp_seo_config', array_merge((array) $seo, ['guard' => 0]));
        try {
            $saved = SDP_Directory::save(
                ['npsn' => 'TESTR009', 'nama' => 'Sekolah FIKTIF REST', 'email' => 'fixture@example.invalid'],
                false, 'fill', null, null, null, true
            );
            self::assertNotInstanceOf(WP_Error::class, $saved);
            $postId = (int) $saved['id'];
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_status' => 'publish']));

            $request = new WP_REST_Request('GET', '/sdp/v1/schools/TESTR009');
            $request->set_query_params(['npsn' => 'TESTZ999']);
            $response = rest_do_request($request);
            self::assertSame(200, $response->get_status());
            self::assertSame('TESTR009', $response->get_data()['npsn']);
            self::assertArrayNotHasKey('email', $response->get_data());
            self::assertArrayNotHasKey('payload', $response->get_data());
        } finally {
            if ($postId) {
                wp_delete_post($postId, true);
            }
            update_option('sdp_seo_config', $seo);
        }
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
            $initialSummary = SDP_Portal::results($state);
            $initial = $initialSummary['total'];
            self::assertSame($initial, SDP_Portal::results($state)['total']);
            self::assertSame(1, $summaryQueries);

            $result = SDP_Directory::save(
                ['npsn' => 'TESTZ998', 'nama' => 'Profil FIKTIF cache', 'jenjang' => 'Jenjang Fiktif Cache', 'provinsi' => 'Wilayah FIKTIF Cache', 'akreditasi' => 'UJI CACHE', 'status' => 'Negeri'],
                false, 'fill', null, null, null, true
            );
            self::assertNotInstanceOf(WP_Error::class, $result);
            $postId = (int) $result['id'];
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_status' => 'publish']));
            $published = SDP_Portal::results($state);
            self::assertSame($initial + 1, $published['total']);
            self::assertSame($initialSummary['negeri'] + 1, $published['negeri']);
            self::assertSame($initialSummary['swasta'], $published['swasta']);
            self::assertContains('Jenjang Fiktif Cache', SDP_Portal::options($state)['jenjang']);
            self::assertContains('UJI CACHE', SDP_Portal::options($state)['akreditasi']);
            $scope = ['provinsi' => 'Wilayah FIKTIF Cache'];
            $scopedState = SDP_Portal::state([], $scope);
            self::assertSame(['UJI CACHE'], SDP_Portal::options($scopedState, $scope)['akreditasi']);
            $before = $summaryQueries;
            self::assertSame(1, SDP_Portal::results($scopedState)['total']);
            self::assertSame(1, SDP_Portal::results($scopedState)['total']);
            self::assertSame($before + 1, $summaryQueries);
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_password' => 'fixture']));
            $protected = SDP_Portal::results($state);
            self::assertSame($initial, $protected['total']);
            self::assertSame($initialSummary['negeri'], $protected['negeri']);
            self::assertSame($initialSummary['swasta'], $protected['swasta']);
            self::assertNotContains('Jenjang Fiktif Cache', SDP_Portal::options($state)['jenjang']);
            self::assertNotContains('UJI CACHE', SDP_Portal::options($state)['akreditasi']);
            self::assertSame([], SDP_Portal::options($scopedState, $scope)['akreditasi']);
            self::assertSame(0, SDP_Portal::results($scopedState)['total']);
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_password' => '']));
            self::assertSame($initial + 1, SDP_Portal::results($state)['total']);
            self::assertSame($postId, wp_update_post(['ID' => $postId, 'post_status' => 'private']));
            self::assertSame($initial, SDP_Portal::results($state)['total']);
            self::assertNotContains('Jenjang Fiktif Cache', SDP_Portal::options($state)['jenjang']);
            self::assertNotContains('UJI CACHE', SDP_Portal::options($state)['akreditasi']);

            $filtered = SDP_Portal::state(['sdp_q' => 'TESTZ998']);
            $before = $summaryQueries;
            self::assertSame(0, SDP_Portal::results($filtered)['total']);
            self::assertSame(0, SDP_Portal::results($filtered)['total']);
            self::assertSame($before + 4, $summaryQueries);
        } finally {
            remove_filter('query', $countQuery);
            if ($postId) {
                wp_delete_post($postId, true);
            }
            update_option('sdp_seo_config', $seo);
        }
    }

    public function testDirectoryPrefersExactNpsnAndFallsBackWithinPublicFilters(): void
    {
        $ids = [];
        $seo = get_option('sdp_seo_config', []);
        update_option('sdp_seo_config', array_merge((array) $seo, ['guard' => 0]));
        try {
            foreach ([
                ['ZTEST817', 'Sekolah FIKTIF Samudera', 'Wilayah FIKTIF Satu'],
                ['ZTEST818', 'Sekolah FIKTIF ZTEST817', 'Wilayah FIKTIF Dua'],
                ['00817654', 'Madrasah FIKTIF Pesisir', 'Wilayah FIKTIF Dua'],
            ] as [$npsn, $name, $province]) {
                $result = SDP_Directory::save(['npsn' => $npsn, 'nama' => $name, 'provinsi' => $province], false);
                self::assertNotInstanceOf(WP_Error::class, $result);
                $ids[] = $id = (int) $result['id'];
                self::assertSame($id, wp_update_post(['ID' => $id, 'post_status' => 'publish']));
            }

            $find = static function (array $query): array {
                $result = SDP_Portal::results(SDP_Portal::state($query));
                self::assertArrayNotHasKey('error', $result);
                return array_column($result['rows'], 'npsn');
            };

            self::assertSame(['ZTEST817'], $find(['sdp_q' => 'ztest817']));
            self::assertSame(['00817654'], $find(['sdp_q' => '00817654']));
            self::assertSame(['ZTEST817'], $find(['sdp_q' => 'Samudera']));
            self::assertSame([], $find(['sdp_q' => 'Kata FIKTIF tidak ada']));
            self::assertEqualsCanonicalizing(['ZTEST817', 'ZTEST818'], $find(['sdp_q' => 'ZTEST81']));
            self::assertSame(['ZTEST818'], $find(['sdp_q' => 'ztest817', 'sdp_provinsi' => 'Wilayah FIKTIF Dua']));
            self::assertSame([], $find(['sdp_q' => '00817654', 'sdp_provinsi' => 'Wilayah FIKTIF Satu']));

            self::assertSame($ids[0], wp_update_post(['ID' => $ids[0], 'post_password' => 'fixture']));
            self::assertSame(['ZTEST818'], $find(['sdp_q' => 'ZTEST817']));
            self::assertSame(['ZTEST818'], $find(['sdp_q' => 'ZTEST81']));
            self::assertSame($ids[0], wp_update_post(['ID' => $ids[0], 'post_password' => '', 'post_status' => 'private']));
            self::assertSame(['ZTEST818'], $find(['sdp_q' => 'ZTEST817']));
        } finally {
            foreach ($ids as $id) {
                wp_delete_post($id, true);
            }
            update_option('sdp_seo_config', $seo);
        }
    }

    public function testRemoteSchoolMediaValidatesSourceAndResponse(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $website = 'https://school.example.org';
        $url = $website . '/gambar.png';
        $rateKey = 'sdp61_rate_' . md5('school.example.org');
        $requests = 0;
        $status = 200;
        $mime = 'image/png';
        $path = '';

        $image = imagecreatetruecolor(24, 16);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $intercept = static function ($response, $args, $requestedUrl) use ($url, $png, &$requests, &$status, &$mime) {
            ++$requests;
            self::assertSame($url, $requestedUrl);
            self::assertSame(0, $args['redirection']);
            self::assertTrue($args['sslverify']);
            self::assertTrue($args['reject_unsafe_urls']);
            return ['response' => ['code' => $status], 'headers' => ['content-type' => $mime], 'body' => $png];
        };

        delete_transient($rateKey);
        add_filter('pre_http_request', $intercept, 10, 3);
        try {
            $mismatch = SDP_Media::fetch('https://other.example.org/gambar.png', $website);
            self::assertInstanceOf(WP_Error::class, $mismatch);
            self::assertSame('url', $mismatch->get_error_code());
            self::assertSame(0, $requests);

            $result = SDP_Media::fetch($url, $website);
            self::assertIsString($result);
            $path = $result;
            self::assertSame([24, 16], array_slice(getimagesize($path), 0, 2));
            $wait = SDP_Media::fetch($url, $website);
            self::assertInstanceOf(WP_Error::class, $wait);
            self::assertSame('rate', $wait->get_error_code());
            self::assertSame(1, $requests);

            delete_transient($rateKey);
            $status = 302;
            $redirect = SDP_Media::fetch($url, $website);
            self::assertInstanceOf(WP_Error::class, $redirect);
            self::assertSame('http', $redirect->get_error_code());

            delete_transient($rateKey);
            $status = 200;
            $mime = 'image/jpeg';
            $wrongType = SDP_Media::fetch($url, $website);
            self::assertInstanceOf(WP_Error::class, $wrongType);
            self::assertSame('format', $wrongType->get_error_code());
            self::assertSame(3, $requests);
        } finally {
            remove_filter('pre_http_request', $intercept, 10);
            delete_transient($rateKey);
            if ($path !== '') {
                unlink($path);
            }
        }
    }
}
