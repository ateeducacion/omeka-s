#!/usr/bin/env bash
set -euo pipefail
network="diskquota-pending-$$"
database="$network-db"
cleanup() {
    docker rm -f "$network-before" "$network-after" "$database" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$network" >/dev/null
docker run -d --rm --name "$database" --network "$network" --tmpfs /var/lib/mysql \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_ROOT_HOST=% mariadb:lts >/dev/null
for attempt in {1..30}; do
    if docker exec "$database" mariadb -h127.0.0.1 -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec "$database" mariadb -e 'CREATE DATABASE quota_before; CREATE DATABASE quota_after;'
for state in before after; do
    overlays=(-e AUDIT_TEST_VARIANT="$state")
    if [ "$state" = before ]; then
        overlays=(-v /tmp/diskquota-pending-baseline-module.php:/module/Module.php:ro
            -v /tmp/diskquota-pending-baseline-listener.php:/module/src/Listener/UploadQuotaListener.php:ro
            -v /tmp/diskquota-pending-baseline-resolver.php:/module/src/Service/UploadSizeResolver.php:ro)
    fi
    result=0
    docker run --rm --user 0 --entrypoint sh --name "$network-$state" --network "$network" \
        -v /tmp/omeka-sqlite-metadata-core.tar:/inputs/core.tar:ro -v /tmp/omeka-diskquota-pr:/module:ro \
        -v /tmp/diskquota-pending-mariadb-runner.sh:/inputs/run.sh:ro "${overlays[@]}" \
        -e AUDIT_DB_HOST="$database" -e AUDIT_DB_NAME="quota_$state" alpine-omeka-s:sqlite-test \
        -c 'apk add --no-cache bash && cd /var/www/html && tar -xf /inputs/core.tar && bash /inputs/run.sh /var/www/html /usr/local/bin/omeka-s-cli' \
        > "/tmp/diskquota-pending-mariadb-$state.log" 2>&1 || result=$?
    printf '%s: exit %s\n' "$state" "$result"
    if [ "$state" = after ]; then
        [ "$result" = 0 ]
    else
        [ "$result" != 0 ]
    fi
done
