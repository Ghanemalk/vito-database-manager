# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-19

The first public release.

### Added

- **Launcher:** an **Open** button for every database and a **Database Manager** button on Vito's Databases page.
  The manager opens inside Vito's layout, keeps Vito's header and navigation rail, and folds the server menu away while
  it is open. The address bar keeps the database and the view, so reloads, shared links and the back button work.
- **Overview:** sizes, row estimates, engines and comments for tables and views, plus routines and triggers.
- **Rows grid:**
  - server-side paging, sorting and a search across every column;
  - 18 filter operators;
  - column picking and bulk delete.
- **Row editor:**
  - type-aware inputs: JSON validation and formatting, selects for enums and booleans, checkboxes for sets;
  - a hex editor with file upload and download for binary columns;
  - any value can be set to `NULL`, `DEFAULT` or a SQL function;
  - duplicate a row;
  - a guard against losing unsaved changes.
- **Tables without a primary or unique key** stay editable: rows are matched by a hash of their contents on MySQL and
  MariaDB, and by `ctid` on PostgreSQL.
- **Create tables visually.** Add, change and drop columns, indexes, foreign keys and check constraints. Every change
  shows its exact SQL first.
- **Table settings:**
  - comment, engine, collation and auto-increment;
  - maintenance tasks;
  - duplicate (with or without data), rename, move to another schema (PostgreSQL), empty and drop;
  - copy or download the `CREATE` statement.
- **Import and export:** CSV import with column mapping and insert, skip-duplicates or upsert modes. CSV, JSON and SQL
  export of a whole table or of the filtered rows.
- **SQL console:**
  - multi-statement scripts with syntax highlighting;
  - read-only and transaction modes, row limits and timeouts;
  - per-database history;
  - errors that point to the failing statement and line;
  - scripts up to 50 MB, uploaded in parts.
- **Processes:** live server statistics and running queries, with stop query and end connection.
- **Security:**
  - Vito's `update` permission is required on both the server and the database;
  - SSH transport through Vito's own key;
  - CSRF-protected API;
  - typed-name confirmations for destructive actions;
  - an audit log in `storage/logs/database-manager.log`.
- **Looks:** follows Vito's light, dark or system appearance as it changes, and has a responsive layout for phones.
- **Supported engines:** MySQL 8.4 and 9.7, MariaDB 10.11, 11.4, 11.8 and 12.3, and PostgreSQL 15, 16, 17 and 18,
  each covered by the integration suite.

[Unreleased]: https://github.com/Ghanemalk/vito-database-manager/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Ghanemalk/vito-database-manager/releases/tag/v1.0.0
