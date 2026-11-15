# TODO Log

> Searchable record of closed project work. Active work lives in `TODO.md`.

<!-- tadu:log v1 since 2026-10-12 -->

## Moved from TODO.md (2026-10-12)

- [x] <!-- daily-tasks:COMMIT_FAIL --> **COMMIT_FAIL** (first seen 2026-09-12, last seen
      2026-09-12): the upgrade is staged but the commit hook rejected it (after 0 TS4111 rewrites).
      Decisive line: `✗ Prettier failed. Fix with: npm run format:prettier`. Re-run:
      `bash ~/p/bin/daily/ncu-update-repo.sh ~/p/zonemirror /tmp/logs`.

## 2026

### 2026-11

- [x] 2026-11-15 — **CI:** Fix 19 AdminTokensControllerTest failures on main since 2026-10-13.
  - Result: `AdminTokensController` read `posix_geteuid()` directly, so tests passed only as root.
    The root check is now an injectable closure (default unchanged); tests inject it and a non-root
    test was added.
  - Evidence: `composer run test` as uid 501 passes (695 tests), lint:php, analyse, prettier and
    shellcheck clean locally.

### 2026-10

- [x] 2026-10-21 — **Daily round:** Resolved 2026-09-12: prettier tripped on the untracked
      `.serena/project.yml`; `.serena/` is in .prettierignore and the staged upgrade went in as
      473cc9f, pushed.
  - Result: Verified the existing fix and removed the stale open reminder.
  - Evidence: On 2026-10-21, Prettier --file-info .serena/project.yml returned ignored: true;
    .prettierignore contains .serena/ and git merge-base --is-ancestor 473cc9f HEAD exited 0.
