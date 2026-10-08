#!/usr/bin/env bash
# Isolated installation checks using the already audited release packages.
set -euo pipefail
core_tar=${1:?Pass a git archive of the core}
packages=${2:?Pass the directory containing Collecting.zip, CSVImport.zip and CodeSnippets.zip}
output=${3:?Pass an output directory}
image=${OMEKA_AUDIT_IMAGE:-alpine-omeka-s:sqlite-test}
audit_network="omeka-module-audit-$$"
audit_database="$audit_network-db"
mkdir -p "$output"
output=$(cd "$output" && pwd)
packages=$(cd "$packages" && pwd)
core_tar=$(cd "$(dirname "$core_tar")" && pwd)/$(basename "$core_tar")
cleanup() {
    docker rm -f "$audit_database" >/dev/null 2>&1 || true
    docker network rm "$audit_network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker image inspect "$image" --format '{{.Id}}' > "$output/image.txt"
docker image inspect mariadb:lts --format '{{.Id}}' > "$output/database-image.txt"
shasum -a 256 "$core_tar" > "$output/core-archive-sha256.txt"
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
printf 'driver\tmodule\tcli_exit\tinstalled_active\n' > "$output/results.tsv"
for driver in sqlite mysql; do
    for module in Collecting CSVImport CodeSnippets; do
        echo "Checking $module on $driver"
        if [ "$driver" = mysql ]; then
            docker exec "$audit_database" mariadb -e "CREATE DATABASE audit_$module CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        fi
        docker run --rm -i --user 0 --entrypoint sh --network "$audit_network" \
            -v "$core_tar:/inputs/core.tar:ro" -v "$packages:/inputs/packages:ro" \
            -e AUDIT_DRIVER="$driver" -e AUDIT_MODULE="$module" -e AUDIT_DB_HOST="$audit_database" \
            "$image" -s > "$output/$driver-$module.log" 2>&1 <<'CONTAINER'
set -eu
cd /var/www/html
tar -xf /inputs/core.tar
if [ "$AUDIT_DRIVER" = sqlite ]; then
    printf 'driver = "pdo_sqlite"\npath = "/tmp/audit.sqlite"\n' > config/database.ini
else
    printf 'driver = "pdo_mysql"\nhost = "%s"\nuser = "root"\npassword = ""\ndbname = "audit_%s"\n' "$AUDIT_DB_HOST" "$AUDIT_MODULE" > config/database.ini
fi
omeka-s-cli core:install --admin-email admin@example.com --admin-password audit-test-password --title 'Module audit'
unzip -q "/inputs/packages/$AUDIT_MODULE.zip" -d modules
set +e
omeka-s-cli module:install "$AUDIT_MODULE" -vv
install_exit=$?
set -e
echo "AUDIT_CLI_EXIT=$install_exit"
omeka-s-cli module:list
php <<'PHP'
<?php
require 'vendor/autoload.php';
$params = parse_ini_file('config/database.ini');
$connection = Doctrine\DBAL\DriverManager::getConnection($params);
$module = $connection->fetchAssociative('SELECT id, is_active, version FROM module WHERE id = ?', [getenv('AUDIT_MODULE')]);
echo 'AUDIT_INSTALLED_ACTIVE=' . (int) ($module && $module['is_active']) . PHP_EOL;
echo 'AUDIT_DATABASE_RECORD=' . json_encode($module) . PHP_EOL;
PHP
CONTAINER
        python3 - "$output/$driver-$module.log" <<'NORMALIZE'
from pathlib import Path
import re
import sys
path = Path(sys.argv[1])
text = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', path.read_text())
path.write_text('\n'.join(line.rstrip() for line in text.splitlines()) + '\n')
NORMALIZE
        cli_exit=$(sed -n 's/^AUDIT_CLI_EXIT=//p' "$output/$driver-$module.log")
        active=$(sed -n 's/^AUDIT_INSTALLED_ACTIVE=//p' "$output/$driver-$module.log")
        [ -n "$cli_exit" ] && [ -n "$active" ]
        printf '%s\t%s\t%s\t%s\n' "$driver" "$module" "$cli_exit" "$active" >> "$output/results.tsv"
    done
done
# Successful MySQL controls and a positive SQLite control validate the preparation.
awk -F '\t' 'NR > 1 && ($1 == "mysql" || $2 == "CodeSnippets") && ($3 != 0 || $4 != 1) { bad = 1 } END { exit bad }' "$output/results.tsv"
cat "$output/results.tsv"
