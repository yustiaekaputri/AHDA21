#!/usr/bin/env bash
# Diagnostic only: no login credentials sent, no modification to WordPress.
set -euo pipefail
: "${SDP_WP_ROOT:?Missing WordPress root}"
: "${SDP_PREVIEW_URL:?Missing preview URL}"
[[ "$SDP_PREVIEW_URL" =~ ^http://127\.0\.0\.1:[0-9]+$ ]] || exit 1
stage="${1:?Pass core, active, or seeded}"
[[ "$stage" =~ ^(core|active|seeded)$ ]] || exit 1
for file in wp-login.php wp-admin/admin.php index.php wp-load.php; do
  test -r "$SDP_WP_ROOT/$file" || { echo "Missing core file: $file ($stage)"; exit 1; }
done
log="$(mktemp)"
body="$(mktemp)"
server_pid=''
cleanup() {
  if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  rm -f "$log" "$body"
}
trap cleanup EXIT
port="${SDP_PREVIEW_URL##*:}"
php -S "127.0.0.1:$port" -t "$SDP_WP_ROOT" >"$log" 2>&1 &
server_pid=$!
for i in {1..30}; do
  kill -0 "$server_pid" 2>/dev/null || { echo "Probe server could not start ($stage)"; cat "$log"; exit 1; }
  if curl --max-time 3 -sS "$SDP_PREVIEW_URL/" >/dev/null 2>&1; then break; fi
  sleep 1
done
echo "LOGIN PROBE ($stage)"
for suffix in '/wp-login.php' '/wp-login.php?redirect_to=%2Fwp-admin%2F' '/wp-admin/'; do
  result="$(curl --max-time 15 -sS -L --max-redirs 5 -o "$body" -w '%{http_code} %{url_effective}' "$SDP_PREVIEW_URL$suffix")"
  echo "$suffix -> $result"
  if [[ "$result" != 200\ * ]] || ! grep -Eq 'name="log"|id="user_login"' "$body"; then
    echo "LOGIN PROBE FAILED ($stage): expected real WordPress login form"
    # Server log contains only these anonymous diagnostic requests.
    tail -n 30 "$log"
    exit 1
  fi
done
echo "PASS: WordPress login probe ($stage)"
