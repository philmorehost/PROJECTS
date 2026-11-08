# Migrations

`NNNN_description.sql`, zero padded, applied in order by `php bin/migrate.php`.

- Never edit a file that has been applied anywhere — the runner stores a sha256 and refuses to
  continue when a shipped file changes. Add the next number instead.
- One statement per `;`. No `DELIMITER` blocks, triggers or stored procedures (those live in
  `database/schema.sql` / `database/ledger-guards.sql`).
- Every file must be safe on a database that already holds real money: add columns as `NULL` or
  with a default, backfill in a separate statement, and only then tighten the column.
- Record anything an operator must do by hand (index builds on large tables, downtime) in the
  header comment of the migration and in `docs/DEPLOYMENT.md` §10.
