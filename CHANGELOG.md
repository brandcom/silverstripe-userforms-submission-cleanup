# Changelog

## 1.0.1 (2026-11-17)

- README: installation from the public repository.

## 1.0.0 (2026-11-17)

- `tasks:userforms-delete-expired`: deletes submissions older than the retention window,
  including field rows, uploaded files and their version history.
- `/_userforms-cleanup`: token-protected HTTP trigger for hosts without shell cron.
- `tasks:userforms-purge-orphans`: one-off cleanup of orphaned field rows and uploads.
