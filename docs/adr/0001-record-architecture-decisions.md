# ADR-0001 — Record architecture decisions

We record the load-bearing decisions taken during the rewrite from a one-shot Python script into a
multi-tenant cPanel plugin.

## Status

Accepted (initial release).

## Context

The original `cloudflare.py` script did a one-way export from a single cPanel zone JSON dump to a
single Cloudflare account, hard-coded against `CF_API_TOKEN` and `CF_ZONE_ID` environment variables.
It had no concept of hooks, multi-tenant, retries, or audit. Rewriting it as a proper cPanel plugin
required deciding:

1. Plugin scope (per-user vs WHM-only vs hybrid)
2. Language version target
3. Sync direction
4. Storage layout
5. Event-processing model

## Decisions

### D1: Hybrid scope (WHM admin defaults + cPanel user opt-in)

The original scaffold mixed concerns: hooks ran as the cPanel user but config sat at
`/root/.cf-sync/config.json`. We split this:

- **WHM admin** controls global defaults, an allowlist of users that may enable the plugin, the
  Cloudflare rate-limit budget, and a dry-run kill switch (`SystemConfigStorage`).
- **cPanel user** pastes their own scoped API token and selects a zone. Their token never reaches
  another user's account; storage lives under their home directory with mode 0700
  (`UserConfigStorage`).

Trade-off: two UIs and two storage layers. Worth it because hosting-shared servers need both shapes
of control.

### D2: PHP 8.1+

cPanel 108+ ships PHP 8.x by default. We use `readonly` properties, enums, typed properties,
intersection types. Dropping PHP 7.4 support removed a large category of defensive coding.

### D3: cPanel -> Cloudflare one-way + manual import

Bidirectional sync would require Cloudflare webhooks landing on a public-internet endpoint and a
long-running reverse hook into cPanel. The risk and operational surface aren't justified for v1. A
manual "Import from Cloudflare" path is scaffolded for first-time use
(`ImportFromCloudflare::listRemote`).

### D4: Per-user SQLite queue

Alternatives considered:

- **Shared SQLite at `/var/cpanel/...`**: would require either group `cpanel` with mode 0660 (risky
  on shared hosts) or a setuid wrapper.
- **UNIX socket to a root daemon for enqueue**: cleaner privilege story but a full bidi RPC layer is
  overkill for an INSERT.
- **systemd-journald + a custom reader**: ties us to a single transport.

A per-user SQLite file at `~/.zonemirror/queue.sqlite` with WAL journaling gives durable,
isolated queues that the hook (running as the user) can write and the root daemon can read. The
price is iterating `EnrolledUsers::all()` in the daemon loop instead of one shared SELECT.

### D5: Idempotency keys instead of UPSERT-by-content

cPanel sometimes retries hook scripts on transient errors. Without dedup, a single user edit could
land in Cloudflare twice (with different IDs but same content). A SHA-256 of
`(action, domain, type, name, content, priority, port)` is used as a `UNIQUE` constraint with
`INSERT OR IGNORE`; duplicates silently coalesce.

### D6: Atomic claim via BEGIN IMMEDIATE + visibility timeout

SQLite lacks `SELECT FOR UPDATE`. Two daemon invocations (e.g. on restart during catch-up) could
otherwise race to claim the same row. We wrap claim in `BEGIN IMMEDIATE` (acquires the write lock up
front) and advance `next_run_at` by a configurable visibility timeout before commit.

### D7: Encrypted-at-rest tokens with rotation header

Tokens are encrypted with libsodium XChaCha20-Poly1305 if available, else AES-256-GCM. The binary
blob is prefixed with a 1-byte version so the algorithm can be rotated without breaking existing
configs.

## Consequences

- We carry an extra daemon (one process, ~30 MB) over a pure cron approach.
- Per-user state under home dirs survives `uninstall.sh` unless `--purge` is passed. Documented in
  the README.
- Adding a new record type touches three files (RecordType, Mapper, RecordMatcher) plus tests.
  Documented in CONTRIBUTING.md.
