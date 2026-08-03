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
baseline). `sql/schema.sql` currently has a real ordering bug - it INSERTs
into `inventory_categories` before that table's own `CREATE TABLE` statement,
which comes ~380 lines later in the same file - so loading it standalone (or
via `install.php`'s loader) fails. See the tech-debt tracker / spawned task
for the fix; it's orthogonal to what these tests check.

Because of that, `MigrationsRunnerTest` proves the migration **runner's own
control flow** is correct against a real schema - version gating, the
5-minute failure cooldown, stop-at-first-failure ordering, and (very
usefully) that every migration is genuinely idempotent when run against a
database that already has its effects applied. It does **not** prove a
true zero-to-118 install from `sql/schema.sql` succeeds - that needs the
schema.sql ordering bug fixed first, and a separate test using the real
fresh-install file instead of this fixture.

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
