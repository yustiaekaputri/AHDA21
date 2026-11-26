"""Compare real HTTP directory responses with summary cache off/on, isolated 10k CI only."""
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
root=Path(os.environ["SDP_WP_ROOT"]).resolve()
repo=Path(__file__).resolve().parents[1]
out=Path("artifacts/benchmark")
scale=json.loads((out/"dataset-10000.json").read_text())
assert scale["fixture_rows"]==10000 and scale["public_rows"]==10002
manifest=json.loads(Path(os.environ["SDP_PREVIEW_MANIFEST"]).read_text())
u=urlsplit(manifest["pages"]["directory"])
assert u.scheme=="http" and u.hostname=="127.0.0.1" and u.port==8080
def url(**params):
    return urlunsplit(u._replace(query=urlencode(dict(parse_qsl(u.query))|params)))
wp=["php","-d","memory_limit=128M",os.environ["SDP_WP_CLI"],"--path="+str(root),"--allow-root","--user=sdp_preview_admin"]
def call(*args):
    subprocess.run(wp+list(args),check=True,timeout=60)
def extract(raw):
    decoder=json.JSONDecoder()
    for m in re.finditer(r"\{",raw):
        try:
            value,_=decoder.raw_decode(raw[m.start():])
            if isinstance(value,dict):
                return value
        except ValueError:
            pass
    raise ValueError("Missing JSON")
# Configuration changes are allowed only on the isolated generated WordPress.
assert root == (Path(os.environ["RUNNER_TEMP"])/"sdp-wordpress").resolve()
assert not (root/"wp-config.php").is_symlink()
call("eval","if(wp_get_environment_type()!=='local'||get_option('blog_public')||!defined('SDP_SAFE_MODE')||!SDP_SAFE_MODE||defined('SDP_SUMMARY_CACHE')||!current_user_can('manage_options')||!defined('SDP_SMOKE_TEST_ENABLED')||!SDP_SMOKE_TEST_ENABLED) WP_CLI::error('Unsafe or already configured');")
report={}
def read_page(target):
    with urlopen(target,timeout=15) as response:
        assert response.status==200
        body=response.read(2*1024*1024+1)
        assert len(body)<=2*1024*1024
        text=body.decode("utf-8")
        # Compare the public total and visible fixture names, ignoring WP-generated nonces.
        summary=re.search(r'([0-9.,]+)\s+sekolah ditemukan',text)
        names=re.findall(r'Sekolah Benchmark DEMO FIKTIF [0-9]+',text)
        assert summary and names
        return {"total":re.sub(r"[^0-9]","",summary.group(1)),"names":names}
try:
    for mode in ("off","on"):
        call("config","set","SDP_SUMMARY_CACHE","true" if mode=="on" else "false","--raw")
        call("eval","delete_transient(SDP_Performance::SUMMARY_KEY);")
        with (out/f"summary-http-{mode}-server.log").open("w") as log:
            server=subprocess.Popen(["php","-d","memory_limit=128M","-S","127.0.0.1:8080","-t",str(root)],stdout=log,stderr=log)
            try:
                for _ in range(30):
                    if server.poll() is not None:
                        raise RuntimeError("Server stopped")
                    try:
                        with urlopen("http://127.0.0.1:8080/wp-includes/css/dashicons.min.css",timeout=3) as response:
                            if response.status==200: break
                    except OSError:
                        time.sleep(1)
                else:
                    raise RuntimeError("Server readiness failed")
                checks={"directory":read_page(url()),"page100":read_page(url(sdp_p=100)),"search":read_page(url(sdp_q="BENC9999"))}
                assert checks["directory"]["total"]=="10002"
                assert checks["page100"]["total"]=="10002"
                assert checks["search"]["total"]=="1"
                # Prime only the unrestricted aggregate before each sample group.
                report[mode]={"checks":checks,"measurements":{}}
                for clients in (1,2):
                    read_page(url())
                    result=subprocess.run(["python3",str(repo/"tools/benchmark_local.py"),"http",url(),"--local-test","--requests","12","--workers",str(clients)],check=True,capture_output=True,text=True,timeout=180)
                    data=extract(result.stdout)
                    assert data["attempts"]==12 and data["ok"]==12 and data["errors"]==0,data
                    report[mode]["measurements"][str(clients)]=data
                    print(f"PASS: cache {mode}, {clients} clients, p95 {data['p95_ms']} ms",flush=True)
                (out/"summary-http-comparison.json").write_text(json.dumps(report,indent=2)+"\n")
            finally:
                server.terminate()
                try: server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
    assert report["off"]["checks"]==report["on"]["checks"],"HTTP visible results differ"
    print("PASS: public results identical with cache off and on")
finally:
    call("config","delete","SDP_SUMMARY_CACHE")
    call("eval","delete_transient(SDP_Performance::SUMMARY_KEY);")
