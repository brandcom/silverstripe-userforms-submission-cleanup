# Changelog

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
