#!/usr/bin/env bash
# Isolated installation checks of release packages on SQLite and MariaDB.
#
# Usage: test_sqlite_module_installation.sh <packages-dir> <output-dir> <chain>...
# A chain lists modules in installation order, the last one being tested,
# e.g. "Common,AdvancedSearch". Each module needs <packages-dir>/<Module>.zip.
# Each chain gets a fresh installation per engine: install, operations of
# test_sqlite_module_operations.php, uninstall in reverse order.
set -euo pipefail
packages=${1:?Pass the directory containing the module packages}
output=${2:?Pass an output directory}
shift 2
[ "$#" -gt 0 ] || { echo 'Pass at least one module chain' >&2; exit 1; }
image=${OMEKA_AUDIT_IMAGE:-erseco/alpine-omeka-s:develop}
# Optional git archive of the core to test instead of the image's core.
core_tar=${OMEKA_AUDIT_CORE:-}
operations=$(cd "$(dirname "$0")" && pwd)/test_sqlite_module_operations.php
audit_network="omeka-module-audit-$$"
audit_database="$audit_network-db"
mkdir -p "$output"
output=$(cd "$output" && pwd)
packages=$(cd "$packages" && pwd)
core_mount=()
if [ -n "$core_tar" ]; then
    core_tar=$(cd "$(dirname "$core_tar")" && pwd)/$(basename "$core_tar")
    core_mount=(-v "$core_tar:/inputs/core.tar:ro")
    shasum -a 256 "$core_tar" > "$output/core-archive-sha256.txt"
fi
cleanup() {
    docker rm -f "$audit_database" >/dev/null 2>&1 || true
    docker network rm "$audit_network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker image inspect "$image" --format '{{index .RepoDigests 0}} {{.Id}}' > "$output/image.txt"
docker image inspect mariadb:lts --format '{{.Id}}' > "$output/database-image.txt"
(cd "$packages" && shasum -a 256 ./*.zip) > "$output/packages-sha256.txt"
docker network create "$audit_network" >/dev/null
docker run -d --rm --name "$audit_database" --network "$audit_network" \
    --tmpfs /var/lib/mysql -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
    -e MARIADB_ROOT_HOST=% mariadb:lts >/dev/null
ready=false
for attempt in $(seq 1 30); do
    if docker exec "$audit_database" mariadb -h127.0.0.1 -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
if [ "$ready" != true ]; then
    docker logs "$audit_database" > "$output/database-error.log" 2>&1
    exit 1
fi
printf 'driver\tchain\tinstall\tinstalled_active\toperations_failed\tuninstall\tfk_violations\n' > "$output/results.tsv"
for chain in "$@"; do
    target=${chain##*,}
    for driver in sqlite mysql; do
        echo "Checking $chain on $driver"
        log="$output/$driver-$target.log"
        if [ "$driver" = mysql ]; then
            docker exec "$audit_database" mariadb -e "CREATE DATABASE audit_$target CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        fi
        docker run --rm -i --user 0 --entrypoint sh --network "$audit_network" \
            ${core_mount[@]+"${core_mount[@]}"} -v "$packages:/inputs/packages:ro" -v "$operations:/inputs/operations.php:ro" \
            -e AUDIT_DRIVER="$driver" -e AUDIT_CHAIN="$chain" -e AUDIT_TARGET="$target" -e AUDIT_DB_HOST="$audit_database" \
            "$image" -s > "$log" 2>&1 <<'CONTAINER' || true
set -eu
cd /var/www/html
[ -f /inputs/core.tar ] && tar -xf /inputs/core.tar
if [ "$AUDIT_DRIVER" = sqlite ]; then
    printf 'driver = "pdo_sqlite"\npath = "/tmp/audit.sqlite"\n' > config/database.ini
else
    printf 'driver = "pdo_mysql"\nhost = "%s"\nuser = "root"\npassword = ""\ndbname = "audit_%s"\n' "$AUDIT_DB_HOST" "$AUDIT_TARGET" > config/database.ini
fi
omeka-s-cli core:install --admin-email admin@example.com --admin-password audit-test-password --title 'Module audit'
modules=$(echo "$AUDIT_CHAIN" | tr ',' ' ')
for module in $modules; do
    unzip -q "/inputs/packages/$module.zip" -d modules
done
status() {
    php -r '
        require "vendor/autoload.php";
        $c = Doctrine\DBAL\DriverManager::getConnection(parse_ini_file("config/database.ini"));
        $row = $c->fetchAssociative("SELECT is_active FROM module WHERE id = ?", [$argv[1]]);
        echo $row === false ? "absent" : ($row["is_active"] ? "active" : "inactive");
    ' "$1"
}
install=ok
for module in $modules; do
    set +e
    omeka-s-cli module:install "$module" -vv
    exit_code=$?
    set -e
    echo "AUDIT_INSTALL $module exit=$exit_code status=$(status "$module")"
    [ "$exit_code" = 0 ] || install="failed:$module"
done
echo "AUDIT_RESULT install=$install"
echo "AUDIT_RESULT installed_active=$( [ "$(status "$AUDIT_TARGET")" = active ] && echo 1 || echo 0 )"
if [ "$install" = ok ]; then
    php /inputs/operations.php $modules
fi
uninstall=ok
for module in $(echo "$modules" | tr ' ' '\n' | sed '1!G;h;$!d'); do
    set +e
    omeka-s-cli module:uninstall "$module" -vv
    exit_code=$?
    set -e
    echo "AUDIT_UNINSTALL $module exit=$exit_code status=$(status "$module")"
    [ "$exit_code" = 0 ] && [ "$(status "$module")" != active ] || uninstall="failed:$module"
done
echo "AUDIT_RESULT uninstall=$uninstall"
if [ "$AUDIT_DRIVER" = sqlite ]; then
    echo "AUDIT_RESULT fk_violations=$(php -r '$p = new PDO("sqlite:/tmp/audit.sqlite"); echo count($p->query("PRAGMA foreign_key_check")->fetchAll());')"
fi
CONTAINER
        python3 - "$log" <<'NORMALIZE'
from pathlib import Path
import re
import sys
path = Path(sys.argv[1])
text = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', path.read_text(errors='replace'))
path.write_text('\n'.join(line.rstrip() for line in text.splitlines()) + '\n')
NORMALIZE
        result() { sed -n "s/^AUDIT_RESULT $1=//p" "$log" | tail -1; }
        failed=$(grep -c '^AUDIT_OP .* fail ' "$log" || true)
        printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$driver" "$chain" "$(result install)" "$(result installed_active)" \
            "$failed" "$(result uninstall)" "$(result fk_violations)" >> "$output/results.tsv"
    done
done
column -t -s $'\t' "$output/results.tsv"
