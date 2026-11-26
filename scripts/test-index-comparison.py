"""Compare existing optional indexes on an isolated 10k fixture database."""
import json
import os
import subprocess
from pathlib import Path

assert os.environ.get("GITHUB_ACTIONS")=="true"
assert os.environ.get("SDP_PREVIEW_URL")=="http://127.0.0.1:8080"
repo=Path(__file__).resolve().parents[1]
out=Path("artifacts/benchmark")
out.mkdir(parents=True,exist_ok=True)
wp=["php","-d","memory_limit=128M",os.environ["SDP_WP_CLI"],"--path="+os.environ["SDP_WP_ROOT"],"--allow-root","--user=sdp_preview_admin"]
reports={}
for phase in ("baseline","indexed"):
    run=subprocess.run(wp+["eval-file",str(repo/"tests/integration/profile-queries.php"),phase],text=True,capture_output=True,timeout=180)
    if run.returncode:
        raise RuntimeError(run.stdout+run.stderr)
    report=json.loads(run.stdout)
    reports[phase]=report
    (out/f"sql-{phase}.json").write_text(json.dumps(report,indent=2,ensure_ascii=False)+"\n")
a,b=reports["baseline"],reports["indexed"]
assert a["portal"]==b["portal"], "Portal results changed after index build"
rows=[]
for scenario, queries in a["queries"].items():
    for name, baseline in queries.items():
        indexed=b["queries"][scenario][name]
        assert baseline["result_sha256"]==indexed["result_sha256"], (scenario,name,"SQL results changed")
        row={"scenario":scenario,"query":name,"baseline_p95_ms":baseline["p95_ms"],"indexed_p95_ms":indexed["p95_ms"]}
        rows.append(row)
        print(f"{scenario}/{name}: {row['baseline_p95_ms']} -> {row['indexed_p95_ms']} ms (p95)")
(out/"sql-comparison.json").write_text(json.dumps({"queries":rows,"added_indexes":b["added_indexes"],"ddl_ms":b["ddl_ms"],"note":"Sequential within-run comparison; no timing threshold, no production change"},indent=2)+"\n")
print("PASS: all SQL and portal results identical before/after optional index build")
