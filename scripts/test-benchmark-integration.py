"""Real WordPress/MariaDB, bounded fictional benchmarks; CI only."""
import csv
import json
import os
import re
import subprocess
import time
from urllib.parse import urlencode, urlsplit, urlunsplit, parse_qsl
from urllib.request import urlopen
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

call(["eval-file", gate, "0"])
for begin, end in [(0, 100), (100, 1000), (1000, 10000)]:
    f = root / f"batch-{end}.csv"
    with f.open("x", encoding="utf-8", newline="") as stream:
        writer = csv.writer(stream)
        writer.writerow(["npsn","nama","jenjang","status","provinsi","kabupaten","kecamatan","jumlah_siswa","jumlah_guru"])
        for i in range(begin, end):
            writer.writerow([f"BENC{i:04d}",f"Sekolah Benchmark DEMO FIKTIF {i:04d}",("SD","SMP","SMA")[i%3],"Negeri",f"Provinsi Benchmark {i%10}",f"Kabupaten Benchmark {i%20}",f"Kecamatan Benchmark {i%50}",100+i%900,10+i%40])
    command = ["sdp","import",str(f),"--authorized=1","--limit=100","--source=CI BENCHMARK DEMO FIKTIF","--publish"]
    start = time.monotonic()
    checkpoints = []
    for _ in range((end-begin)//100 + 30):
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
    metrics["cli_memory_limit"]="128M"
    (out/f"dataset-{end}.json").write_text(json.dumps(metrics,indent=2,ensure_ascii=False)+"\n")
    print(f"PASS: {end} benchmark fixtures, {metrics['public_rows']} public profiles, {import_elapsed}s import; listing p95 {metrics['cases']['listing']['p95_ms']} ms")
# Measure actual HTTP responses against the final dataset, with bounded clients.
manifest=json.loads(Path(os.environ["SDP_PREVIEW_MANIFEST"]).read_text())
directory=manifest["pages"]["directory"]
def query(url, **params):
    u=urlsplit(url)
    assert u.scheme=="http" and u.hostname=="127.0.0.1" and u.port==8080
    return urlunsplit(u._replace(query=urlencode(dict(parse_qsl(u.query)) | params)))
targets={
    "directory":query(directory),
    "search":query(directory,sdp_q="BENC9999"),
    "page_100":query(directory,sdp_p=100),
    "region":query(directory,sdp_provinsi="Provinsi Benchmark 0"),
}
with (out/"http-server.log").open("w") as log:
    server=subprocess.Popen(["php","-d","memory_limit=128M","-S","127.0.0.1:8080","-t",os.environ["SDP_WP_ROOT"]],stdout=log,stderr=log)
    try:
        for _ in range(30):
            if server.poll() is not None:
                raise RuntimeError("Preview server stopped")
            try:
                with urlopen(targets["directory"],timeout=2) as response:
                    if response.status==200:
                        break
            except OSError:
                time.sleep(1)
        else:
            raise RuntimeError("Preview server not ready")
        with urlopen(targets["search"],timeout=10) as response:
            body=response.read(2*1024*1024).decode("utf-8",errors="replace")
            assert response.status==200 and "Sekolah Benchmark DEMO FIKTIF 9999" in body
        for label,url in targets.items():
            for workers in (1,2):
                command=["python3",str(repo/"tools/benchmark_local.py"),"http",url,"--local-test","--requests","12","--workers",str(workers)]
                report=extract(subprocess.run(command,check=True,text=True,capture_output=True,timeout=90).stdout)
                assert report["attempts"]==12 and report["ok"]==12 and report["errors"]==0, report
                report["scope"]="PHP development server, single server worker, loopback HTTP; not VPS capacity"
                (out/f"http-{label}-{workers}.json").write_text(json.dumps(report,indent=2)+"\n")
                print(f"PASS: HTTP {label}, {workers} clients, p95 {report['p95_ms']} ms, body {report['mean_success_body_bytes']} bytes")
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
print("PASS: all three fictional scale stages and bounded HTTP checks completed; not a production VPS benchmark")
