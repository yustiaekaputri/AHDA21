"""Run a mixed read test against the disposable loopback site."""

from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import subprocess
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
from benchmark_local import request, summary, target

duration = int(os.environ.get('SDP_BUDGET_DURATION', '360'))
if duration not in (120, 360, 720):
    raise SystemExit('Durasi uji harus 120, 360, atau 720 detik.')


def checkpoint(elapsed, batch):
    expiry = subprocess.check_output([
        'mariadb', '--protocol=socket', '--socket=/run/mysqld/mysqld.sock', '--user=root',
        '--batch', '--skip-column-names', 'sdp_development', '--execute',
        "SELECT option_value FROM wp_options WHERE option_name='_transient_timeout_sdp88_facet_0'",
    ], text=True, timeout=5).strip()
    return {
        'seconds': round(elapsed, 3),
        'batches': batch,
        'summary_expiration': int(expiry) if expiry else None,
        'memory_bytes': int(Path('/sys/fs/cgroup/memory.current').read_text()),
    }


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
next_checkpoint = 0
checkpoints = []
with ThreadPoolExecutor(max_workers=4) as workers:
    while time.monotonic() - started < duration:
        name_search = 'broad' if batch % 5 == 4 else 'partial'
        names = ('directory', 'npsn', 'province', name_search)
        replies = list(workers.map(request, (targets[name] for name in names)))
        for name, result in zip(names, replies):
            if result['error']:
                result['batch'] = batch + 1
            samples[name].append(result)
        batch += 1
        elapsed = time.monotonic() - started
        if elapsed >= next_checkpoint:
            item = checkpoint(elapsed, batch)
            checkpoints.append(item)
            print(f'Beban campuran: {elapsed:.0f} detik, {4 * batch} permintaan.', file=sys.stderr, flush=True)
            next_checkpoint = (int(elapsed) // 60 + 1) * 60
        time.sleep(0.25)

elapsed = time.monotonic() - started
results = {name: summary(rows, elapsed) for name, rows in samples.items()}
for result in results.values():
    result['failures'] = [sample for sample in result.pop('samples') if sample['error']]
checkpoints.append(checkpoint(elapsed, batch))
expiration_times = {item['summary_expiration'] for item in checkpoints if item['summary_expiration'] is not None}
print(json.dumps({'duration_s': round(elapsed, 3), 'batches': batch, 'mixed': results, 'checkpoints': checkpoints}, indent=2))
if not all(result['ok'] and not result['errors'] for result in results.values()):
    raise SystemExit(1)
expected_refreshes = {120: 0, 360: 1, 720: 2}[duration]
if expected_refreshes and (len(expiration_times) < expected_refreshes + 1
                           or max(expiration_times) - min(expiration_times) < 300 * expected_refreshes):
    raise SystemExit('Jumlah pembaruan ringkasan setelah TTL belum teramati.')
