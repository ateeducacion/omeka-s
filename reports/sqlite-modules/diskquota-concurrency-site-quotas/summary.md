# DiskQuota concurrency and site reassignment follow-up

PR: https://github.com/ateeducacion/omeka-s-DiskQuota/pull/33
Base PR: https://github.com/ateeducacion/omeka-s-DiskQuota/pull/32
Branch: `hotfix/serialize-uploads-and-site-assignments`
Commit: `4214e559a4954f771b0a7d355da07699709ac27e`.

This PR is stacked on `hotfix/enforce-pending-file-quotas` (`bf8990f`). Merge #32 first, then rebase and retarget #33 to main. Neither PR has been merged by this task.

## Reproductions and fixes

Real reassignment and multi-process tests failed before implementation. Restoring #32 in MariaDB produces 24 failures in the initial 57-check suite. Locking alone still allowed an upload through cached site membership: its unit regression checked the old site, and the real move-versus-upload race allowed both writes and exceeded the new site's quota. These failures were reproduced before correcting membership reads.

Item/media writes acquire a per-database lock before hydration and usage reads. MySQL/MariaDB use GET_LOCK with a database-specific name; SQLite uses flock beside the canonical database file. The 30-second timeout/failure rejects writes. Native nested/batch requests cannot release the outer lock before flush. Shutdown cleans unpersisted files before releasing an outstanding lock.

Existing originals are charged to their final item-site memberships, with deductions for old assignments and retained media, and with pending site changes/uploads included. Quota-rejected non-continuing batches refresh previously prepared changes; continueOnError preserves the items that fit. Clean cached memberships are read from current database state, while dirty collections preserve the current operation's assignments. Clean existing items are refreshed before hydration.

## Validation

- PHPUnit: 148 tests, 338 assertions; 97.91% line coverage (750/766).
- SQLite: 59/59 real checks; all five scripts AUDIT_RESULT=PASS.
- MariaDB: 59/59 real checks; all five scripts AUDIT_RESULT=PASS.
- Includes five independent two-process races: user uploads, site uploads, assignment/upload, assignment/assignment and an upload with a preloaded item moved by another process. Worker rejection is required to contain an actual quota validation error. Original-file and media-record counts verify cleanup.
- Unit tests cover locks, nested/root API lifetime, deferred flush/shutdown, retries, pending collections, retained-original projections, batch rollback/continueOnError and failed SQL lookups.
- PSR2, translation checks and compilation, actionlint, shell syntax and whitespace checks pass. PHPMD 3.0.0 has zero code-size findings; no rules suppressed. Its PR base-branch filter is removed so stacked PRs are scanned with the existing permissions.
- Integration: Omeka 74a5e131f1881e9d565cf346ff090155b7ee833b, PHP 8.4.17, DBAL 2.13.9. Unit tests PHP 8.5.11; CI PHP 8.1.
- All native tests use disposable data/containers. No production, Playground or core files were changed.

## Operational limits

Writes to one database are serialized, including URL downloads. MySQL clients must use the same primary server; SQLite clients must share the canonical database and lock file. Do not delete a lock file with active writers. External database transactions are rejected to avoid stale snapshots. Custom deferred flushes/errors without API-post can hold a lock until process shutdown and delay long-lived CLI writers. Owner transfers, mutable file replacements, direct SQL/ORM writes and custom ingestion outside TempFile are not covered. Metrics count originals, not thumbnail sizes.

## GitHub validation

All eight reported checks pass for the final commit. CI independently confirms 148 tests / 338 assertions / 97.91% coverage and all 59 real SQLite checks. PHPMD completes with no code-size findings; GitHub has no open scanning alerts or review comments for PR #33 at verification time.

- CI: https://github.com/ateeducacion/omeka-s-DiskQuota/actions/runs/38247596002
- PHPMD: https://github.com/ateeducacion/omeka-s-DiskQuota/actions/runs/38247596214
- CodeQL default setup covers Actions/JavaScript/TypeScript and did not run for this stacked target branch; no CodeQL result is claimed for #33.

The PR remains OPEN, targeting the #32 branch; no merge was performed.
