# AGENTS.md

pgacc is a legacy PHP/PostgreSQL accounting tool for a political party, with a German UI.
It has 29 top-level PHP files, global functions, no framework, direct `pg_*` calls, and SQL built by string concatenation.
Overview: `README.md`. Test harness: `tests/README.md`. Open findings and the fix plan: `tests/COMPATIBILITY_PLAN.md`.

## Compatibility contract

- Code must run on PHP 5.6 through 8.5. Use no PHP 7+ syntax or functions: no `??`, `<=>`, `match`,
  typed parameters/returns/properties, arrow functions, nullsafe `?->`, `str_contains`/`str_starts_with`, etc.
  Short arrays `[]` are allowed (5.4+). Existing code uses `array()`; match the surrounding code.
- PostgreSQL: production runs a very old server; the schema was reconstructed from 8.4 (`tests/fixtures/schema.sql`).
  Tests use PostgreSQL 17. Write SQL that works on both.
- Test files must stay PHP 5.6-compatible too (`tests/known-diagnostics.php`, mutation targets, helpers).

## Change workflow (owner's rules)

1. Test first. Characterize the current behaviour with a test before you change it.
2. Make minimal, behaviour-preserving changes. Behavioural changes are the owner's decision: surface them
   (PR text, `tests/COMPATIBILITY_PLAN.md`) and never slip them into a fix or refactor.
3. Every application change must cover all changed executable lines:
   `PGACC_TEST_ID=<id> PGACC_COVERAGE_BASE=<base branch, e.g. origin/master> sh tests/run.sh 7.4`
4. Run mutation tests before and after the change, and report both results:
   - code reached by HTTP tests: `sh tests/mutation/run.sh <lane> <target>` with a target in
     `tests/mutation/targets/`, on 7.4 and 5.6. Add or extend a target for new behaviour; introduce no survivors.
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
- `sh tests/run.sh` runs all lanes. `sh tests/run.sh 5.6|7.4|8.0|8.5` runs one lane.
  Coverage runs only on 7.4 (report in `build/coverage/`).
- Always set a unique `PGACC_TEST_ID=<a-z0-9_->` when another run could be active (worktrees, parallel agents).
  Remove tagged images afterwards with `docker rmi`.
- Other checks: `sh tests/static-analysis.sh` (`--report` lists every finding) and `sh tests/php-syntax-matrix.sh`.
- Some lanes fail by design until their fixes land (currently PHP 8.0/8.5: login headers, unlisted diagnostics).
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
| `file.php` | Encrypted receipt download (`mcrypt`, removed in PHP 7.2) |
| `table-schema.txt`, `tests/fixtures/` | Schema notes; test schema and synthetic data |
| `tests/run.sh`, `compose*.yaml` | Harness entry point and per-lane Compose files |
| `tests/http-*.php`, `tests/write-workflows.php`, `tests/unit/` | HTTP, write-workflow and PHPUnit tests |
| `tests/coverage/`, `tests/mutation/`, `tests/static-analysis/` | Coverage gate, mutation runners, analysis plus baselines |
