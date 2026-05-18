# Performance Notes

## Targets

- Hook overhead: **< 50 ms** added to each cPanel DNS edit (perceived as
  instant by the cPanel UI).
- Daemon throughput: **>= 30 events/sec/user** sustained against Cloudflare
  (Cloudflare's per-token limit is 1,200 reqs / 5 minutes -> ~4 rps, so the
  bottleneck is the upstream rate limit, not us).
- Daemon resident memory: **< 30 MB**.

## Hot path

1. Hook receives JSON on stdin (< 4 KB typical).
2. `HookHandler::handle` decrypts the user token (~1 ms, OpenSSL/sodium).
3. `CpanelToCloudflareMapper::map` is pure CPU, ~50 µs.
4. `SqliteQueue::enqueue` is one `INSERT OR IGNORE` against a WAL-journaled
   SQLite (~1 ms typical).

Total: well under the 50 ms budget on commodity hardware.

## Where time goes inside the daemon

| Step                                            | Typical | Notes                                          |
| ----------------------------------------------- | ------- | ---------------------------------------------- |
| `SqliteQueue::claim`                            | < 2 ms  | One short transaction.                         |
| `CloudflareApiClient::listRecords` (filtered)   | 80-200 ms | TLS handshake amortized via cURL handle reuse. |
| `RecordMatcher::findEquivalent`                 | < 100 µs | In-memory scan over filtered results.          |
| `CloudflareApiClient::{create|update|delete}`   | 80-200 ms | One round trip.                                |

`listRecords` is the dominant cost. We always filter by `type` and `name` so
the response is at most a handful of records (typically 1-3).

## Connection reuse

`CloudflareApiClient` keeps a single `CurlHandle` for the lifetime of the
object. The worker creates a fresh client per user per cycle so the TLS
handshake is paid once per cycle, not per request. With the default `sleep=2s`
between idle cycles, that handle stays warm long enough for batches to land
on the same TLS session.

## SQLite tuning

`PRAGMA journal_mode = WAL`
`PRAGMA synchronous = NORMAL`
`PRAGMA busy_timeout = 5000`

WAL enables non-blocking readers while the writer holds the lock; `NORMAL`
synchronous gives durability across power loss (the only loss window is the
last few committed transactions in WAL — acceptable because the upstream
Cloudflare state can always be re-derived from cPanel).

`busy_timeout` prevents `SQLITE_BUSY` from bubbling up under contention.

## Rate-limit awareness

- `CloudflareApiClient` parses `X-RateLimit-Remaining` and `Retry-After`.
- `BackoffPolicy::nextDelaySeconds` uses **full jitter**
  (`random_int(1, 2^attempts)`), capped at 10 minutes, max 8 attempts before
  dead-letter.
- WHM admin can set a global `rate_limit_rps` budget (1-50) and toggle
  `dry_run` as a kill switch during incidents.

## Cycle-level optimizations applied

- **Zone snapshot per user per cycle.** `ZoneSnapshot` does one full
  `listRecords` call when a user has pending events, then ProcessEvent looks
  up matches in-memory and mutates the snapshot after each successful
  create/update/delete. A 50-event burst goes from 100 Cloudflare requests
  (50 list + 50 mutate) to 51 (1 list + 50 mutate).
- **SqliteQueue cached per user across cycles.** A single PDO + WAL setup
  per user lives for the daemon's lifetime instead of being torn down every
  2 seconds.
- **System config + enrolled-users TTL.** Re-read every 30 seconds rather
  than on every loop pass. Hot path no longer touches the filesystem.
- **`rate_limit_rps` enforced.** `WorkerLoop` `usleep`s `floor(1e6 / rps)`
  microseconds between Cloudflare-bound calls, honoring the WHM admin's
  budget instead of just storing it.
- **Retry-After honored.** When Cloudflare returns 429 with a Retry-After
  header, the queue waits at least that long before next claim.

## Known not-yet-optimized paths

- `Mapper` allocates a fresh closure per `match` arm — measured to be
  negligible (< 5 µs) but flagged here for future awareness.
- No batching of Cloudflare requests: the API supports list-and-replace style
  bulk edits in newer versions. Worth revisiting if a single user routinely
  enqueues > 100 events per cycle.
