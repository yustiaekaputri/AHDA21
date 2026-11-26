"""Compare equivalent SQL query shapes, no schema modifications."""
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
r=subprocess.run(wp+["eval-file",str(repo/"tests/integration/compare-query-shapes.php")],capture_output=True,text=True,timeout=240)
if r.returncode:
    raise RuntimeError(r.stdout+r.stderr)
report=json.loads(r.stdout)
(out/"sql-query-shapes.json").write_text(json.dumps(report,indent=2)+"\n")
for name, variants in report["cases"].items():
    assert len(variants)==4 and all(x["samples"]==5 for x in variants.values())
    assert len({x["result_sha256"] for x in variants.values()})==1
    print(name+": "+", ".join(f"{k}={v['median_ms']}ms median" for k,v in variants.items()))
print("PASS: all candidate SQL result hashes identical; no schema or production query changes")
