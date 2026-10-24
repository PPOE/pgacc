# AGENTS.md

pgacc is a legacy PHP/PostgreSQL accounting tool for a political party, with a German UI.
It has 30 top-level PHP files, global functions, no framework, direct `pg_*` calls, and SQL built by string concatenation.
Overview: `README.md`. Test harness: `tests/README.md`. Open findings and the fix plan: `tests/COMPATIBILITY_PLAN.md`.

## Compatibility contract

- Production runs PHP 5.3.3 until the `php5-last` tag, so code must run on PHP 5.3 through 8.5.
  No PHP 5.4+ syntax: no short arrays `[]`, no `f()[0]`, no traits, no `::class`, no `$this` in closures.
  No PHP 7+ syntax or functions either: no `??`, `<=>`, `match`, typed parameters/returns/properties,
  arrow functions, nullsafe `?->`, `str_contains`/`str_starts_with`, etc. Use `array()`.
- PHPCompatibility checks 5.3-8.5 (`tests/static-analysis/phpcs.xml`).
- PostgreSQL: production runs 8.4.20 (schema: `tests/fixtures/schema.sql`). Tests use 8.4.20 (built from
  squeeze, `tests/postgres84/`, configured like production) and 18. Write SQL that works on both.
- Lanes (`tests/README.md`): PHP 5.3 x PG 8.4 (the production pair: squeeze i386, Lighttpd + PHP-CGI
  5.3.3-7+squeeze19, HTTP test scripts run from a PHP 5.6 client image), PHP 7.4 x PG 18 (coverage) and
  7.4 x 8.4, PHP 8.5 x PG 18. No PHP 5.6, 8.0 or 8.4 lanes.
- HTTP test scripts and their helpers (`tests/http-*.php`, `tests/write-workflows.php`,
  `tests/known-diagnostics.php`, `tests/reference/` client side) run on the PHP 5.6 client: keep them
  5.6-compatible. `tests/unit/FunctionsTest.php`, `CryptoTest.php`, `tests/runtime/` and the server-side
  `tests/php53/` scripts run on PHP 5.3 (no `finally`).

## Change workflow (owner's rules)

1. Test first. Characterize the current behaviour with a test before you change it.
2. Make minimal, behaviour-preserving changes. Behavioural changes are the owner's decision: surface them
   (PR text, `tests/COMPATIBILITY_PLAN.md`) and never slip them into a fix or refactor.
3. Every application change must cover all changed executable lines:
   `PGACC_TEST_ID=<id> PGACC_COVERAGE_BASE=<base branch, e.g. origin/master> sh tests/run.sh 7.4`
4. Run mutation tests on the changed lines before (the new test must be red) and after the change, and
   report both results:
   - code reached by HTTP tests: `sh tests/mutation/run.sh 7.4 <target> --changed-since=<base>` with a
     target in `tests/mutation/targets/`. Add or extend a target for new behaviour; introduce no survivors.
   - `functions.php` helpers: `sh tests/mutation/infection.sh 7.4|8.5`.
   - Details: `tests/mutation/README.md` ("Required step for fixes").
5. Never weaken tests, baselines, canaries or analysis config to get a green run. Each baseline change is reviewed.

## Baselines: keep them in sync

- `tests/known-diagnostics.php` is keyed by **file:line**. Adding or removing lines in an application file shifts
  the entries below that point; update them in the same change. When a fix removes a diagnostic, remove its entry.
  Never add an entry just to pass a lane.
- `tests/static-analysis/phpcs-baseline.txt` and `phpstan-baseline.neon` fail when a finding disappears or its
  count changes. After a fix, run `sh tests/static-analysis.sh --update-baselines`, review the diff, and update
  the findings list in `tests/COMPATIBILITY_PLAN.md`. Canaries live in `tests/static-analysis/canaries/`.
- Mutation targets: each `search` must match exactly once, and `replace` must keep the line count. App edits can
  break targets: `sh tests/mutation/run.sh 7.4 --self-test` checks every committed target.

## Running tests

- Docker only. Tests use synthetic data on internal networks with no published ports.
- `sh tests/run.sh` runs all lanes. `sh tests/run.sh 5.3|7.4|8.5` runs one lane on its default server
  (8.4 for 5.3, 18 otherwise); `PGACC_TEST_PG=8.4|18` overrides it. Coverage runs only on 7.4 x 18
  (report in `build/coverage/`).
- Local verification policy (CI runs the rest, `tests/README.md` "Continuous integration"): run only the
  affected lane, usually 7.4, with the coverage gate on the changed lines; mutation only on the changed
  lines (`--changed-since`); the red test before the fix; `sh tests/static-analysis.sh` if application code
  changed; `sh tests/run.sh 5.3` too for hotfix changes (`php5-last`). Run checks sequentially.
