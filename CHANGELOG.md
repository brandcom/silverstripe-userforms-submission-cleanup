# Changelog

## Unreleased

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
