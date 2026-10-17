#!/usr/bin/env bash
# Both the Omeka checkout and its database must be disposable.
set -euo pipefail
core=$(cd "${1:?Pass a disposable Omeka checkout with Composer dependencies}" && pwd)
cli=$(cd "$(dirname "${2:?Pass the Omeka CLI PHAR}")" && pwd)/$(basename "$2")
tests=$(cd "$(dirname "$0")" && pwd)
scratch=$(mktemp -d)
cleanup() {
    if [ -n "${server:-}" ]; then
        kill "$server" 2>/dev/null || true
        wait "$server" 2>/dev/null || true
    fi
    cat "$scratch/http.log" 2>/dev/null || true
    rm -rf "$scratch"
}
trap cleanup EXIT
cd "$core"
printf 'driver = "pdo_sqlite"\npath = "%s/quota.sqlite"\n' "$scratch" > config/database.ini
mkdir -p files modules
ln -s "$(dirname "$(dirname "$tests")")" modules/DiskQuota
php "$cli" core:install --admin-email admin@example.com --admin-password audit-test-password --title 'DiskQuota integration'
php "$cli" module:install DiskQuota
php -r 'file_put_contents($argv[1], str_repeat("x\n", 524288)); file_put_contents($argv[2], str_repeat("x\n", 512));' "$scratch/full.txt" "$scratch/new.txt"
DISKQUOTA_TEST_ROOT="$core" php -S 127.0.0.1:8765 "$tests/contradictory-item.php" > "$scratch/http.log" 2>&1 &
server=$!
for attempt in {1..50}; do
    if (echo > /dev/tcp/127.0.0.1/8765) 2>/dev/null; then
        break
    fi
    kill -0 "$server"
    sleep 0.1
done
curl --fail-with-body --silent --show-error --max-time 60 \
    -F "file[]=@$scratch/full.txt" -F "file[]=@$scratch/new.txt" -F "file[]=@$scratch/new.txt" \
    http://127.0.0.1:8765/ | tee "$scratch/result.txt"
grep -qx 'AUDIT_RESULT=PASS' "$scratch/result.txt"
