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
    if [ -n "${downloads:-}" ]; then
        kill "$downloads" 2>/dev/null || true
        wait "$downloads" 2>/dev/null || true
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
php -r 'foreach (["full" => 524288, "new" => 512, "small" => 204800, "large" => 409600, "over" => 1048576] as $name => $lines) { file_put_contents($argv[1] . "/$name.txt", str_repeat("x\n", $lines)); }' "$scratch"
php -r 'file_put_contents($argv[1], base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGP4z8AARAwQCgAf7gP9i18U1AAAAABJRU5ErkJggg=="));' "$scratch/tiny.png"
php -S 127.0.0.1:8766 -t "$scratch" > "$scratch/downloads.log" 2>&1 &
downloads=$!
DISKQUOTA_TEST_ROOT="$core" php -d post_max_size=32M -d upload_max_filesize=8M \
    -S 127.0.0.1:8765 "$tests/router.php" > "$scratch/http.log" 2>&1 &
server=$!
for attempt in {1..50}; do
    if (echo > /dev/tcp/127.0.0.1/8765) 2>/dev/null; then
        break
    fi
    kill -0 "$server"
    sleep 0.1
done
status=0
run_test() {
    local route=$1
    shift
    if ! curl --fail-with-body --silent --show-error --max-time 60 "$@" \
        "http://127.0.0.1:8765/$route" | tee "$scratch/result.txt"; then
        status=1
    fi
    if ! grep -qx 'AUDIT_RESULT=PASS' "$scratch/result.txt"; then
        status=1
    fi
}
run_test contradictory-item \
    -F "file[]=@$scratch/full.txt" -F "file[]=@$scratch/new.txt" -F "file[]=@$scratch/new.txt" \
    -F "file[]=@$scratch/new.txt"
run_test pending-uploads \
    -F "file[]=@$scratch/small.txt" -F "file[]=@$scratch/large.txt" -F "file[]=@$scratch/large.txt" \
    -F "file[]=@$scratch/full.txt" -F "file[]=@$scratch/over.txt" -F "file[]=@$scratch/over.txt" \
    -F "file[]=@$scratch/small.txt" -F "file[]=@$scratch/small.txt" -F "file[]=@$scratch/full.txt" \
    -F "file[]=@$scratch/small.txt" -F "file[]=@$scratch/small.txt" \
    -F "file[]=@$scratch/small.txt" -F "file[]=@$scratch/small.txt" -F "file[]=@$scratch/small.txt"
run_test url-ingestion
exit "$status"
