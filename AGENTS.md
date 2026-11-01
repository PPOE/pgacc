# AGENTS.md

pgacc is a legacy PHP/PostgreSQL accounting tool for a political party, with a German UI.
It has 28 top-level PHP files, the two entry points in `public/` (the document root) and library files in `src/`, global functions, no framework, `pg_*` calls (mostly through
the `src/db.php` helpers), and SQL built by string concatenation.
Overview: `README.md`. Test harness: `tests/README.md`. Open findings and the fix plan: `tests/COMPATIBILITY_PLAN.md`.

## Compatibility contract

- After the 1.0.0 release (tag `php5-last`, the last one for PHP 5.3 / PostgreSQL 8.4) the application supports
  and tests **PHP 8.5 only** and **PostgreSQL 18 only** (new server). Modern PHP syntax and functions are allowed,
  but the change rules below still apply: minimal diffs, no syntax modernization of code you are not changing
  (formatting is automatic, see "File conventions"). PHPCompatibility checks `testVersion` 8.5- (`tests/static-analysis/phpcs.xml`), PHPStan
  `phpVersion` 8.5.
- PostgreSQL: schema in `tests/fixtures/schema.sql`; tests run on 18 (`compose.test.yaml`).
- One lane (`tests/README.md`): PHP 8.5 x PG 18, with coverage, the changed-line gate, the mutation runner,
  Infection and the reference comparison (golden master `tests/reference/php85/`, recorded on 8.5). Open migration steps: "PHP 8.5-only migration" in
  `tests/COMPATIBILITY_PLAN.md`.

## Change workflow (owner's rules)

1. Test first. The red test is the smallest focused test (one PHPUnit method or HTTP scenario) and must fail
   on an assertion; a fixture, connection, parse or fatal setup error is not red evidence.
2. Make minimal, behaviour-preserving changes. Behavioural changes are the owner's decision: surface them
   (PR text, `tests/COMPATIBILITY_PLAN.md`) and never slip them into a fix or refactor.
