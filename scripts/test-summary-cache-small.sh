#!/usr/bin/env bash
set -euo pipefail
[[ "$GITHUB_ACTIONS" == true && "$SDP_PREVIEW_URL" == http://127.0.0.1:8080 ]]
wp=(php -d memory_limit=128M "$SDP_WP_CLI" --path="$SDP_WP_ROOT" --allow-root --user=sdp_preview_admin)
"${wp[@]}" eval-file tests/integration/test-summary-cache.php 0
cleanup() { "${wp[@]}" eval-file tests/integration/summary-cache-process.php cleanup; }
trap cleanup EXIT
for phase in prime hit hide hidden restore restored; do
  "${wp[@]}" eval-file tests/integration/summary-cache-process.php "$phase"
done
echo 'PASS: cross-process summary cache persistence and invalidation'
