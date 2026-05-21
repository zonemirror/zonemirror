# Architecture

A high-level read of how a single Zone Editor edit ends up at Cloudflare.

## Lifecycle of a DNS edit

```
cPanel user edits a record
       │
       ▼
ZoneEdit::add_zone_record  (UAPI)
       │  (post-API standardized hook)
       ▼
bin/on_add_zone_record           ← runs as the cPanel user
       │
       ▼
HookHandler::handle
  ├── UserConfigStorage::load    ← reads encrypted token, settings
  ├── SystemConfigStorage::isUserAllowed
  ├── HookPayloadParser::extract
  ├── CpanelToCloudflareMapper::map
  └── SqliteQueue::enqueue       ← writes to ~/.zonemirror/queue.sqlite
       │
       ▼  (out-of-band)
WorkerLoop::run                  ← systemd-supervised, runs as root
  for each enrolled user:
       ├── SqliteQueue::claim    ← atomic, BEGIN IMMEDIATE
       ├── ProcessEvent::handle
       │     ├── CloudflareApiClient::listRecords
       │     ├── RecordMatcher::findEquivalent
       │     └── CloudflareApiClient::{create|update|delete}Record
       └── SqliteQueue::{ack|fail}
```

## Layered dependencies

```
┌─────────────────────────────────────────────────────────────────┐
│ Interface (bin/, resources/, src/Interface/)                    │
│   Hooks, UI controllers, worker loop                            │
└─────┬───────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────┐
│ Application (src/Application/)                                  │
│   ProcessEvent, ImportFromCloudflare                            │
└─────┬───────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────┐
│ Domain (src/Domain/)                                            │
│   DnsRecord, DnsEvent, RecordType, EventAction, SyncResult      │
└─────────────────────────────────────────────────────────────────┘

      ┌────────────────────────────────────────────────────────┐
      │ Infrastructure (src/Infrastructure/)                   │
      │   Cloudflare client, SQLite queue, storage, logging    │
      │   (referenced from Interface and Application; never    │
      │    referenced from Domain)                             │
      └────────────────────────────────────────────────────────┘
```

## Filesystem map

| Path                                               | Owner     | Mode | Purpose                                  |
| -------------------------------------------------- | --------- | ---- | ---------------------------------------- |
| `/usr/local/cpanel/3rdparty/zonemirror/`  | root:root | 0755 | Plugin code + vendor/                    |
| `/var/cpanel/zonemirror/system.json`      | root:root | 0600 | WHM-admin defaults                       |
| `/var/cpanel/zonemirror/master.key`       | root:root | 0600 | 32-byte token-encryption key             |
| `/var/cpanel/zonemirror/enrolled-users`   | root:root | 0644 | Newline-separated list of opted-in users |
| `/var/cpanel/zonemirror/logs/zonemirror.log` | root:root | 0640 | JSON-lines log (token-redacted)          |
| `/home/<user>/.zonemirror/config.json`    | `<user>`  | 0600 | Per-user settings + encrypted token      |
| `/home/<user>/.zonemirror/queue.sqlite`   | `<user>`  | 0600 | Per-user pending DNS events              |
| `/etc/systemd/system/zonemirrord.service` | root:root | 0644 | systemd unit                             |

## Why these design choices

- **Per-user queue** (instead of one shared DB) isolates one user's failures from another's and lets
  hooks write to their own queue without elevating to root.
- **WAL journaling + BEGIN IMMEDIATE** lets the root worker and per-user hooks share the file
  safely; SQLite has no `SELECT FOR UPDATE`, so claim is expressed as an immediate-mode transaction
  with a visibility timeout.
- **Idempotency keys** built from `(action, domain, type, name, content, priority, port)` collapse
  duplicate hook fires (cPanel sometimes retries on transient hook errors).
- **Mapping is the only place that knows the cPanel payload shape.** All later layers consume
  `DnsRecord`, so adding a new transport upstream only touches `CpanelToCloudflareMapper`.

## See also

- [`adr/0001-record-architecture-decisions.md`](adr/0001-record-architecture-decisions.md)
- [`PERFORMANCE.md`](PERFORMANCE.md)
- [`../SECURITY.md`](../SECURITY.md)
