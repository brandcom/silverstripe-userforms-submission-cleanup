# Changelog

## Unreleased

Fixes from a code review of 1.3.0.

Behaviour changes:

- A file only counts as form upload inside a folder that a form upload field writes to or wrote
  to (now including the field's version history). The `t` flag alone is no longer enough: a form
  upload an editor moved to another folder is kept.
- Files referenced by any record through `has_one` or `many_many` are kept, not only files
  linked in content or used by another submission.
- Output separates kept files into "still in use" and "not a form upload", and reports files
  that were already gone.
- `--dry-run` reports per upload what a real run would do, instead of counting references.
- The HTTP trigger answers unauthorised calls with the site's regular 404 page.
- The orphan task decides on version history by the file's last location before deletion, and
  respects `purge_file_versions: false`.

Fixes:

- A PHP error (e.g. `TypeError`) while deleting a submission left the database transaction
  open. Later writes landed in it and were rolled back with the connection, while deleted assets
  stayed deleted. Transactions are now rolled back on any `Throwable`, and the run aborts if a
  transaction is left open.
- `batch_size: 0` made the task loop forever while holding the lock. `batch_size`,
  `--max-runtime` and the retention window are validated strictly, invalid values stop the task.
- With `keep_archived_assets: true` deleted uploads stayed in the protected store without any
  record. The setting is now switched off for the cleanup's archive calls.
- `purgeFileVersions()` skips files that still exist in a stage and covers version tables of
  File subclasses. Its second parameter was removed.
- The orphan task stopped at the first failing upload. Failures are now reported, the field row
  is kept for the next run, and the task exits non-zero.
- Submissions are paged by ID, so a submission that is not removed cannot be loaded again.
- `wasSkipped()` is reset on every run. `max_runtime: 0` no longer removes the HTTP time limit.
- A failing lock release no longer hides the original error.

## 1.3.0 (2026-11-18)

- Retention window can be set via `USERFORMS_CLEANUP_RETENTION_DAYS` in `.env`. Order: `--days`,
  env, `retention_days` config, 90. Applies to the HTTP trigger too.
- Invalid values stop the task (exit code invalid) instead of falling back. `--days` is now
  validated as strictly as the env value: `1.5` or `30.0` are rejected, previously cast to an int.
- Output names the source of the retention window.

## 1.2.1 (2026-11-18)

- Marked as under active development (README notice, Composer description), CI badge.

## 1.2.0 (2026-11-18)

- Minimum versions raised to the first releases without known security advisories:
  `silverstripe/userforms` ^7.1.4, `silverstripe/framework` ^6.2.13, `silverstripe/assets` ^3.2.4,
  `silverstripe/versioned` ^3.2.1. Composer 2.9+ already refused the older ones.
- CI (GitHub Actions): test suite against the lowest allowed and the newest versions, PHP 8.3
  and 8.4, plus PSR-12 check.
- README: tested versions, removed outdated Packagist note.

## 1.1.0 (2026-11-17)

- Fix: the lock against overlapping HTTP calls did not work (`canLock()` means "lock is free",
  not "locking supported"), two calls could process the same submissions.
- All cleanup tasks now share one lock, so cron, HTTP trigger and the orphan task never overlap.
- Fix: files with an empty `UserFormUpload` flag were treated as form uploads as soon as a
  submission pointed at them, and the flag was written back. Now an empty flag only counts as
  upload inside a form upload folder; the flag is read without side effects.
- Test suite (28 tests).

## 1.0.1 (2026-11-17)

- README: installation from the public repository.

## 1.0.0 (2026-11-17)

- `tasks:userforms-delete-expired`: deletes submissions older than the retention window,
  including field rows, uploaded files and their version history.
- `/_userforms-cleanup`: token-protected HTTP trigger for hosts without shell cron.
- `tasks:userforms-purge-orphans`: one-off cleanup of orphaned field rows and uploads.
