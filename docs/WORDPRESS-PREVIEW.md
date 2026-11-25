# WordPress Integration and Preview (draft)

This configuration runs real WordPress, the checked-out plugin and theme, PHP 8.2/8.3 and MariaDB 10.11 in GitHub Actions. The original simulation workflow is unchanged. It is an initial integration gate, not production approval.

## GitHub Actions

Open Actions > WordPress Integration and Preview. The workflow runs on pushes and pull requests. Each matrix job provisions its own disposable database, checks that it is empty, runs the existing RC4 preflight and smoke test, then seeds two fictional profiles and renders actual pages in Chromium. Download the wordpress-preview-php-* artifacts for screenshots and logs. Check the job result, not only the presence of artifacts. No public preview URL is provisioned. The server is bound to 127.0.0.1 and terminates after the job.

## Codex Cloud or local Linux

Prerequisites: PHP CLI with mysqli and other WP extensions, WP-CLI or its PHAR, curl, openssl, bash, a reachable local MariaDB/MySQL instance, and an empty disposable database named sdp_test with a test user named sdp_test. If these dependencies cannot be installed in the Codex environment, use GitHub Actions. Never point these scripts at a production installation.

Set SDP_WP_ROOT to a new empty temporary directory, SDP_DB_PASSWORD to the disposable database user's password, SDP_DB_PORT if needed, and SDP_PREVIEW_MANIFEST to a new absolute path outside WordPress. Optionally set SDP_WP_CLI to the downloaded PHAR and SDP_WP_VERSION to a specific WordPress version (default: current release).
Run bash scripts/setup-preview.sh, then bash scripts/start-preview.sh. Open http://127.0.0.1:8080 in the same environment. Use a supported private preview mechanism only if provided by the environment; do not open a public tunnel. To repeat, provision a new empty test database and directory; scripts intentionally refuse an existing database. For browser checks install Playwright and Chromium, then run python tests/browser/test_preview.py with the same manifest environment variable.

The preview fixtures are marked DEMO FIKTIF. No production credentials are included, no VPS configuration changes, no deployment. The workflow's database password is a throwaway test value for an isolated service container, not a secret used outside CI. WordPress and WP-CLI are fetched at run time; pin and expand versions after the first successful run. PHP built-in server is not a production web server.

Remaining work: full browser scenarios including authenticated operator/admin, real source imports, migration, load/performance and independent security testing. Screenshots are not a substitute for functional assertions.

Additional browser tests: server-side region/level filters, comparison results, anonymous operator access, and (when SDP_PREVIEW_AUTH is set to a new file outside WordPress and outside artifacts) authenticated subscriber and administrator access separation. CI enables this option and removes the file before artifact upload. Never upload the credential file or screenshots of authenticated pages. These tests do not yet cover operator claim submission or approval.

Login diagnostics: SDP_LOGIN_PROBE=1 runs real unauthenticated HTTP probes on a fresh core install, after plugin/theme activation and after demo seeding. The probes check core files and the actual form, failing at the first broken stage. No credentials are used. The integration workflow runs on pull requests and pushes to main (plus manual dispatch) to avoid duplicate full browser jobs for each PR commit. Codespaces configuration gets syntax validation in CI, but a real Codespace build and forwarding test remain manual.

The core-stage probe in run 45717768374 found that `/wp-admin/` redirected to `/sdp-wordpress/wp-login.php` (404), before plugin/theme activation. Setup now explicitly sets the `home` and `siteurl` options plus `WP_HOME` and `WP_SITEURL` constants to the loopback origin and asserts the effective canonical and login URLs. The HTTP probes still must pass all three stages; no authenticated test is skipped. Verification pending CI.
