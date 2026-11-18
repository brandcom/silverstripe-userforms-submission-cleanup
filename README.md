# Silverstripe Userforms Submission Cleanup

[![CI](https://github.com/brandcom/silverstripe-userforms-submission-cleanup/actions/workflows/ci.yml/badge.svg)](https://github.com/brandcom/silverstripe-userforms-submission-cleanup/actions/workflows/ci.yml)

> [!WARNING]
> **Under active development.** This module permanently deletes form submissions and uploaded
> files. It is in production use on a single site so far, and configuration, task names and
> behaviour may still change between minor releases. Run it with `--dry-run` first, take a
> database and asset backup, and pin a minor version (e.g. `~1.2.0`) until the module is
> marked stable.

Deletes [userforms](https://github.com/silverstripe/silverstripe-userforms) submissions older
than a retention window (default 90 days): submission, all field rows, uploaded files and their
version history. Runs as a cron task or, for hosts without shell cron, via a token-protected URL.

## Requirements

- Silverstripe 6 (`silverstripe/framework` ^6.2.13, `silverstripe/assets` ^3.2.4,
  `silverstripe/versioned` ^3.2.1)
- `silverstripe/userforms` ^7.1.4
- PHP 8.3+

### Tested versions

The module reads and deletes userforms data partly via raw SQL (`SubmittedFormField.ParentID`,
`SubmittedFileField.UploadedFileID`, `EditableFileField.FolderID`, `File.UserFormUpload`). A
schema change in userforms would not cause a Composer conflict but wrong results, so the
upper bound stays at the tested major version.

| | lowest | newest tested |
| --- | --- | --- |
| `silverstripe/userforms` | 7.1.4 | 7.1.4 |
| `silverstripe/framework` | 6.2.13 | 6.3.8 |
| `silverstripe/assets` | 3.2.4 | 3.3.2 |
| `silverstripe/versioned` | 3.2.1 | 3.3.0 |

The lower bounds are the first releases without known security advisories. Older 6.x/7.x
releases have published RCE and XSS issues, and Composer 2.9+ refuses to install them anyway.
The data model the module uses is unchanged since userforms 7.0.0.

The CI workflow runs the test suite against the lowest and the newest versions (PHP 8.3 and
8.4), on every pull request and weekly.

## Installation

```bash
composer require brandcom/silverstripe-userforms-submission-cleanup
vendor/bin/sake dev/build flush=1
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
picks it up. All cleanup tasks share a database lock: a run that starts while another one is
active (cron, HTTP, orphan task) is skipped instead of working on the same records.

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
- While another cleanup run (HTTP or CLI) is active, the call answers **409** and does nothing.
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

A file is only deleted if it is clearly a form upload. It is **kept** (but detached from the
submission, and otherwise not modified) if

- its `UserFormUpload` flag is `f`,
- the flag is empty (older uploads) and the file is not in a folder that a form upload field
  writes to. Old submissions can point at file IDs that now belong to editorial files,
- it is linked in content (`FileLink`), or
- another submission references it.

The flag is read directly from the database. `File::isTrackedFormUpload()` is avoided on
purpose: it counts an empty flag as upload as soon as any submission points at the file, and
writes that result back.

## What this module does not cover

Retention in the database and asset store only. Not covered:

- notification emails sent by userforms (mailboxes, mail server)
- database and file backups
- files on disk without a `File` record
- server and application logs

## Tests

The suite runs against a temporary database and an isolated asset store. From a project that
has the module installed and `phpunit/phpunit` ^11 as dev dependency:

```bash
vendor/bin/phpunit -c vendor/brandcom/silverstripe-userforms-submission-cleanup/phpunit.xml.dist
```

The database user needs permission to create databases (in DDEV prefix the command with
`SS_DATABASE_USERNAME=root SS_DATABASE_PASSWORD=root`).

Standalone in the module root (needs `SS_DATABASE_*` in the environment, and stub `Page` and
`PageController` classes in `app/`, see the step "Project stub" in `.github/workflows/ci.yml`):

```bash
composer update                                  # newest versions
composer update --prefer-lowest --prefer-stable  # lowest allowed versions
vendor/bin/phpunit
```

## License

MIT, see [LICENSE](LICENSE).
