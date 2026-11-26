#!/usr/bin/env python3
"""Build commit-pinned WordPress candidate packages from a local AHDA21 git clone."""
import argparse
import hashlib
import json
import re
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path, PurePosixPath

def git(repo, *args):
    return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.PIPE)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def build(repo, ref, output):
    if not re.fullmatch(r'[0-9a-fA-F]{40}', ref):
        raise ValueError('Use an exact 40-character commit SHA, not a moving branch.')
    commit = git(repo, 'rev-parse', '--verify', ref+'^{commit}').decode().strip()
    if commit.lower() != ref.lower():
        raise ValueError('Commit mismatch')
    if output.exists():
        raise ValueError('Output directory exists; use a new directory.')
    output.parent.mkdir(parents=True, exist_ok=True)
    specs = [('plugin', 'sekolah-directory-pro', 'sekolah-directory-pro.php'),
             ('theme', 'sekolah-directory-theme', 'style.css')]
    with tempfile.TemporaryDirectory(dir=output.parent) as tmp:
        stage = Path(tmp) / 'candidate'
        stage.mkdir()
        manifest = {'commit': commit, 'status': 'development_candidate_not_release',
                    'note': 'Original version headers preserved. Requires staging and acceptance tests before production.', 'packages': []}
        for source, slug, main in specs:
            records = git(repo, 'ls-tree', '-rz', commit, '--', source+'/').split(b'\0')
            members = {}
            for raw in filter(None, records):
                meta, path_bytes = raw.split(b'\t', 1)
                mode, kind, oid = meta.decode().split()
                path = PurePosixPath(path_bytes.decode('utf-8'))
                if kind != 'blob' or mode not in {'100644','100755'} or path.parts[0] != source or len(path.parts)<2:
                    raise ValueError(f'Unsupported tree entry: {path}')
                rel = PurePosixPath(*path.parts[1:])
                if any(x in {'.','..'} or x.startswith('.') for x in rel.parts) or '\\' in str(rel):
                    raise ValueError(f'Unsafe archive path: {rel}')
                if rel.suffix.lower() not in {'.php','.css','.js','.txt','.csv','.json','.md','.png','.jpg','.jpeg','.svg','.webp','.gif','.ico','.woff','.woff2'}:
                    raise ValueError(f'Unreviewed archive file type: {rel}')
                blob = git(repo, 'cat-file', 'blob', oid)
                if len(blob) > 4*1024*1024:
                    raise ValueError(f'Unreviewed large file: {rel}')
                members[str(rel)] = blob
            if main not in members:
                raise ValueError(f'Missing entrypoint {source}/{main}')
            head = members[main][:8192].decode('utf-8')
            match = re.search(r'(?mi)^\s*(?:\*\s*)?Version:\s*([0-9A-Za-z.\-]+)\s*$', head)
            if not match:
                raise ValueError(f'Missing version header: {main}')
            version = match.group(1)
            if source == 'plugin':
                constant = re.search(r"const\s+VERSION\s*=\s*['\"]([^'\"]+)['\"]", head)
                if not constant or constant.group(1) != version:
                    raise ValueError('Plugin version header and constant mismatch')
            name = f'{slug}-candidate-{version}-{commit[:12]}.zip'
            dest = stage/name
            with zipfile.ZipFile(dest, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
                for rel, blob in sorted(members.items()):
                    entry = zipfile.ZipInfo(f'{slug}/{rel}', (1980,1,1,0,0,0))
                    entry.compress_type = zipfile.ZIP_DEFLATED
                    entry.create_system = 3
                    entry.external_attr = (0o100644 << 16)
                    z.writestr(entry, blob, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
            with zipfile.ZipFile(dest) as z:
                if z.testzip() is not None or set(z.namelist()) != {f'{slug}/{p}' for p in members}:
                    raise ValueError('ZIP integrity check failed')
                for rel, blob in members.items():
                    if z.read(f'{slug}/{rel}') != blob:
                        raise ValueError(f'ZIP content mismatch: {rel}')
            manifest['packages'].append({'file':name,'version_header':version,'sha256':digest(dest.read_bytes()),'bytes':dest.stat().st_size,'files':len(members)})
        (stage/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n', encoding='utf-8')
        stage.rename(output)
    return manifest

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--repo',type=Path,required=True)
    p.add_argument('--commit',required=True)
    p.add_argument('--output',type=Path,required=True)
    a=p.parse_args()
    try:
        print(json.dumps(build(a.repo.resolve(),a.commit,a.output.resolve()),indent=2))
    except (ValueError,OSError,subprocess.CalledProcessError,UnicodeError) as e:
        print('Build refused: '+str(e),file=sys.stderr)
        return 2
    return 0

if __name__=='__main__':
    sys.exit(main())
