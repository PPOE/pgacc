# Test harness

This directory contains an isolated characterization-test environment for the
legacy pgacc application. It does not use production credentials, production
data, or the production attachment directory.

Run the current harness with:

```bash
sh tests/run.sh
```

The runner currently performs two baseline checks:

1. It syntax-checks every top-level PHP application file without executing it.
2. It initializes and queries a synthetic PostgreSQL database with `psql`,
   including the `pgcrypto` extension required by pgacc password checks.

PHP 7.4 is used only as a temporary characterization bridge because the
unchanged application contains syntax removed in PHP 8. It is not a supported
deployment target. The database uses PostgreSQL 17 as the initial replacement
platform target.

`fixtures/harness.sql` is deliberately a test-harness probe, not the pgacc
application schema. A reviewed, data-free schema and synthetic application
fixtures will be added in a separate change.

The PHP-to-PostgreSQL application integration is also intentionally deferred.
Keeping the first slice independent of packages from the retired PHP 7.4 base
distribution makes this baseline deterministic and easier to maintain.
