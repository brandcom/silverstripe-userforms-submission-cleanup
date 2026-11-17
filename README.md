# Silverstripe Userforms Submission Cleanup

Deletes [userforms](https://github.com/silverstripe/silverstripe-userforms) submissions older
than a retention window (default 90 days): submission, all field rows, uploaded files and their
version history. Runs as a cron task or, for hosts without shell cron, via a token-protected URL.

## Requirements

- Silverstripe 6 (`silverstripe/framework` ^6, `silverstripe/assets` ^3, `silverstripe/versioned` ^3)
- `silverstripe/userforms` ^7
- PHP 8.3+

## Installation

```bash
composer require brandcom/silverstripe-userforms-submission-cleanup
vendor/bin/sake dev/build flush=1
```

Until the package is listed on Packagist, add the repository first:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/brandcom/silverstripe-userforms-submission-cleanup" }
]
```

## Usage

### Cron (shell)

```bash
# daily, 03:15
15 3 * * * cd /path/to/site && vendor/bin/sake tasks:userforms-delete-expired >> logs/userforms-cleanup.log 2>&1
```

| Option | |
| --- | --- |
| `--days=<n>` | Override the retention window |
| `--dry-run` | Only count what would be deleted |
| `--max-runtime=<s>` | Stop after n seconds, the next run continues |

The task exits non-zero if a submission could not be deleted, so cron mail or monitoring
picks it up.

### Cron via URL (e.g. All-Inkl)

1. Set a token of at least 32 characters in `.env`:
   ```bash
   USERFORMS_CLEANUP_TOKEN="<openssl rand -hex 24>"
   ```
2. Let the hoster's cron call the URL daily:
   ```
   https://www.example.com/_userforms-cleanup?token=<USERFORMS_CLEANUP_TOKEN>
   ```

Prefer the header `X-Cleanup-Token: <token>` where the cron tool allows it: a token in the
query string ends up in access logs and on error pages.

- Without the env var, or with a missing or wrong token, the endpoint answers **404**.
- Each call stops after 20 seconds (shared hosting limits), the next call continues.
- A DB lock prevents overlapping calls (**409**).
- `?dry-run=1` only counts. The retention window cannot be changed over HTTP.
- Plain-text response, status **200**, or **500** on errors.

### One-off: orphaned submission data

Older cleanup code that deleted `SubmittedForm` via raw SQL often left field rows and uploads
without a parent submission. The regular task never sees those. Clean them up once:

```bash
vendor/bin/sake tasks:userforms-purge-orphans --dry-run
vendor/bin/sake tasks:userforms-purge-orphans
```

It archives the uploads of orphaned upload fields, deletes orphaned rows from every table of
the `SubmittedFormField` hierarchy, and purges version history of uploads that are already gone.
Take a database backup first.

## Configuration

```yaml
Brandcom\UserFormsSubmissionCleanup\Tasks\DeleteExpiredSubmissionsTask:
  retention_days: 90      # days to keep
  batch_size: 100         # submissions per pass

Brandcom\UserFormsSubmissionCleanup\SubmissionCleaner:
  purge_file_versions: true   # also delete File_Versions rows (filenames)

Brandcom\UserFormsSubmissionCleanup\Control\CleanupController:
  max_runtime: 20         # seconds per HTTP call
```

## How files are deleted

Userforms stores uploads in the draft stage (older versions also published them). Tasks run in
`Stage.Live` mode, so the built-in `cascade_deletes` on `SubmittedFileField.UploadedFile`
resolves against `File_Live` and leaves the draft row and the asset behind. This module
therefore archives each upload explicitly in draft mode (`doArchive()`): draft row, live row and
the asset in the public or protected store are removed. `doArchive()` keeps `File_Versions`,
which still holds the filename, so those rows are deleted too (`purge_file_versions`).

A file is **kept** (but detached from the submission) if it was not created by a form
(`File::isTrackedFormUpload()`), is linked in content (`FileLink`), or is referenced by another
submission.

## What this module does not cover

Retention in the database and asset store only. Not covered:

- notification emails sent by userforms (mailboxes, mail server)
- database and file backups
- files on disk without a `File` record
- server and application logs

## License

MIT, see [LICENSE](LICENSE).
