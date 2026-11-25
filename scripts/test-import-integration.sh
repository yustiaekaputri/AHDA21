#!/usr/bin/env bash
# Real WP-CLI, only against the disposable seeded test database.
set -euo pipefail
repo="$(cd "$(dirname "$0")/.." && pwd)"
: "${SDP_WP_ROOT:?}" "${SDP_WP_CLI:?}" "${RUNNER_TEMP:?}"
export SDP_IMPORT_FIXTURES="$RUNNER_TEMP/sdp-import-fixtures"
wp=(php "$SDP_WP_CLI" --path="$SDP_WP_ROOT" --allow-root --user=sdp_preview_admin)
python3 "$repo/tests/integration/make_import_fixtures.py" "$SDP_IMPORT_FIXTURES"
check() { "${wp[@]}" eval-file "$repo/tests/integration/assert-import.php" "$1"; }
check initial
if "${wp[@]}" sdp import "$SDP_IMPORT_FIXTURES/batch.csv" --authorized=0; then
  echo 'Unauthorized import unexpectedly succeeded'; exit 1
fi
check initial
batch=("$SDP_IMPORT_FIXTURES/batch.csv" --authorized=1 --limit=1 '--source=CI DEMO FIKTIF' '--provinsi=Provinsi Fiktif A')
for round in 1 2 3 4 5; do
  "${wp[@]}" sdp import "${batch[@]}" | tee "$SDP_IMPORT_FIXTURES/round-$round.log"
  if [[ "$round" == 1 ]]; then check first; else check complete; fi
done
python3 - "$SDP_IMPORT_FIXTURES" <<'PY'
import json, re, sys
from pathlib import Path
root = Path(sys.argv[1])
expected = [(1,1,0,0,False),(2,1,1,0,False),(3,1,1,1,False),(3,1,1,1,True),(3,1,1,1,True)]
for i, want in enumerate(expected,1):
    raw = (root / f"round-{i}.log").read_text()
    match = re.search(r'\{\s*"offset"\s*:.*?\}', raw, re.S)
    assert match, f"Missing checkpoint JSON in round {i}"
    data = json.loads(match.group())
    got = tuple(data[key] for key in ("seen","saved","skipped","invalid","done"))
    assert got == want, (i,got,want)
print("PASS: persisted checkpoint, region filtering, invalid NPSN, EOF and repeated completed import")
PY
"${wp[@]}" sdp import "$SDP_IMPORT_FIXTURES/update.csv" --authorized=1 '--source=CI DEMO FIKTIF' --policy=fill
check updated
check converted
python3 - "$SDP_IMPORT_FIXTURES/converted.csv" <<'PY'
import csv, sys
with open(sys.argv[1], newline="", encoding="utf-8") as f:
    rows=list(csv.DictReader(f))
assert len(rows)==1 and rows[0]["npsn"]=="TEST0005" and rows[0]["nama"]=="SMP XLSX DEMO FIKTIF", rows
print("PASS: XLSX CSV values preserved")
PY
"${wp[@]}" sdp import "$SDP_IMPORT_FIXTURES/converted.csv" --authorized=1 '--source=CI XLSX DEMO FIKTIF'
check final
echo 'PASS: actual CSV/XLSX integration; disposable database only'
