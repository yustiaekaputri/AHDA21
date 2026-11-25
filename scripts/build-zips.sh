#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p artifacts/packages
cp -R plugin "$tmp/sekolah-directory-pro"
cp -R theme "$tmp/sekolah-directory-theme"
cp -R tools "$tmp/sekolah-directory-pro/tools"
cp docs/plugin/*.txt "$tmp/sekolah-directory-pro/"
cp docs/theme/*.txt "$tmp/sekolah-directory-theme/"
python3 - "$tmp" <<'PY'
from pathlib import Path
from sys import argv
from zipfile import ZipFile, ZIP_DEFLATED

staged = Path(argv[1])
for directory in sorted(staged.iterdir()):
    with ZipFile(Path("artifacts/packages") / f"{directory.name}.zip", "w", ZIP_DEFLATED) as package:
        for path in sorted(directory.rglob("*")):
            if path.is_file() and "__pycache__" not in path.parts and path.suffix not in {".pyc", ".pyo"}:
                package.write(path, path.relative_to(staged))
PY
