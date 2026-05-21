# Installing ZoneMirror from source

Use this guide when you want to run ZoneMirror on a cPanel/WHM server from a specific branch, pull
request, or commit — anything that does not yet have a release tag.

The result is a working install indistinguishable from one done through the one-line bootstrap
script: same paths, same systemd units, same cPanel and WHM UI integrations, same hooks. The only
difference is that you control the source tree directly, which makes it possible to iterate on a fix
without cutting a release.

If you are looking for the normal install path, see the [Install](../README.md#install) section of
the README.

## Table of contents

- [When to use this guide](#when-to-use-this-guide)
- [Safety belts you should rely on](#safety-belts-you-should-rely-on)
- [Prerequisites](#prerequisites)
- [Install from source](#install-from-source)
- [Configure a safe test environment](#configure-a-safe-test-environment)
- [Live iteration loop](#live-iteration-loop)
- [Debugging cheatsheet](#debugging-cheatsheet)
- [Going from dry-run to live](#going-from-dry-run-to-live)
- [Uninstall](#uninstall)
- [Common pitfalls](#common-pitfalls)

---

## When to use this guide

- You want to test a branch or pull request on a real cPanel server before it is merged.
- You hit a bug in production and need to validate a fix against the actual hook flow without
  cutting a patch release first.
- You are developing a new record type, a new mapping, or a hook change and the only way to verify
  it end to end is to fire a real cPanel UAPI ZoneEdit event.

If your change is pure logic and covered by unit tests, run `composer check` locally and stop there.
This guide is for changes that touch the cPanel/WHM/systemd integration surface, which cannot be
reproduced with PHPUnit alone.

## Safety belts you should rely on

The plugin is designed so that a misconfigured or buggy build cannot silently corrupt a customer's
DNS. Three independent gates stand between any hook fire and an actual Cloudflare write:

1. **Global dry-run mode** (WHM admin toggle). When dry-run is on, hooks fire and events are queued,
   but the worker logs `DRY_RUN would …` lines instead of calling the Cloudflare API. **Turn this on
   before any other action**; turn it off only after you have watched it work on a non-production
   zone.
2. **Per-admin allowlist** (WHM). A cPanel user has to be explicitly added to the allowlist before
   any of their events leave their local queue. An empty allowlist means zero outbound traffic.
3. **Per-user opt-in token** (cPanel). Each allowlisted user has to paste their own Cloudflare API
   token in the cPanel UI. Without a token, their queue accepts events but nothing is sent.

Combine all three for the first install: dry-run on, allowlist empty, no tokens enrolled. From that
baseline, walk up one level at a time and check the logs between each step.

## Prerequisites

On the cPanel server:

- cPanel & WHM **108+** (Jupiter theme)
- PHP **8.1+** with `curl`, `pdo_sqlite`, `openssl` (and `sodium` if available)
- `systemd`
- `composer` available on `$PATH` (or commit `vendor/` into your test branch)
- Root access (the installer must run as `root`)

For the test traffic itself you also want:

- A test domain on the cPanel account, or a real domain you control where DNS drift is acceptable.
- A Cloudflare account that hosts that domain, with an API token scoped to exactly that zone
  (`Zone:DNS:Edit` plus `Zone:Zone:Read`). Do not reuse the token of a real customer for testing.

## Install from source

```bash
# As root on the cPanel server
cd /opt
git clone https://github.com/zonemirror/zonemirror.git
cd zonemirror
git checkout <branch-or-commit>          # optional; defaults to main

# Install runtime dependencies (no dev dependencies on the target server)
composer install --no-dev --prefer-dist --optimize-autoloader

# Run the installer (same script the release flow ends up running)
sudo bash packaging/install.sh
```

`packaging/install.sh` rsyncs the source tree to `/usr/local/cpanel/3rdparty/zonemirror/`, registers
the hooks against UAPI and Api2, installs the systemd units, drops the `zonemirror` CLI symlink in
`/usr/local/bin/`, and registers the cPanel plugin manifest. It is safe to re-run.

Sanity check the install without doing anything else:

```bash
sudo systemctl status zonemirrord
sudo zonemirror status
/usr/local/cpanel/bin/manage_hooks list | grep zonemirror
ls -la /var/cpanel/zonemirror/
ls -la /usr/local/cpanel/base/frontend/jupiter/zonemirror/
```

At this point the plugin is installed and the daemon is running, but the allowlist is empty and no
user has a token. Nothing reaches Cloudflare.

## Configure a safe test environment

1. **Enable dry-run globally.** In WHM, open `Plugins → ZoneMirror`, check `Dry-run mode`, and save.
   Verify the system config:

   ```bash
   sudo cat /var/cpanel/zonemirror/system.json
   ```

   You should see `"dry_run": true`.

2. **Tail the logs in two terminals.** Keep these visible the whole test.

   ```bash
   sudo tail -F /var/cpanel/zonemirror/logs/zonemirror.log
   sudo journalctl -u zonemirrord -f
   ```

3. **Enrol a single test user.** Pick a cPanel account that is not a real customer (for example,
   create `testzm`), add it to the WHM allowlist, log in as that user, open `Domains → ZoneMirror`,
   paste the test Cloudflare token, click `Test connection`, then `Enable`.

4. **Trigger an edit.** From the cPanel Zone Editor, add a record on the test zone (a TXT is the
   lowest-risk choice). Within two seconds you should see the daemon emit a `DRY_RUN would upsert …`
   line. No record is touched in Cloudflare.

5. **Inspect the queue.** Confirm the event made it into the user's SQLite queue and was claimed by
   the daemon:

   ```bash
   sudo sqlite3 /home/testzm/.zonemirror/queue.sqlite \
     "SELECT id, action, type, name, attempts, dead_at FROM events ORDER BY id DESC LIMIT 10;"
   ```

## Live iteration loop

Editing source on your laptop and re-deploying to the cPanel server can be done two ways depending
on how often you expect to iterate.

**Standard reinstall** (one or two iterations):

```bash
cd /opt/zonemirror
git pull
sudo bash packaging/install.sh
```

`install.sh` is idempotent. It rsyncs (with `--delete`), re-registers hooks (cPanel deduplicates
registrations), restarts the daemon, and resets file permissions.

**Symlink mode** (many iterations, hot-reload of PHP code):

```bash
sudo systemctl stop zonemirrord
sudo rm -rf /usr/local/cpanel/3rdparty/zonemirror
sudo ln -s /opt/zonemirror /usr/local/cpanel/3rdparty/zonemirror
sudo systemctl start zonemirrord
```

In symlink mode, `git pull` on `/opt/zonemirror` is reflected immediately on the next hook
invocation. The daemon also picks up new code on its next restart. Three caveats:

- Changes to `packaging/systemd/*.service` or `*.timer` need `sudo systemctl daemon-reload`.
- Changes to `packaging/register-hooks.sh` need a re-run of that script
  (`sudo bash /opt/zonemirror/packaging/register-hooks.sh`).
- When you are done debugging, run `uninstall.sh --purge` and reinstall from a clean clone to
  confirm the canonical install still works.

## Debugging cheatsheet

```bash
# Daemon
sudo systemctl restart zonemirrord
sudo journalctl -u zonemirrord -n 200 --no-pager

# Plugin
sudo zonemirror status
sudo zonemirror logs -n 200

# Per-user queue (replace testzm with the actual cPanel username)
sudo sqlite3 /home/testzm/.zonemirror/queue.sqlite \
  "SELECT id, action, type, name, attempts, dead_at FROM events ORDER BY id DESC LIMIT 20;"

# Drain pending count
sudo sqlite3 /home/testzm/.zonemirror/queue.sqlite \
  "SELECT COUNT(*) FROM events WHERE dead_at IS NULL;"

# Force retry of dead-letter rows
sudo sqlite3 /home/testzm/.zonemirror/queue.sqlite \
  "UPDATE events SET dead_at=NULL, attempts=0 WHERE dead_at IS NOT NULL;"

# System config (server-wide)
sudo cat /var/cpanel/zonemirror/system.json

# Per-user config (token is encrypted at rest, but other fields are clear)
sudo cat /home/testzm/.zonemirror/config.json

# Confirm hooks are wired
/usr/local/cpanel/bin/manage_hooks list | grep zonemirror

# Confirm cPanel and WHM UI surfaces are linked
ls -la /usr/local/cpanel/base/frontend/jupiter/zonemirror/index.live.php
ls -la /usr/local/cpanel/whostmgr/docroot/cgi/zonemirror/index.live.php
```

## Going from dry-run to live

When the dry-run output looks right and the queue drains cleanly:

1. Disable dry-run in WHM and save.
2. Confirm: `sudo cat /var/cpanel/zonemirror/system.json | grep dry_run` should show `false`.
3. Trigger the same edit again. You should now see `info` lines that include the Cloudflare record
   id, and you can verify the record exists in the Cloudflare dashboard for the test zone.
4. Only after that, add real customer accounts to the allowlist. Their first action is still gated
   by them pasting their own token in the cPanel UI.

## Uninstall

```bash
# Stop daemon, remove systemd units, unregister hooks, unregister cPanel plugin,
# remove install prefix. Leaves /var/cpanel/zonemirror/ and ~/.zonemirror/ in
# place so reinstall can recover state.
sudo bash /opt/zonemirror/packaging/uninstall.sh

# Same as above plus wipe /var/cpanel/zonemirror/ and every user's ~/.zonemirror/.
sudo bash /opt/zonemirror/packaging/uninstall.sh --purge
```

## Common pitfalls

**PHP version resolves to the wrong binary.** Many cPanel servers have an old `php` on `$PATH` and
the modern interpreter under `/usr/local/cpanel/3rdparty/bin/php`. The hook scripts use
`#!/usr/bin/env php`, so they pick whatever `php` resolves to. Check with
`head -1 /usr/local/cpanel/3rdparty/zonemirror/bin/on_add_zone_record` and
`/usr/local/cpanel/3rdparty/bin/php -v`. If the resolved interpreter is below 8.1, either fix
`$PATH` for the hook execution context or pin the shebang.

**`composer install` fails or no composer is on the server.** Either install composer on the box
(`curl -sS https://getcomposer.org/installer | php`) or commit a `vendor/` directory into your test
branch so `install.sh` finds an autoloader. The release tarball solves this by always shipping with
`vendor/` pre-generated.

**Hooks do not fire on Zone Editor edits.** Modern cPanel UIs route through UAPI, but a few legacy
editors and scripted callers still use Api2. The installer registers both, but if you see edits
without a corresponding queue row, list the hooks with
`/usr/local/cpanel/bin/manage_hooks list | grep zonemirror` and confirm both `Uapi::ZoneEdit::*` and
`Api2::ZoneEdit::*` variants are present.

**Daemon refuses to start on older distros.** The systemd unit uses `MemoryDenyWriteExecute`,
`ProtectKernelTunables`, and other hardening directives that older systemd versions may not
understand. Inspect the failure with `sudo journalctl -xeu zonemirrord` and, if needed, comment out
the offending directive in `/etc/systemd/system/zonemirrord.service` to identify which one is the
problem.

**SQLite file is owned by root after a hook crash.** Hook scripts run as the cPanel user, so the
user's queue file must be writable by that user. If a hook ran briefly as root (rare but possible
during an install glitch), the queue file ends up `root:root` and subsequent user-context hooks
silently fail to insert. Fix with `chown $USER:$USER ~/.zonemirror/queue.sqlite`.

**Cloudflare token is correct but `Test connection` fails.** The token needs `Zone:DNS:Edit` plus
`Zone:Zone:Read` scoped to the specific zones the user wants to mirror. Tokens scoped to "all zones"
of an account also work but are broader than necessary.
