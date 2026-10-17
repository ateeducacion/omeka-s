# DiskQuota pending uploads and URL quota enforcement

PR: https://github.com/ateeducacion/omeka-s-DiskQuota/pull/32
Branch: `hotfix/enforce-pending-file-quotas`
Final commit: `bf8990f109824896502c529eb37fda82a788214a`.
Base: `ebce0f0`.

The native `media.ingest_file.pre` event checks actual downloaded/uploaded bytes before permanent storage. Request-local pending originals contribute to final media owner and item-site budgets. Rejected hydration removes only tracked, unpersisted originals/thumbnails through the core deletion routine; failed reservations are released. Usage SQL errors propagate. Indexed multipart checks use the selected file, while URL data cannot spoof size using metadata or unrelated uploads.

## TDD evidence

- Registered-event oversize/aggregate quota regressions failed before implementation.
- Three resolver regressions failed before fixing the selected multipart file and URL handling.
- Two usage-query failure regressions failed before removing the zero-usage fallback.
- Empty originals and thumbnail-only cleanup each had a failing regression before correction.
- Restoring the prior PR head listener reproduces orphan thumbnails in the real URL batch test: 27 checks pass, the thumbnail cleanup check fails. The same test passes with the correction.
- Three 400 KiB uploads against a 1 MiB site quota reject the third and clean both already accepted files.

## Final local validation

- PHPUnit: 124 tests, 281 assertions; 98.18% line coverage (594/605).
- PSR2, actionlint, shell syntax and git diff whitespace checks pass.
- PHPMD 3.0.0, same version/ruleset as CI: zero code-size results on all tracked PHP files plus the new PendingUploads class. Analysis ran in a fresh temporary source directory to avoid stale PDepend metrics during repeated local runs. No rules suppressed.
- SQLite: 28/28 real integration checks, all three scripts report AUDIT_RESULT=PASS.
- MariaDB: 28/28 real integration checks, all three scripts report AUDIT_RESULT=PASS.
- Runtime: Omeka `74a5e131f1881e9d565cf346ff090155b7ee833b`, PHP 8.4.17, Doctrine DBAL 2.13.9. Local PHPUnit uses PHP 8.5.11; CI uses PHP 8.1.
- Native runs use disposable databases/containers. No production/Playground data changed.

## Remaining scope

No shared reservations for concurrent requests; existing-media owner/site reassignments, mutable-ingester updates, and custom ingestion paths outside TempFile are separate work. The current usage metric counts originals, excluding thumbnail sizes and temporary downloads. Cleanup tracks entities without IDs; this is not a general rollback mechanism for ORM flush failures after IDs have already been assigned.

The PR remains open and has not been merged.

## GitHub validation

All 12 reported final checks pass (including both preview runs) for the final head, including CodeQL, PHPMD, Codecov, PHPUnit and real SQLite ingestion. CI independently confirms 124 tests / 281 assertions / 98.18% coverage and 28 real SQLite checks.

- CI: https://github.com/ateeducacion/omeka-s-DiskQuota/actions/runs/38242850077
- PHPMD: https://github.com/ateeducacion/omeka-s-DiskQuota/actions/runs/38242850068
- CodeQL: https://github.com/ateeducacion/omeka-s-DiskQuota/actions/runs/38242849517
- GitHub marks all three PR PHPMD alerts as fixed: #23 (class complexity), #24 (cyclomatic), #25 (NPath). These were quality warnings surfaced through Advanced Security, not security vulnerabilities. Rules were not suppressed and alerts were not dismissed manually.
