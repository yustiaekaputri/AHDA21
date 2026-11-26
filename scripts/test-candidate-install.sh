#!/usr/bin/env bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${SDP_PREVIEW_URL:-}" == http://127.0.0.1:8080 && "$SDP_WP_ROOT" == "$RUNNER_TEMP/sdp-wordpress" ]]
mode="${1:?fresh or upgrade}"
[[ "$mode" == fresh || "$mode" == upgrade ]]
repo="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo"
candidate="$RUNNER_TEMP/candidate"
paths="$(python3 - "$candidate" "$(git rev-parse HEAD)" <<'PY'
import hashlib,json,sys,zipfile
from pathlib import Path
root=Path(sys.argv[1])
manifest=json.loads((root/"manifest.json").read_text())
assert manifest["commit"]==sys.argv[2] and manifest["status"]=="development_candidate_not_release"
assert len(manifest["packages"])==2
for slug in ("sekolah-directory-pro","sekolah-directory-theme"):
    matches=[p for p in manifest["packages"] if p["file"].startswith(slug+"-candidate-")]
    assert len(matches)==1
    p=matches[0];name=p["file"]
    assert Path(name).name==name and name.endswith(".zip")
    f=root/name
    assert not f.is_symlink() and hashlib.sha256(f.read_bytes()).hexdigest()==p["sha256"]
    with zipfile.ZipFile(f) as z:
        assert z.testzip() is None and len(z.namelist())==p["files"]
        assert all(x.startswith(slug+"/") for x in z.namelist())
    print(f)
PY
)"
mapfile -t archives <<< "$paths"
[[ "${#archives[@]}" == 2 ]]
plugin="${archives[0]}"
theme="${archives[1]}"
wp=(php -d memory_limit=128M "$SDP_WP_CLI" --path="$SDP_WP_ROOT" --allow-root --user=sdp_preview_admin)
mkdir -p artifacts
if [[ "$mode" == upgrade ]]; then
  SDP_PLUGIN_ARCHIVE="$repo/sekolah-directory-pro-v10.0.0-rc4.zip" SDP_THEME_ARCHIVE="$repo/sekolah-directory-theme-v7.0.0.zip" bash scripts/setup-preview.sh
  "${wp[@]}" eval-file tests/integration/package-state.php original > artifacts/before-upgrade.json
  "${wp[@]}" plugin install "$plugin" --force
  "${wp[@]}" plugin activate sekolah-directory-pro
  "${wp[@]}" theme install "$theme" --force
  "${wp[@]}" theme activate sekolah-directory-theme
else
  SDP_PLUGIN_ARCHIVE="$plugin" SDP_THEME_ARCHIVE="$theme" bash scripts/setup-preview.sh
fi
"${wp[@]}" eval-file tests/integration/package-state.php candidate > artifacts/after-install.json
if [[ "$mode" == upgrade ]]; then
  diff -u artifacts/before-upgrade.json artifacts/after-install.json
  echo 'PASS: RC4 ZIP replacement preserves fixture payload hashes, post IDs and visibility'
fi
bash scripts/test-import-integration.sh
bash scripts/test-summary-cache-small.sh
echo "PASS: $mode actual candidate ZIP installation and integration"