3. Cover every changed executable line. Mutation evidence before merge: run the affected mutants locally with
   `--only=` (red before the fix, green after; report both), or add the `mutation` label to the pull request
   (CI's mutation job, blocking). HTTP-reached code: a target in `tests/mutation/targets/`; add or extend one
   for new behaviour, introduce no survivors. `functions.php` and `src/format.php` helpers: `sh tests/mutation/infection.sh 8.5`, once the helper change
   is finished. Details: `tests/mutation/README.md` ("Required step for fixes").
4. Never weaken tests, baselines, canaries or analysis config to get a green run. Each baseline change is reviewed.

## Verification cadence

- One fix track by default; with the owner's approval at most two agents, in separate worktrees. Run only
  one expensive Docker batch (lane, coverage, mutation, Infection) at a time on the shared host.
- Before editing, record the branch, the starting commit and the real PR base (the branch the PR targets).
- While editing, run only the focused test with the development loop (`sh tests/dev.sh up`, then
  `sh tests/dev.sh test <http-file>` or `sh tests/dev.sh unit --filter <test>`; `tests/README.md`,
  "Development loop"): the checkout is bind-mounted, so no rebuild is needed. Explicit mutants
  (`--only=`) are welcome but not required on every iteration. Full `tests/run.sh` only for the readiness batch. Review the finished patch once and fix
  related findings together; unrelated findings go to the plan.
- Readiness batch, editing stopped, in this order: (1) `sh tests/static-analysis.sh` if application code
  changed; (2) `PGACC_COVERAGE_BRANCHES=0 PGACC_COVERAGE_BASE=<real PR base> sh tests/run.sh`, and
  confirm the log has the changed-line result (`<file>: N changed executable lines, all covered`);
  (3) mutation evidence: run the affected mutants locally (`--only=`, or the target with
  `--changed-since=<real PR base>`) or add the `mutation` label to the pull request before merge (CI then
  runs the changed-line mutation job, blocking).
- A 1.0.x (PHP 5.3) hotfix needs the harness of the `php5-last` tag: this branch has no PHP 5.3 lane.
- Run the reference comparison (`sh tests/reference/run.sh compare 8.5`) once, after the patch stabilizes, when
  a change can affect page output, SQL or stored data.
- Every application file keeps at least 95% line coverage (`tests/coverage/check-file-floor.php`, run by the lane;
  reviewed exceptions in `tests/coverage/file-floor-exceptions.php`).
- CI runs tests, the coverage gate, the reference comparison and static analysis on every pull request
  push; mutation only with the `mutation` or `full-ci` label, weekly and on manual runs; broad Infection
  and full mutation sweeps with `full-ci`, weekly and manual (`tests/README.md`, "Continuous integration"). Report checks left to CI as pending, not as passed.
- After a fix, rerun only the checks it invalidates. Docs-only edits need no runtime run. One tool session
  per running check; wait for it, no polling loops. Capture the real exit code before `grep`.
- Status and handoff notes record per check: source (commit or diff), base, command, exit code, results
  and what is pending.

## Baselines: keep them in sync

- `tests/known-diagnostics.php` is keyed by **file:line**. Adding or removing lines in an application file shifts
  the entries below that point; update them in the same change. When a fix removes a diagnostic, remove its entry.
  Never add an entry just to pass a lane.
- `tests/static-analysis/phpcs-baseline.txt` and `phpstan-baseline.neon` fail when a finding disappears or its
  count changes. After a fix, run `sh tests/static-analysis.sh --update-baselines`, review the diff, and update
  the findings list in `tests/COMPATIBILITY_PLAN.md`. Canaries live in `tests/static-analysis/canaries/`.
- Mutation targets: each `search` must match exactly once, and `replace` must keep the line count. App edits can
  break targets: `sh tests/mutation/run.sh 8.5 --self-test` checks every committed target.

## Running tests

- Docker only, synthetic data, internal networks, no published ports. Local default while editing:
  `sh tests/dev.sh` (development loop, no coverage). `sh tests/run.sh` (same as
  `sh tests/run.sh 8.5`) runs the PHP 8.5 x PG 18 lane with coverage (`build/coverage/`).
  `sh tests/static-analysis.sh --report` lists every finding.
- Always set a unique `PGACC_TEST_ID=<a-z0-9_->` when another run could be active (worktrees, parallel agents).
  Remove tagged images afterwards with `docker rmi`.
- The lane passes and is blocking in CI. Check `tests/COMPATIBILITY_PLAN.md` for the current state; don't assume. Known failures
  (`KF-*`) fail when they start passing, so a fix moves them to regular assertions.

## File conventions

- PHP files follow PER Coding Style 3.0, whitespace rules only (`.php-cs-fixer.dist.php`; 4-space indentation,
  PER brace placement and spacing). A hook formats edited PHP files (`.claude/settings.json`,
  `scripts/claude-format-hook.sh`); run `sh scripts/format.sh` manually otherwise. CI fails on unformatted code
  (`sh scripts/format.sh --check --ci`). Never change inline HTML or string contents to satisfy the formatter.
- Every text file of ours is LF (`.gitattributes`, `* text=auto eol=lf`). Test fixtures, the reference recordings
  and third-party files (`tests/fixtures/`, `tests/reference/fixtures/`, `tests/reference/php85/`, `public/icons/`,
  vendored `public/js/` files) are byte-exact (`-text`): never convert them.
- Token-changing modernization (array syntax, `elseif`, visibility, closing tags, imports) is a separate,
  planned change: don't mix it into fixes. The changed-line gates are strict (every changed line counts);
  `tests/coverage/ast-equivalence.sh` and `token-equivalence.sh` are standalone review tools. Exempting
  AST-equivalent files for a syntax-only pull request is an owner decision (`tests/README.md`, "Coverage"). The formatting commit is listed in `.git-blame-ignore-revs`.

## Safety

- Never commit `constants.php` (real configuration and keys), `files/` (receipt storage), credentials, keys,
  production dumps or real personal or financial data. Tests use `tests/runtime/test-constants.php` and
  synthetic fixtures, keys and service responses. They never contact live external services (such as the
  membership system): use local stubs inside the test network. Never touch production systems.
- Security-relevant findings are tracked in `tests/COMPATIBILITY_PLAN.md`. Don't spell out or publicize
  unfixed ones elsewhere.

## Context and token use

- Keep command output small: redirect runs to a log file, print the exit status plus a short `grep` of the
  result lines. Open logs and the large docs (`tests/COMPATIBILITY_PLAN.md`, `tests/README.md`,
  `tests/mutation/README.md`) with `grep -n`/`sed -n` around what you need.
- Read agent transcripts, `~/.codex/sessions`, `~/.claude/projects` or saved tool outputs only in a subagent
  that returns a short summary. Subagent reports: at most ~2,500 characters — verdict first, then only
  failures or decisions with `file:line`, then paths. Commit messages and the plan carry the details.
- Start a fresh session (or `/clear`) between unrelated tasks; leave a short handoff note first.

## Git

- Work on a branch. Keep commits focused: one fix, its tests and its baseline updates together. Parallel agents
  use separate `git worktree`s. Never use `git stash`, which is shared across worktrees.

## Where to look

| Path | Role |
|---|---|
| `public/` | Document root: only the entry point `index.php` (it `chdir()`s to the repository root, which holds the code, `constants.php` and `files/`), `style.css`, `js/`, `icons/` |
| `public/index.php` | Entry point: login/logout, `checklogin()`, requires every page file, dispatches on `?action=` (default: report) |
| `functions.php` | Shared helpers: `acc_header`/`acc_footer`, `getfilter`, `getsort`, `rights2orgasql` (rights to SQL) |
| `src/db.php` | Database helpers `db_query()`, `db_exec()`, `db_next_value()` (die with the pages' message on failure and log the calling page's file:line); outside the document root; loaded by `index.php` after `functions.php` |
| `src/format.php` | Formatting and parsing helpers: `amount_cents()` (exact amount parsing), `format_date()`, `format_amount()` (cents as `12.34` plus a unit); loaded by `index.php` after `src/db.php` |
| `new.php`, `edit.php` | Voucher workflow: create, edit, approve (acks), merge, revisions (finalization removed) |
| `report.php`, `vreport.php`, `tinyreport.php` | Financial reports (public report is the default page) |
| `import.php`, `import2.php` | BAWAG bank CSV import |
| `getusers.php` | Membership-system interface (member list lookup) |
| `internal/mbapi.php`, `src/sync-api.php` | Internal sync endpoint: payments as JSON after a peer and token check against `sync-secrets.php` (release root, like `constants.php`); `internal/` is the document root of the internal nginx listener (off unless configured); tests `tests/http-sync.php`, `tests/unit/SyncApiTest.php` |
| `file.php` | Encrypted receipt download (helpers in `functions.php`, phpseclib): `files/<n>.v2.aes` (AES-256 + HMAC, keyed on `$key` and file number), legacy `files/<n>.aes` (mcrypt-compatible) |
| `migrate-receipt-key.php` | Command-line migration of legacy receipts to `<n>.v2.aes` in two phases (404 over HTTP); runbook in `tests/COMPATIBILITY_PLAN.md` |
| `check.php` | Command-line voucher number check of one account, `php check.php <account> <max>` (404 over HTTP) |
| `table-schema.txt`, `tests/fixtures/` | Schema notes; test schema and synthetic data |
| `tests/run.sh`, `compose.test.yaml`, `compose.coverage.yaml`, `tests/php-runtime.Dockerfile` | Harness entry point, Compose files, PHP 8.5 image |
| `tests/dev.sh`, `compose.dev.yaml`, `tests/dev/` | Local development loop (bind-mounted checkout, per-file state reset, no coverage) |
| `tests/http-*.php`, `tests/write-workflows.php`, `tests/unit/` | HTTP, write-workflow and PHPUnit tests |
| `tests/web-server/` | Request matrix of the document root (`matrix.php`, also run by `http-docroot.php`) and `run.sh`: real nginx (rendered site template) and Apache (`public/.htaccess`, UPGRADING.md virtual host) |
| `tests/coverage/`, `tests/mutation/`, `tests/static-analysis/` | Coverage gate, mutation runners, analysis plus baselines |
| `tests/reference/` | Golden master `php85/` (recorded on PHP 8.5 x PG 18, diagnostics included), `run.sh record` / `run.sh compare 8.5`, `allowlist.php` (empty; only for real nondeterminism) |
