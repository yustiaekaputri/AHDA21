#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p artifacts
docker compose exec -T -u www-data wordpress wp eval-file /sdp-tests/sdp-run-localwp.php DISPOSABLE-LOCAL-ONLY --user=sdp_local_admin 2>&1 | tee artifacts/smoke.log
grep -q 'SDP LOCALWP VALIDATION | COMPLETE' artifacts/smoke.log
