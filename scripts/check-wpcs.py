#!/usr/bin/env python3
"""Fail when PHPCS reports findings outside the checked-in baseline."""
import argparse
from collections import Counter
from hashlib import sha256
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def findings(report):
    counts = Counter()
    for path, file_report in report['files'].items():
        file_path = Path(path)
        relative_path = file_path.resolve().relative_to(ROOT)
        lines = file_path.read_text(encoding='utf-8').splitlines()
        for message in file_report['messages']:
            line = message['line']
            source = message['source']
            fingerprint = json.dumps([
                str(relative_path),
                source,
                message['type'],
                message['message'],
                lines[line - 1].strip() if 0 < line <= len(lines) else '',
            ], ensure_ascii=False)
            key = sha256(fingerprint.encode()).hexdigest()
            counts[key] += 1
            yield key, counts[key], f'{relative_path}:{line} {source}'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('report', type=Path)
    args = parser.parse_args()
    baseline = json.loads((ROOT / 'docs/wpcs-baseline.json').read_text())
    report = json.loads(args.report.read_text())
    current = list(findings(report))
    totals = report['totals']
    if len(current) != totals['errors'] + totals['warnings']:
        raise ValueError('The PHPCS report totals do not match its findings.')
    new = [location for key, count, location in current if count > baseline.get(key, 0)]
    for location in new:
        print(location)
    print(f'WPCS: {len(current)} total findings, {len(new)} outside the baseline.')
    return int(bool(new))


if __name__ == '__main__':
    raise SystemExit(main())
