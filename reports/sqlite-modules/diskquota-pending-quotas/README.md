Run this regression only in a disposable Omeka installation with DiskQuota active
and an administrator `admin@example.com`. It creates sites, items and uploaded
media and requires the real core API, database and HTTP upload handling.

CI runs it automatically on PHP 8.1 and SQLite in the `sqlite_integration` job
of `.github/workflows/ci.yml`. The job checks out the SQLite-capable Omeka fork
at `74a5e131f1881e9d565cf346ff090155b7ee833b`, installs its Composer dependencies
and uses Omeka CLI 0.18.0, verified against its published SHA256, to install Omeka
and activate this module. No production data or GitHub secrets are used.
The disposable instance accepts `image/png`, `text/plain` and `application/octet-stream`
for the generated `.txt` fixture, whose detected MIME type varies by libmagic
version; Omeka's file validation remains enabled.

To run the same job locally, prepare a clean checkout of that core revision
with `composer install --no-dev`, download the verified CLI PHAR, then run:

```sh
bash test/integration/run-sqlite.sh /path/to/disposable/omeka /path/to/omeka-s-cli.phar
```

PHP needs Omeka's required extensions, including `pdo_sqlite` and `fileinfo`;
ImageMagick's `convert` command must also be available, as in the CI job.

The runner executes three HTTP regressions, printing their checks and failing on
any HTTP error or missing `AUDIT_RESULT=PASS`. Both PHP servers and the temporary database/uploads are removed
on exit. Installed Omeka data remains in the disposable checkout.

- `contradictory-item.php`: explicit item overrides and pending site assignments.
- `pending-uploads.php`: the selected multipart file, aggregate user/site quotas,
  failed item creation/update cleanup, retry, exact limits and final media ownership.
- `url-ingestion.php`: real local HTTP downloads without multipart data, unknown
  and forged sizes, user/site limits, unlimited quotas, `store_original=false`
  and removal of real thumbnails after rejecting a batch.

The second server serves generated download fixtures on loopback port 8766;
the application test server listens on 8765. The runner collects failures across
all scripts. Use it once against a clean disposable installation.

Quota enforcement runs on `media.ingest_file.pre`, after Omeka has the real
temporary file and has resolved its owner/item, and before permanent storage.
Pending originals are counted separately from persisted media. Failed hydration
removes only new files using Omeka's original/thumbnail deletion routine;
shutdown cleanup also removes tracked files that were never persisted, including
thumbnail-only media that do not consume the original-file quota.
Usage query errors propagate rather than being treated as zero usage.

Scope: request-local pending files and ingesters using Omeka's TempFile pipeline.
Parallel PHP requests, mutable ingester updates and reassigning existing media
to another owner/site still need separate accounting. Quotas count originals,
matching the module's existing usage queries; temporary files and thumbnails
are not included.

For manual testing against an already installed disposable instance:

Inside the isolated container, with this repository mounted at `/module`:

```sh
cd /var/www/html
php -r 'file_put_contents("/tmp/full.txt", str_repeat("x\n", 524288)); file_put_contents("/tmp/new.txt", str_repeat("x\n", 512));'
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