- Always set a unique `PGACC_TEST_ID=<a-z0-9_->` when another run could be active (worktrees, parallel agents).
  Remove tagged images afterwards with `docker rmi`.
- Other checks: `sh tests/static-analysis.sh` (`--report` lists every finding).
- Some lanes fail by design until their fixes land (currently PHP 8.5: implicit-connection deprecations displayed
  before redirects).
  Check `tests/COMPATIBILITY_PLAN.md` for the current state; don't assume. Known failures (`KF-*`) fail when they
  start passing, so a fix moves them to regular assertions.

## File conventions

- Several files use CRLF (`index.php`, `functions.php`, `new.php`, `edit.php`, `import.php`, ...), others LF.
  Check with `file <name>` and keep line endings byte-exact. Never convert them.
- Indentation is mixed. Do not reformat, re-indent or restyle code you are not changing. Diffs stay minimal.
  A formatting pass is planned for later ("coding-standard checks" in `tests/COMPATIBILITY_PLAN.md`).

## Safety

- Never commit `constants.php` (real configuration and keys), credentials, keys, production dumps or real
  personal or financial data. Tests use `tests/runtime/test-constants.php` and `tests/fixtures/`. New
  fixtures, keys and service responses must be synthetic.
- `files/` holds runtime receipt storage. Never commit it.
- Tests must never contact live external services (such as the membership system). Use local stubs inside the
  test network. Never touch production systems.
- Security-relevant findings are tracked in `tests/COMPATIBILITY_PLAN.md`. Don't spell out or publicize
  unfixed ones elsewhere.

## Context and token use

- Keep command output small: redirect test, Docker and mutation runs to a log file and print only the exit
  status plus a short `grep` of the result lines; open logs with `grep -n`/`sed -n` around a failure.
- Don't read large docs whole: `grep -n` `tests/COMPATIBILITY_PLAN.md`, `tests/README.md` and
  `tests/mutation/README.md` for the section you need.
- Read agent transcripts, `~/.codex/sessions`, `~/.claude/projects` or saved tool outputs only in a subagent
  that returns a short summary.
- Subagent reports: at most ~2,500 characters — verdict first, then only failures or decisions with
  `file:line`, then paths. Commit messages and the plan carry the details.
- Start a fresh session (or `/clear`) between unrelated tasks; leave a short handoff note first.

## Git

- Work on a branch. Keep commits focused: one fix, its tests and its baseline updates together.
- Parallel agents use separate `git worktree`s. Never use `git stash`, which is shared across worktrees.

## Where to look

| Path | Role |
|---|---|
| `index.php` | Entry point: login/logout, `checklogin()`, requires every page file, dispatches on `?action=` (default: report) |
| `functions.php` | Shared helpers: `acc_header`/`acc_footer`, `getfilter`, `getsort`, `rights2orgasql` (rights to SQL) |
| `new.php`, `edit.php` | Voucher workflow: create, edit, approve (acks), finalize, merge, revisions |
| `report.php`, `vreport.php`, `tinyreport.php` | Financial reports (public report is the default page) |
| `import.php`, `import2.php` | BAWAG bank CSV import |
| `getusers.php`, `mbapi.php` | Membership-system interface (lookup and export API) |
| `file.php` | Encrypted receipt download (mcrypt-compatible helpers in `functions.php`, phpseclib); new receipts are `files/<n>.v2.aes` (configured `$key`), legacy `files/<n>.aes` |
| `migrate-receipt-key.php` | Command-line migration of legacy receipts to `<n>.v2.aes` (404 over HTTP); runbook in `tests/COMPATIBILITY_PLAN.md` |
| `table-schema.txt`, `tests/fixtures/` | Schema notes; test schema and synthetic data |
| `tests/run.sh`, `compose*.yaml` | Harness entry point and per-lane Compose files |
| `tests/http-*.php`, `tests/write-workflows.php`, `tests/unit/` | HTTP, write-workflow and PHPUnit tests |
| `tests/coverage/`, `tests/mutation/`, `tests/static-analysis/` | Coverage gate, mutation runners, analysis plus baselines |
| `tests/reference/` | PHP 5 golden master: `record.sh` (PHP 5.3 x PG 8.4), `run.sh compare <lane>`, reviewed `allowlist.php` (`accepted`/`todo`, optional `pg`) |
| `tests/postgres84/`, `tests/postgres-version.sh` | PostgreSQL 8.4.20 test server image; `PGACC_TEST_PG` selection |
