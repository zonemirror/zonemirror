# Cloudflare DNS Sync for cPanel

A cPanel plugin that automatically syncs DNS changes from cPanel's Zone Editor to Cloudflare. Users paste a scoped Cloudflare API Token once, select the zone, set defaults (proxied vs DNS-only), and enable. The plugin registers cPanel hooks, enqueues events, and a background worker applies them to Cloudflare with retries and logging.

## Features (v1)
- Zero-touch sync from cPanel → Cloudflare for common record types
- Per-domain settings UI with token, zone selection, defaults, and enable toggle
- Background worker with queue, retries, and idempotency
- One-time import (Cloudflare → cPanel) with diff preview (scaffolded)

## Layout
```
cpanel-cloudflare-sync/
├─ plugin/
│  ├─ install.sh, uninstall.sh, cloudflare_sync.cpanelplugin
│  ├─ ui/
│  ├─ api/
│  ├─ hooks/
│  ├─ worker/
│  ├─ bin/
│  ├─ etc/systemd/
│  └─ resources/
├─ tests/
├─ composer.json
└─ README.md
```

## Requirements
- PHP 7.4+ (8.x supported)
- cURL, JSON, PDO_SQLite extensions
- cPanel v104+ (Jupiter theme)

## Development
- Edit PHP under `plugin/`
- Autoload via Composer PSR-4
- Unit tests under `tests/`

## Security
- Uses API Tokens (least privilege) — not Global API Key
- Token encrypted at rest under `~/.cf-sync/config.json`
- Logs redact secrets; no plaintext token written

## License
MIT
