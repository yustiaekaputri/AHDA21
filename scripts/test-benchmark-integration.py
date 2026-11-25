"""Real WordPress/MariaDB, bounded fictional benchmarks; CI only."""
import csv
import json
import os
import re
import subprocess
import time
from pathlib import Path

assert os.environ.get("GITHUB_ACTIONS") == "true", "CI only"
assert os.environ.get("SDP_PREVIEW_URL") == "http://127.0.0.1:8080", "Loopback only"
root = Path(os.environ["RUNNER_TEMP"]) / "sdp-benchmark"
root.mkdir(exist_ok=False)
out = Path("artifacts/benchmark")
out.mkdir(parents=True, exist_ok=True)
repo = Path(__file__).resolve().parents[1]
wp = ["php", os.environ["SDP_WP_CLI"], "--path=" + os.environ["SDP_WP_ROOT"], "--allow-root", "--user=sdp_preview_admin"]
gate = str(repo / "tests/integration/benchmark-gate.php")

def call(args):
    result = subprocess.run(wp + args, text=True, capture_output=True, timeout=90)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result.stdout

def extract(raw):
    decoder = json.JSONDecoder()
    for m in re.finditer(r"\{", raw):
        try:
            value, _ = decoder.raw_decode(raw[m.start():])
            if isinstance(value, dict):
                return value
        except ValueError:
            pass
    raise ValueError("No JSON report: " + raw[:300])

call(["eval-file", gate, "0"])
for begin, end in [(0, 100), (100, 1000)]:
    f = root / f"batch-{end}.csv"
    with f.open("x", encoding="utf-8", newline="") as stream:
        writer = csv.writer(stream)
        writer.writerow(["npsn","nama","jenjang","status","provinsi","kabupaten","kecamatan","jumlah_siswa","jumlah_guru"])
        for i in range(begin, end):
            writer.writerow([f"BENC{i:04d}",f"Sekolah Benchmark DEMO FIKTIF {i:04d}",("SD","SMP","SMA")[i%3],"Negeri",f"Provinsi Benchmark {i%10}",f"Kabupaten Benchmark {i%20}",f"Kecamatan Benchmark {i%50}",100+i%900,10+i%40])
    command = ["sdp","import",str(f),"--authorized=1","--limit=100","--source=CI BENCHMARK DEMO FIKTIF","--publish"]
    start = time.monotonic()
    checkpoints = []
    for _ in range(30):
        report = extract(call(command))
        checkpoints.append(report)
        assert report["invalid"]==0 and report["skipped"]==0, report
        if report["done"]:
            break
    assert report["done"] and report["saved"]==end-begin and report["seen"]==end-begin, report
    import_elapsed = round(time.monotonic()-start,3)
    # GNU time measures the complete WP-CLI process (not MariaDB or server-wide memory).
    timing = out / f"process-{end}.txt"
    result = subprocess.run(["/usr/bin/time","-v","-o",str(timing),*wp,"eval-file",gate,str(end)],text=True,capture_output=True,timeout=90)
    if result.returncode:
        raise RuntimeError(result.stdout+result.stderr)
    metrics=extract(result.stdout)
    metrics["import_elapsed_s"]=import_elapsed
    metrics["import_batches"]=len(checkpoints)
    metrics["import_new_rows"]=end-begin
    (out/f"dataset-{end}.json").write_text(json.dumps(metrics,indent=2,ensure_ascii=False)+"\n")
    print(f"PASS: {end} benchmark fixtures, {metrics['public_rows']} public profiles, {import_elapsed}s import; listing p95 {metrics['cases']['listing']['p95_ms']} ms")
print("PASS: both fictional scale stages completed; not a production VPS benchmark")
