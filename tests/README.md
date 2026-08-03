# Tests

PHPUnit, installed via Composer (`composer install`). Run with:

```
vendor/bin/phpunit
```

Needs a MySQL database to test against - by default `volunteer_ops_test` on
`localhost` with the `root` user and no password (matching this project's
default local XAMPP setup). Override via env vars if needed:
`TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASS`.

## Setting up the local test database

```
mysql -u root -e "CREATE DATABASE volunteer_ops_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root volunteer_ops_test < tests/fixtures/schema-structure.sql
```

CI does the same thing against a fresh MySQL service container - see
`.github/workflows/ci.yml`.

## What `tests/fixtures/schema-structure.sql` is

A structure-only (`--no-data`) dump of a fully-migrated database, i.e. the
schema as it looks *after* every migration in `includes/migrations.php` has
already been applied. Regenerate it after adding a new migration:

```
mysqldump -u root --no-data --routines --triggers --skip-comments volunteer_ops > tests/fixtures/schema-structure.sql
```

**This is deliberately not `sql/schema.sql`** (the documented "fresh install"
baseline) - this fixture proves the migration runner's own logic, not that
`sql/schema.sql` itself loads cleanly, so the two are kept separate on
purpose.

`sql/schema.sql` previously had a real ordering bug (INSERTed into
`inventory_categories`/`inventory_locations` before those tables' own
`CREATE TABLE` statements, ~380 lines later in the same file) plus two
related forward-reference issues that surfaced once that was fixed: a
stray mid-file `SET FOREIGN_KEY_CHECKS = 1;` that re-enabled FK validation
before several tables added later in the file's growth, and a MariaDB-only
limit on combining `ADD COLUMN` + `ADD FOREIGN KEY` (same column) in one
`ALTER TABLE`. All three are fixed as of the commit that added this note -
loading `sql/schema.sql` then `sql/inventory_schema.sql` standalone (e.g.
via `mysql < file.sql`, or through `install.php`'s loader) now succeeds
end to end against a genuinely empty database.

`MigrationsRunnerTest` proves the migration **runner's own control flow**
is correct against a real schema - version gating, the 5-minute failure
cooldown, stop-at-first-failure ordering, and (very usefully) that every
migration is genuinely idempotent when run against a database that already
has its effects applied. It does **not** prove a true zero-to-118 install
from `sql/schema.sql` succeeds - that's what `FreshInstallTest` (below) is for.

## What `FreshInstallTest` covers

Proves `sql/schema.sql` + `sql/inventory_schema.sql` load cleanly end to end
against a genuinely empty database - the "true zero-to-118 install" case
`MigrationsRunnerTest` doesn't cover (that one starts from
`schema-structure.sql`, already fully migrated).

It uses `includes/sql-statement-splitter.php`'s `splitSqlStatements()` - the
same quote-aware statement splitter `install.php`'s real web installer uses
in place of a naive `explode(';', ...)` (which breaks on `;` inside seeded
HTML/CSS, e.g. `font-family: Arial, sans-serif;`) - so a regression in that
shared parsing logic fails this test too, not just a regression in the two
`.sql` files themselves.

Runs against its own disposable database (`TEST_DB_NAME` + `_freshinstall`,
e.g. `volunteer_ops_test_freshinstall`), created in `setUpBeforeClass()` and
dropped in `tearDownAfterClass()` via a separate PDO connection - never the
shared `TEST_DB_NAME` fixture `MigrationsRunnerTest` and others depend on
already being fully migrated. No extra setup needed beyond the DB credentials
already used by every other test (`TEST_DB_HOST`/`PORT`/`USER`/`PASS`); the
test creates and tears down its own schema itself.

## JavaScript tests

A handful of pure/near-pure functions from war-room.php's inline `<script>`
were pulled out into `assets/js/war-room-utils.js` (loaded via a normal
`<script src>` tag, so every other call site in war-room.php is unchanged)
specifically so they're unit-testable. Covered by
`tests/js/war-room-utils.test.js`, using Node's built-in test runner - no npm
dependency needed:

```
node --test tests/js/*.test.js
```

This is a deliberately narrow start, not a full decomposition of
war-room.php's ~1500 lines of inline JS - see the tech-debt tracker for the
larger (and riskier) job of splitting the rest of it out, which should wait
until more of it has coverage like this.
