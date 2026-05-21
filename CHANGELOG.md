# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-05-22

### Added

- One-line installer (`packaging/bootstrap.sh`) — curl-able from GitHub raw, resolves the latest
  release tag, verifies SHA-256, stages under `/opt/zonemirror/releases/<version>/`, swaps the
  `/opt/zonemirror/current` symlink, and hands off to `packaging/install.sh`.
- `zonemirror` operator CLI installed to `/usr/local/bin/zonemirror`. Subcommands: `version`,
  `check`, `update [--dry-run]`, `status`, `auto-update on|off`, `logs [-n N]`, `help`.
- Auto-update systemd timer (`zonemirrord-updater.{service,timer}`) — disabled by default, enabled
  with `sudo zonemirror auto-update on`. Daily with 0-3 h randomized delay; persistent across boots;
  honors release-tarball SHA-256.
- `VERSION` file at the repo root as the single source of truth; release workflow rewrites it to the
  git tag at build time.
- `VersionReader` infrastructure component, surfaced on the WHM admin UI as a version pill next to
  the page title.
- README rewritten: one-liner install, comparison table, mermaid flow diagram, configuration
  reference, diagnostics quick-ref, FAQ, roadmap.

### Added (initial release)

- Hybrid WHM-admin + cPanel-user configuration model with allowlist and dry-run kill switch.
- Per-user SQLite event queue with WAL journaling, atomic claim, idempotency keys, exponential
  backoff with jitter, and dead-letter tracking.
- Cloudflare API client with cURL handle reuse, paginated listings, Retry-After and
  X-RateLimit-Remaining parsing, typed exceptions.
- Standardized hooks for `ZoneEdit::add_zone_record`, `edit_zone_record`, `remove_zone_record`, and
  `mass_edit_zone`.
- WHM admin LiveAPI page with global defaults, allowlist, and dry-run toggle.
- cPanel user LiveAPI page with CSRF, connection test, queue depth, and dead-letter count.
- Systemd unit with hardening directives.
- PHPUnit suite (30 tests) and PHPStan level 8 + strict rules.

### Security

- Cloudflare API tokens encrypted at rest with XChaCha20-Poly1305 (sodium) or AES-256-GCM (OpenSSL
  fallback). Master key stored root-only at `/var/cpanel/zonemirror/master.key`.
- All log output passes through `TokenRedactor`.
- Constant-time CSRF verification (`hash_equals`) with rotation on success to prevent token replay.
- `_acme-challenge` and `_dmarc` records are never proxied.
- Hook privilege separation: cPanel-user hooks never read the root-owned master key; only the root
  daemon decrypts tokens.

### Reliability

- `rate_limit_rps` from the WHM admin UI is enforced as an inter-call sleep in the worker (was
  previously read but never honored).
- `Retry-After` and `X-RateLimit-Remaining` from Cloudflare 429 responses feed back into the queue's
  retry delay.
- Zone snapshot cached per user per cycle so a 50-event burst costs 1 list + 50 mutate API calls
  (was 50 list + 50 mutate).
- SqliteQueue (and its PDO + WAL setup) cached across worker cycles.
- System config and enrolled-users list reloaded with a 30 s TTL instead of on every loop pass.

### Fixed

- Standardized hook registration now uses `--event Uapi::ZoneEdit::*` (and `Api2::ZoneEdit::*` as a
  fallback for legacy callers). The previous `--hookpoint "uapi=..."` formulation was rejected by
  cPanel's `manage_hooks`, so no hook ever fired.
- Removed the `'curl_' . 'exec'` string-split workaround in `CloudflareApiClient`; SAST scanners no
  longer flag the file as intentionally-obfuscated.

## [0.1.0] - Initial scaffold (pre-rewrite)

- Initial single-user Python sync script and prototype cPanel hooks.
