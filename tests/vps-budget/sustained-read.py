"""Run a two-minute mixed read test against the disposable loopback site."""

from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
from benchmark_local import request, summary, target

directory = 'http://127.0.0.1:8080/direktori-sekolah/'
targets = {
    'directory': target(directory),
    'npsn': target(directory + '?sdp_q=TEST0000'),
    'province': target(directory + '?sdp_provinsi=Provinsi%20Fiktif%2000'),
    'partial': target(directory + '?sdp_q=000200'),
    'broad': target(directory + '?sdp_q=Sekolah%20FIKTIF'),
}
samples = {name: [] for name in targets}
started = time.monotonic()
batch = 0
with ThreadPoolExecutor(max_workers=4) as workers:
    while time.monotonic() - started < 120:
        name_search = 'broad' if batch % 5 == 4 else 'partial'
        names = ('directory', 'npsn', 'province', name_search)
        replies = list(workers.map(request, (targets[name] for name in names)))
        for name, result in zip(names, replies):
            samples[name].append(result)
        batch += 1
        time.sleep(0.25)

elapsed = time.monotonic() - started
results = {name: summary(rows, elapsed) for name, rows in samples.items()}
for result in results.values():
    result['failures'] = [sample for sample in result.pop('samples') if sample['error']]
print(json.dumps({'duration_s': round(elapsed, 3), 'batches': batch, 'mixed': results}, indent=2))
if not all(result['ok'] and not result['errors'] for result in results.values()):
    raise SystemExit(1)
