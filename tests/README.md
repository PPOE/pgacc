# Test harness

This directory contains an isolated characterization-test environment for the
legacy pgacc application. It does not use production credentials, production
data, or the production attachment directory.

Run the current harness with:

```bash
sh tests/run.sh
```

The runner currently performs three checks:

1. It syntax-checks every top-level PHP application file without executing it.
2. It installs a data-free copy of the pgacc schema and synthetic fixtures in
   PostgreSQL, then runs database characterization assertions with `psql`.
3. It starts the unchanged application in a disposable PHP 7.4 runtime with
   the PostgreSQL extension and exercises real HTTP routes against the fixture
   database.

PHP 7.4 is used only as a temporary characterization bridge because the
unchanged application contains syntax removed in PHP 8. It is not a supported
deployment target. The database uses PostgreSQL 17 as the initial replacement
platform target.

`fixtures/schema.sql` was reconstructed from a read-only inspection of the
deployed PostgreSQL 8.4.20 catalog. It preserves the observed tables, columns,
defaults, sequences, and constraints while excluding the dated backup table,
owners, permissions, credentials, and all production rows. `fixtures/data.sql`
contains only visibly synthetic records.

`database-characterization.sql` covers password hashing, open and closed
voucher states, attachment propagation, public transactions and spendings,
donation totals, report type mapping, and review-progress counts using SQL
contracts from the current PHP application.

`http-smoke.php` covers the public report, transactions, spendings, rejected
login, successful login, and an authenticated ledger request. The runtime uses
test-only constants and the locked Composer dependencies. It has no published
host port, cannot reach external networks, and receives only synthetic data.
The test server enables `output_buffering=4096` because the current login flow
sends redirect and cookie headers after page rendering has started.

The PHP 7.4 package repository is archived, so `php-runtime.Dockerfile` pins
the base images and installs `pgsql` from the archived Bullseye repository.
This runtime is only a characterization bridge, not a deployment target.
