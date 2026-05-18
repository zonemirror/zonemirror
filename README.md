# Cloudflare DNS Sync for cPanel

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](composer.json)
[![cPanel 108+](https://img.shields.io/badge/cPanel-108%2B-orange.svg)](https://docs.cpanel.net/)

A production-grade cPanel/WHM plugin that mirrors every Zone Editor change to
Cloudflare in real time. Each cPanel user pastes a scoped Cloudflare API
token once, selects a zone, and toggles "Enable" — every subsequent
`add_zone_record` / `edit_zone_record` / `remove_zone_record` /
`mass_edit_zone` UAPI call is mirrored to Cloudflare by a background worker
with idempotency, retries, and dead-lettering.

> Replaces hand-rolled `cloudflare.py` scripts and cron jobs with a proper,
> multi-tenant, auditable plugin.

## Features

- **Real-time sync** of A, AAAA, CNAME, MX, TXT, SRV, CAA records via cPanel
  standardized hooks (`uapi=ZoneEdit::*`).
- **Hybrid scope:** WHM admin sets global defaults (proxied, TTL, allowlist,
  dry-run); each cPanel user opts in independently with their own scoped
  Cloudflare API token.
- **Encrypted-at-rest tokens** using libsodium XChaCha20-Poly1305 (falls back
  to AES-256-GCM via OpenSSL) with a 32-byte master key under
  `/var/cpanel/cloudflare-dns-sync/master.key` (root-only).
- **Per-user SQLite event queue** with WAL journaling, atomic claim, exponential
  backoff with jitter, idempotency keys (SHA-256 over action + domain + record
  shape), and dead-letter inspection.
- **Cloudflare client** with cURL handle reuse, paginated listings,
  Retry-After / X-RateLimit-Remaining parsing, typed exceptions.
- **Dry-run mode** (set in WHM) logs intended changes without calling
  Cloudflare — useful for first-time deployments and audits.
- **Token redaction** in all log output (Bearer headers, JSON fields,
  40+ char identifiers).
- **CSRF protection** with `hash_equals`-validated tokens on both the cPanel
  and WHM UIs; strict Content-Security-Policy on both pages.
- **Systemd-supervised daemon** with hardened unit (`NoNewPrivileges`,
  `ProtectHome=read-only`, `MemoryDenyWriteExecute`).

## Architecture

```
cpanel-cloudflare-dns-sync/
├── src/
│   ├── Domain/             # DnsRecord, DnsEvent, RecordType, EventAction, SyncResult
│   ├── Application/        # ProcessEvent, ImportFromCloudflare
│   ├── Infrastructure/
│   │   ├── Cloudflare/     # CloudflareApiClient, RecordMatcher, HttpResponse
│   │   ├── Mapping/        # CpanelToCloudflareMapper
│   │   ├── Queue/          # SqliteQueue, BackoffPolicy
│   │   ├── Storage/        # UserConfigStorage, SystemConfigStorage, ConfigCrypto, KeyStore
│   │   └── Logging/        # FileLogger, TokenRedactor, LogLevel
│   └── Interface/
│       ├── Hook/           # HookHandler, HookPayloadParser
│       ├── Ui/             # UserController, AdminController, Csrf
│       └── Worker/         # WorkerLoop
├── bin/                    # cf-syncd + four hook entry scripts
├── resources/
│   ├── cpanel/             # Jupiter LiveAPI template (cPanel user UI)
│   └── whm/                # WHM admin LiveAPI template
├── packaging/              # .cpanelplugin manifest, systemd unit, install / uninstall
├── tests/Unit/             # PHPUnit (Mapper, RecordMatcher, Queue, Crypto, Redactor, HookParser)
└── docs/                   # Architecture overview + ADRs
```

The layers respect a strict dependency direction: `Interface` -> `Application`
-> `Domain`; `Infrastructure` is the only layer that talks to the network,
filesystem, or SQLite.

## Requirements

- cPanel & WHM **108+** (Jupiter theme)
- PHP **8.1+** with `curl`, `pdo_sqlite`, `openssl` (and optionally `sodium`)
- Linux with systemd
- A Cloudflare **API Token** (not Global API Key) scoped to the zone(s) you
  want to mirror, with `Zone:DNS:Edit` and `Zone:Zone:Read`

## Quickstart

```bash
git clone https://github.com/BusiRocket/cpanel-cloudflare-dns-sync.git
cd cpanel-cloudflare-dns-sync
sudo bash packaging/install.sh
```

After install:

1. **WHM -> Plugins -> Cloudflare DNS Sync** to set global defaults / allowlist.
2. **cPanel -> Domains -> Cloudflare DNS Sync** for each enabled user to paste
   their token and pick a zone.
3. Check the daemon: `systemctl status cloudflare-dns-syncd`.
4. Tail logs: `tail -f /var/cpanel/cloudflare-dns-sync/logs/cf-sync.log`.

To remove (preserves config + queues for reinstall):
```bash
sudo bash /usr/local/cpanel/3rdparty/cloudflare-dns-sync/packaging/uninstall.sh
```

To fully purge:
```bash
sudo bash /usr/local/cpanel/3rdparty/cloudflare-dns-sync/packaging/uninstall.sh --purge
```

## Security model

- The Cloudflare API token never touches disk in plaintext. It is encrypted
  with a 32-byte master key (`/var/cpanel/cloudflare-dns-sync/master.key`,
  mode `0600`, root-only) using XChaCha20-Poly1305 (sodium) or AES-256-GCM
  (OpenSSL fallback).
- Tokens are redacted from every log line via `TokenRedactor` before write.
- Per-user state lives under each cPanel user's home
  (`~/.cloudflare-dns-sync/`), mode `0700`. Hooks running as the user can
  enqueue events without escalating; the daemon (root) reads them.
- `_acme-challenge` and `_dmarc` records are **never** proxied (would break
  ACME validation and DMARC reporting), regardless of WHM/user defaults.
- CSRF tokens on both UIs are validated with `hash_equals` (constant-time).
- Strict `Content-Security-Policy` on UI pages (no inline JS, no remote
  origins).

See [`docs/THREAT_MODEL.md`](docs/THREAT_MODEL.md) and
[`SECURITY.md`](SECURITY.md) for full disclosure policy and threat model.

## Development

```bash
composer install
composer test         # PHPUnit
composer analyse      # PHPStan level 8 + strict rules
composer lint:php     # php-cs-fixer dry-run
composer format:php   # php-cs-fixer apply
make format           # PHP + shell + prettier
```

### Reproducing a hook locally

```bash
echo '{"data":{"args":{"domain":"example.com"},"result":{"data":{"type":"A","name":"www.example.com.","address":"203.0.113.10","ttl":300}}}}' \
  | CFSYNC_USER_HOME=/tmp/cfsync-dev \
    php bin/on_add_zone_record
```

## Contributing

Pull requests welcome. Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) first
and ensure `composer check` is green before opening a PR.

## License

MIT — see [`LICENSE`](LICENSE).
