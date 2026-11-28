#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
scripts/setup.sh
scripts/install.sh
scripts/wp.sh eval-file /opt/sdp/scripts/seed-demo.php --user=sdp-admin
