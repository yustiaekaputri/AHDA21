#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p artifacts/reports
wpcs=0
phpstan=0
vendor/bin/phpcs --standard=WordPress --extensions=php --report=summary plugin theme > artifacts/reports/wpcs.txt 2>&1 || wpcs=$?
vendor/bin/phpstan analyse --memory-limit=512M --no-progress --error-format=table > artifacts/reports/phpstan.txt 2>&1 || phpstan=$?
printf 'WPCS=%d PHPStan=%d\n' "$wpcs" "$phpstan"
if (( wpcs || phpstan )); then
    exit 1
fi
