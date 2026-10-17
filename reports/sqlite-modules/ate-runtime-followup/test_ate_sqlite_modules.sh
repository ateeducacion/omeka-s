#!/usr/bin/env bash
# Test published ATE modules in disposable databases, with no exposed ports.
set -euo pipefail
core_tar=${1:?Pass the core git archive}
packages=${2:?Pass a directory containing module release ZIPs}
output=${3:?Pass an output directory}
image=${OMEKA_AUDIT_IMAGE:-alpine-omeka-s:sqlite-test}
network="omeka-ate-audit-$$"
database="$network-db"
app="$network-app"
mkdir -p "$output"
output=$(cd "$output" && pwd)
packages=$(cd "$packages" && pwd)
core_tar=$(cd "$(dirname "$core_tar")" && pwd)/$(basename "$core_tar")
tests=$(cd "$(dirname "$0")" && pwd)
cleanup() {
    docker rm -f "$app" "$database" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker image inspect "$image" --format '{{.Id}}' > "$output/image.txt"
docker image inspect mariadb:lts --format '{{.Id}}' > "$output/database-image.txt"
shasum -a 256 "$core_tar" > "$output/core-archive-sha256.txt"
for module in DiskQuota ExeLearning Common Log; do
    shasum -a 256 "$packages/$module.zip"
done > "$output/packages-sha256.txt"
docker network create "$network" >/dev/null
docker run -d --rm --name "$database" --network "$network" --tmpfs /var/lib/mysql \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_ROOT_HOST=% mariadb:lts >/dev/null
ready=false
for attempt in $(seq 1 30); do
    if docker exec "$database" mariadb -h127.0.0.1 -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        ready=true
        break
    fi
    sleep 1
done
[ "$ready" = true ]
printf 'scenario\tmodule\tcontainer_exit\tfunctional_result\n' > "$output/results.tsv"
for scenario in sqlite mysql; do
    driver=$scenario
    modules='DiskQuota ExeLearning'
    inline_log=''
    if [ "$scenario" = sqlite-inline-log ]; then
        driver=sqlite
        modules=IsolatedSites
        inline_log=1
    fi
    for module in $modules; do
        echo "Testing $module on $scenario"
        if [ "$driver" = mysql ]; then
            docker exec "$database" mariadb -e "CREATE DATABASE audit_$module CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        fi
        status=0
        docker run --rm -i --name "$app" --user 0 --entrypoint sh --network "$network" \
            -v "$core_tar:/inputs/core.tar:ro" -v "$packages:/inputs/packages:ro" \
            -v "$tests/test_ate_sqlite_modules.php:/inputs/test.php:ro" \
            -e AUDIT_DRIVER="$driver" -e AUDIT_MODULE="$module" -e AUDIT_DB_HOST="$database" \
            -e AUDIT_INLINE_LOG_FKS="$inline_log" \
            "$image" -s > "$output/$scenario-$module.log" 2>&1 <<'CONTAINER' || status=$?
set -eu
cd /var/www/html
tar -xf /inputs/core.tar
if [ "$AUDIT_DRIVER" = sqlite ]; then
    printf 'driver = "pdo_sqlite"\npath = "/tmp/audit.sqlite"\n' > config/database.ini
else
    printf 'driver = "pdo_mysql"\nhost = "%s"\nuser = "root"\npassword = ""\ndbname = "audit_%s"\n' "$AUDIT_DB_HOST" "$AUDIT_MODULE" > config/database.ini
fi
omeka-s-cli core:install --admin-email admin@example.com --admin-password audit-test-password --title 'ATE module audit'
if [ "$AUDIT_MODULE" = IsolatedSites ]; then
    for dependency in Common Log; do
        unzip -q "/inputs/packages/$dependency.zip" -d modules
        echo "AUDIT_DEPENDENCY=$dependency"
        if [ "$dependency" = Log ] && [ -n "$AUDIT_INLINE_LOG_FKS" ]; then
            # Test-only fixture: separate IsolatedSites SQL from Log's install blocker.
            php <<'PHP'
<?php
$path = 'modules/Log/data/install/schema.sql';
$sql = file_get_contents($path);
$owner = 'CONSTRAINT FK_8F3F68C57E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL';
$job = 'CONSTRAINT FK_8F3F68C5BE04EA9 FOREIGN KEY (`job_id`) REFERENCES `job` (`id`) ON DELETE CASCADE';
foreach ([$owner, $job] as $constraint) {
    $alter = 'ALTER TABLE `log` ADD ' . $constraint . ';';
    if (substr_count($sql, $alter) !== 1) { throw new RuntimeException('Log schema fixture no longer matches'); }
    $sql = str_replace($alter, '', $sql);
}
$sql = str_replace('PRIMARY KEY(`id`)', "PRIMARY KEY(`id`),\n    $owner,\n    $job", $sql);
file_put_contents($path, $sql);
echo "AUDIT_TEST_ONLY_INLINE_LOG_FOREIGN_KEYS=1\n";
PHP
        fi
        omeka-s-cli module:install "$dependency" -vv
    done
fi
unzip -q "/inputs/packages/$AUDIT_MODULE.zip" -d modules
omeka-s-cli module:install "$AUDIT_MODULE" -vv
php <<'PHP'
<?php
require 'vendor/autoload.php';
$connection = Doctrine\DBAL\DriverManager::getConnection(parse_ini_file('config/database.ini'));
$active = $connection->fetchOne('SELECT is_active FROM module WHERE id = ?', [getenv('AUDIT_MODULE')]);
if (!$active) { throw new RuntimeException('Published module did not install'); }
file_put_contents('/tmp/quota.txt', str_repeat('x', 1024));
if (getenv('AUDIT_MODULE') === 'ExeLearning') {
    if (!copy('modules/ExeLearning/data/fixtures/really-simple-test-project.elpx', '/tmp/course.elpx')) {
        throw new RuntimeException('Published eXeLearning fixture is missing');
    }
}
PHP
php -S 127.0.0.1:8765 /inputs/test.php > /tmp/http.log 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true; cat /tmp/http.log' EXIT
upload=/tmp/quota.txt
[ "$AUDIT_MODULE" != ExeLearning ] || upload=/tmp/course.elpx
sleep 1
curl -sS -F "file[]=@$upload" -F "file[]=@$upload" -F "file[]=@$upload" -F "file[]=@$upload" http://127.0.0.1:8765/ > /tmp/result.txt
cat /tmp/result.txt
grep -q '^AUDIT_RESULT=PASS' /tmp/result.txt
CONTAINER
        python3 - "$output/$scenario-$module.log" <<'NORMALIZE'
from pathlib import Path
import re
import sys
path = Path(sys.argv[1])
value = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', path.read_text())
path.write_text('\n'.join(line.rstrip() for line in value.splitlines()) + '\n')
NORMALIZE
        result=$(sed -n 's/^AUDIT_RESULT=//p' "$output/$scenario-$module.log")
        printf '%s\t%s\t%s\t%s\n' "$scenario" "$module" "$status" "${result:-BLOCKED}" >> "$output/results.tsv"
    done
done
cat "$output/results.tsv"
awk -F '\t' 'NR > 1 && ($3 != 0 || $4 != "PASS") { bad = 1 } END { exit bad }' "$output/results.tsv"
