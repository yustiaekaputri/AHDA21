"""Real WordPress/MariaDB, bounded fictional benchmarks; CI only."""
import argparse
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
wp = ["php", "-d", "memory_limit=128M", os.environ["SDP_WP_CLI"], "--path=" + os.environ["SDP_WP_ROOT"], "--allow-root", "--user=sdp_preview_admin"]
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

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument("--max-rows",type=int,choices=[10000,50000],default=10000)
args=parser.parse_args()
if args.max_rows==50000 and os.environ.get("SDP_EXTENDED_BENCHMARK")!="1":
    raise RuntimeError("50k requires the opt-in extended workflow")
def fixture_code(i):
    if 0<=i<10000:
        return f"BENC{i:04d}"
    n=i-10000
    if not 0<=n<40000:
        raise ValueError("fixture ID out of range")
    digits="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    return "BENCA"+digits[n//1296]+digits[(n//36)%36]+digits[n%36]

# Unique, fixed eight-character IDs; retain the original 10k IDs.
ids={fixture_code(i) for i in range(args.max_rows)}
assert len(ids)==args.max_rows and all(re.fullmatch(r"BENC[A-Z0-9]{4}",x) for x in ids)
del ids
stages=[(0,100),(100,1000),(1000,10000)]
if args.max_rows==50000:
    stages.append((10000,50000))
call(["eval-file", gate, "0"])
for begin,end in stages:
    start=time.monotonic()
    checkpoints=[]
    # Bound source files; checkpointed importer still uses 100 records per call.
    for first in range(begin,end,5000):
        last=min(first+5000,end)
        f=root/f"batch-{first}-{last}.csv"
        with f.open("x",encoding="utf-8",newline="") as stream:
            writer=csv.writer(stream)
            writer.writerow(["npsn","nama","jenjang","status","provinsi","kabupaten","kecamatan","jumlah_siswa","jumlah_guru"])
            for i in range(first,last):
                writer.writerow([fixture_code(i),f"Sekolah Benchmark DEMO FIKTIF {i:04d}",("SD","SMP","SMA")[i%3],"Negeri",f"Provinsi Benchmark {i%10}",f"Kabupaten Benchmark {i%20}",f"Kecamatan Benchmark {i%50}",100+i%900,10+i%40])
        command=["sdp","import",str(f),"--authorized=1","--limit=100","--source=CI BENCHMARK DEMO FIKTIF","--publish"]
        for _ in range((last-first)//100+30):
            report=extract(call(command))
            checkpoints.append(report)
            assert report["invalid"]==0 and report["skipped"]==0,report
            if report["done"]:
                break
        assert report["done"] and report["saved"]==last-first and report["seen"]==last-first,report
        print(f"PASS: imported through fixture {last}/{end}; {time.monotonic()-start:.3f}s stage elapsed",flush=True)
    import_elapsed=round(time.monotonic()-start,3)
    # GNU time measures the complete WP-CLI process (not MariaDB or server-wide memory).
    timing = out / f"process-{end}.txt"
    result = subprocess.run(["/usr/bin/time","-v","-o",str(timing),*wp,"eval-file",gate,str(end)],text=True,capture_output=True,timeout=180 if end==50000 else 90)
    if result.returncode:
        raise RuntimeError(result.stdout+result.stderr)
    metrics=extract(result.stdout)
    metrics["import_elapsed_s"]=import_elapsed
    metrics["import_batches"]=len(checkpoints)
    metrics["import_new_rows"]=end-begin
    metrics["cli_memory_limit"]="128M"
    (out/f"dataset-{end}.json").write_text(json.dumps(metrics,indent=2,ensure_ascii=False)+"\n")
    print(f"PASS: {end} benchmark fixtures, {metrics['public_rows']} public profiles, {import_elapsed}s import; listing p95 {metrics['cases']['listing']['p95_ms']} ms")
print(f"PASS: all requested fictional scale stages through {args.max_rows} completed; not a production VPS benchmark")
