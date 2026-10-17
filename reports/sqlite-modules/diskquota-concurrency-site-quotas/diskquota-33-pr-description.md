Stacked on #32: the base is `hotfix/enforce-pending-file-quotas`. Merge #32 first, then rebase/retarget this PR to `main`. This diff contains only the concurrency and site-assignment follow-up.

Two requests could each approve an 800 KiB original against the same 1 MiB quota before either flushed. Assigning an existing item to a full site, including through a batch update, also bypassed the upload-only checks. An item loaded before a concurrent reassignment could retain stale site membership even after waiting for a lock.

Serialize item/media API writes with one per-database lock acquired before hydration and usage reads. Use MySQL/MariaDB `GET_LOCK` and SQLite `flock` beside the canonical database file; lock failures and the 30-second wait timeout reject the write. Release after the outer API operation flushes, keeping nested requests and native batch operations inside the same lock. Shutdown removes pending files before releasing an outstanding lock. No core patch, reservation table or new dependency is required.

Validate existing originals against their final item-site assignments. Combine retained original bytes, old membership deductions, pending site changes and pending uploads. Repeated membership is not charged twice, and removing a site frees usage. A quota-rejected non-continuing batch restores earlier pending assignments; `continueOnError` preserves the items that fit. Read clean cached memberships from the database under the lock and preserve dirty collections containing prepared assignments. Add the Spanish translation for the new validation message.

TDD and validation:

- Before implementation, the real reassignment tests failed and both independent PHP workers could succeed. Restoring #32 reproduces 24 failures in the initial 57-check MariaDB suite. Adding only the lock still allowed an upload through stale cached membership; its unit and real-process regression failed before the membership correction.
- **148 PHPUnit tests / 338 assertions pass; 97.91% line coverage** (750/766), above the 90% gate.
- **59 real checks pass on SQLite and 59 on MariaDB**, including simultaneous user/site uploads, assignment versus upload, two assignments, an item cached before it moves, rejected-file cleanup, replace/append/removal, exact limits, native batch rejection, `continueOnError` and retry.
- The SQLite CI runner executes all five regressions automatically. The concurrency tests start separate PHP processes and pause the first writer before flush; the second must wait, then reject using committed consumption.
- PSR2, PHP translation checks/compilation, `actionlint`, `bash -n`, whitespace checks and **PHPMD 3.0.0 with zero code-size findings** pass. Remove PHPMD's PR target-branch filter so stacked PRs receive the same scan; keep its rules and permissions.
- Integration runtime: Omeka `74a5e131f1881e9d565cf346ff090155b7ee833b`, PHP 8.4.17, DBAL 2.13.9. Local PHPUnit uses PHP 8.5.11; CI uses PHP 8.1. All databases and containers are disposable.

Operational scope: writes are deliberately serialized per database, including URL downloads. MySQL clients must share the same primary server, and SQLite clients must share the same canonical database/lock file. Do not delete an active SQLite lock file. Existing outer database transactions are rejected to avoid stale snapshots. Custom deferred flushes and failures without an API-post event can retain a lock until process shutdown, delaying other writers in long-lived CLI jobs. Existing media owner transfers, mutable ingester file replacements and direct SQL/ORM changes or ingestion outside TempFile remain outside enforcement. Usage continues to count originals, excluding thumbnail sizes.

Database lock semantics: [MariaDB GET_LOCK documentation](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock).
