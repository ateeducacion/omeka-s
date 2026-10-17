Run this regression only in a disposable Omeka installation with DiskQuota active
and an administrator `admin@example.com`. It creates sites, items and uploaded
media and requires the real core API, database and HTTP upload handling.

Inside the isolated container, with this repository mounted at `/module`:

```sh
cd /var/www/html
php -r 'file_put_contents("/tmp/full.txt", str_repeat("x", 1048576)); file_put_contents("/tmp/new.txt", str_repeat("x", 1024));'
DISKQUOTA_TEST_ROOT=/var/www/html php -S 127.0.0.1:8765 /module/test/integration/contradictory-item.php
```

In another shell in that container:

```sh
curl --fail-with-body -F 'file[]=@/tmp/full.txt' -F 'file[]=@/tmp/new.txt' -F 'file[]=@/tmp/new.txt' http://127.0.0.1:8765/
```

Run once per clean SQLite/MariaDB database. Expect four `PASS` lines and
`AUDIT_RESULT=PASS`. The original PR commit `72de488` fails the first case. The
last case guards against querying stale site assignments when the explicit
`o:item` matches the parent currently being hydrated.
