"""HTTP benchmark independently gated on completed 10k database benchmark."""
import json
import os
import re
import subprocess
import time
from pathlib import Path
from urllib.parse import urlencode, urlsplit, urlunsplit, parse_qsl
from urllib.request import urlopen

assert os.environ.get("GITHUB_ACTIONS")=="true"
assert os.environ.get("SDP_PREVIEW_URL")=="http://127.0.0.1:8080"
out=Path("artifacts/benchmark")
repo=Path(__file__).resolve().parents[1]
scale=json.loads((out/"dataset-10000.json").read_text())
assert scale["fixture_rows"]==10000 and scale["public_rows"]==10002

def extract(raw):
    decoder=json.JSONDecoder()
    for m in re.finditer(r"\{",raw):
        try:
            v,_=decoder.raw_decode(raw[m.start():])
            if isinstance(v,dict):
                return v
        except ValueError:
            pass
    raise ValueError("Missing benchmark JSON")

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
                with urlopen("http://127.0.0.1:8080/wp-includes/css/dashicons.min.css",timeout=3) as response:
                    if response.status==200:
                        break
            except OSError:
                time.sleep(1)
        else:
            raise RuntimeError("Preview server not ready")
        # Separate readiness from actual page timings. No slow-page success is fabricated.
        with urlopen(targets["directory"],timeout=15) as response:
            assert response.status==200 and b"sekolah ditemukan" in response.read(2*1024*1024), "Directory failed functional readiness"
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
print("PASS: bounded HTTP checks completed; not a production VPS benchmark")
