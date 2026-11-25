#!/bin/sh
# Diagnostik lokal read-only; tidak menginstal atau mengubah server.
set -eu
printf '\n== RAM / swap ==\n'; free -m
printf '\n== CPU / load ==\n'; nproc; uptime
printf '\n== Disk ==\n'; df -h / /var
printf '\n== Proses terbesar (RSS KiB) ==\n'; ps -eo pid,comm,rss,%cpu --sort=-rss | head -n 12
printf '\n== Versi ==\n'; for cmd in nginx php mariadb wp; do if command -v "$cmd" >/dev/null 2>&1; then "$cmd" --version 2>&1 | head -n 2 || :; fi; done
printf '\nTidak mengukur kuota bandwidth hosting. Jangan bagikan laporan tanpa menyaring informasi sensitif.\n'
