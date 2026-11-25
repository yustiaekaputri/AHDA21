# Preview WordPress privat via GitHub Codespaces

This is an unverified first Codespaces configuration. It does not create a Codespace automatically or use the production VPS. GitHub compute and storage may consume the personal account's included quota or incur charges subject to billing settings.

1. Open the repository and select the branch containing this configuration (codex/wordpress-integration-preview until merged).
2. Code > Codespaces > Create codespace on the selected branch. Choose the smallest available machine and check your billing budget.
3. Once the dev container has finished building, open the Ports tab. Ensure port 8080 is Private (not Public or Organization); never forward the database port. Open a terminal.
4. Run:
       bash scripts/codespace-preview.sh PRIVATE-DEMO-ONLY
5. When setup, preflight and smoke checks complete, open the printed HTTPS URL from the same signed-in GitHub account (desktop or phone). The URL is unique to your Codespace and valid only while running. If needed use the Ports tab > Open in Browser. The preview serves fictional demo profiles, not real school data. No administrator password is provided in this demo; do not expose administrative pages or publish the port.
6. Stop with Ctrl+C and stop the Codespace from https://github.com/codespaces when not in use. Run the same command to resume the same commit, provided the temporary container state still exists. Delete disposable Codespaces after use to avoid storage consumption. To test new commits, create a fresh Codespace; the script never wipes a database.

The dev container uses Debian Bookworm PHP packages and MariaDB. Only the loopback database and web ports are used. WordPress and WP-CLI are downloaded during first manual setup. The existing smoke test runs before the canonical URL is changed to the HTTPS forwarding URL; test mode is then disabled. HTTPS handling is based on the known Codespaces environment, not incoming request headers. This is not a production-grade web server or long-lived staging deployment. Do not use real data or production credentials, and do not set the port Public.

If the build, setup or proxy check fails, inspect the terminal, report the error and use a fresh Codespace; do not bypass the empty database checks. The GitHub Actions screenshot preview remains a fallback.
