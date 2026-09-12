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
