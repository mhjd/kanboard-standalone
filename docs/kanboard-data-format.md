# Kanboard data format sources (authoritative)

This project treats Kanboard's **SQLite database file** as the authoritative data format
for import/export compatibility. The sources below are derived directly from the
Kanboard codebase in this repository.

## Canonical storage

- Default database driver is SQLite, and the default SQLite filename is
  `data/db.sqlite` via `DB_FILENAME`.
  - Source: `app/constants.php` (defines `DB_DRIVER` and `DB_FILENAME`).
  - Source: `config.default.php` (documents SQLite as the default driver).

## Export / backup

- The built-in "Download the Sqlite database" action serves a gzip-compressed
  SQLite file named `db.sqlite.gz`.
  - Source: `app/Controller/ConfigController.php` (`downloadDb`).
  - Source: `app/Model/ConfigModel.php` (`downloadDatabase` uses `gzencode` on
    `DB_FILENAME`).

## Import / restore

- The built-in "Upload the Sqlite database" action accepts a gzip-compressed
  SQLite file and replaces the current database with the decoded contents.
  - Source: `app/Controller/ConfigController.php` (`saveUploadedDb`).
  - Source: `app/Model/ConfigModel.php` (`uploadDatabase` uses `gzdecode` into
    `DB_FILENAME`).

## Non-canonical exports (partial)

- CSV exports exist for tasks, subtasks, transitions, and summary reports, but
  these are partial views and not full-fidelity backups.
  - Source: `app/Controller/ExportController.php` and `app/Console/*ExportCommand.php`.

## Working assumption for the spike

The authoritative import/export format for the standalone app is the SQLite
file `data/db.sqlite` (optionally gzipped as `db.sqlite.gz`). We will use that
as the basis for fixtures and round-trip tests.
