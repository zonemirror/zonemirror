# TODO

States: `[ ]` pending, `[~]` partial, `[!]` blocked, `[x]` done, `[-]` obsolete.

## Daily round

Filed by `~/p/bin/daily`; one bullet per finding, updated in place while it repeats.

- [x] <!-- daily-tasks:COMMIT_FAIL --> **COMMIT_FAIL** (first seen 2026-09-12, last seen
      2026-09-12): the upgrade is staged but the commit hook rejected it (after 0 TS4111 rewrites).
      Decisive line: `✗ Prettier failed. Fix with: npm run format:prettier`. Re-run:
      `bash ~/p/bin/daily/ncu-update-repo.sh ~/p/zonemirror /tmp/logs`.
- [ ] Resolved 2026-09-12: prettier tripped on the untracked `.serena/project.yml`; `.serena/` is in
      .prettierignore and the staged upgrade went in as 473cc9f, pushed.

## Routed from `~/p/TODO.md` (2026-10-03)

Moved verbatim from `~/p/TODO.md` on 2026-10-03; the routing table in `~/p/TODO_LOG.md` (entry of
that date) records each move.

- [ ] **Dependabot #3 `actions/setup-node` 6 to 7** open since 2026-07-16 (review requested), still
      open 2026-10-03. Merge or close. Split out of a cross-repo Dependabot line in `~/p/TODO.md`.
