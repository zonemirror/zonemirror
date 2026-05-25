# ZoneMirror v0.2 redesign — multi-tenant Cloudflare integration

> Status: design, work in progress. Tracks the redesign from v0.1's
> "every cPanel user pastes their own token" model to a mainstream
> product where the server admin configures Cloudflare once and end
> users connect domains with a single click.

## Why

The v0.1 UI asks every cPanel user to:

1. Understand what a Cloudflare API token is.
2. Generate one with the right scopes.
3. Paste it into a form.
4. Type their domain (which the server already knows).
5. Tick "Enable real-time sync".

That's five technical decisions for what should be one button. Most
cPanel hosting customers don't run their own Cloudflare account; their
host does. The server admin already has the Cloudflare tokens that cover
those domains. Making the customer reproduce that knowledge is friction
that we can remove.

The new model treats the server admin as the primary configurer and the
end user as a consumer with at most one decision to make: "yes, connect
this domain". Power users keep a path to bring their own token.

## Concepts

**Admin token** — a Cloudflare API token configured by the WHM admin.
Scoped to one or more Cloudflare accounts/zones. Stored encrypted under
`/var/cpanel/zonemirror/admin-tokens.json` (root, 0600). Decrypted only
by the daemon (root) and by the WHM admin UI (root via cpsrvd).

**Zone index** — a SQLite table at
`/var/cpanel/zonemirror/zone-index.sqlite` (root, 0644 so user-side PHP
can read it) listing every Cloudflare zone reachable through any admin
token, plus the token id that resolves it. Rebuilt periodically by the
daemon and on demand whenever a token is added.

**cPanel domain** — a domain attached to a cPanel account. Enumerated
via UAPI `DomainInfo::list_domains` (user-side) for the UI, or via WHM
API1 `listaccts` + `/var/cpanel/userdata/<user>/` (root-side) for the
indexer when it needs to match domains to zones.

**Domain status** — one of:

- `connected`     — domain matches a zone in the index, sync is on.
- `available`     — domain matches a zone, sync not yet started.
- `not-in-zone`   — domain has no matching zone in any admin token.
- `manual`        — admin disabled auto-sync for this domain.
- `user-token`    — domain is being synced via the user's own token
                    (case 2 below), not an admin token.

**Sync** — for a connected domain, the daemon reconciles the local
cPanel DNS zone (read with WHM API1 `dumpzone`) against the Cloudflare
zone, proposing creates/updates/deletes one record at a time. Nothing
is overwritten without an explicit accept (see Conflicts).

## Cases

### Case 1 — admin global (primary)

Admin adds N tokens in WHM. Indexer enumerates zones. cPanel user
opens the plugin, sees one row per domain with a "Connect" button if
the domain is in the index. Click → daemon takes it from there.

This is the default flow and the only one most customers see.

### Case 2 — user-owned token (advanced)

The cPanel user has their own Cloudflare account. The UI offers a
"Use my own Cloudflare account" link that takes them to a form where
they paste a token. The plugin verifies it, lists the zones it can
reach, and proposes the ones that match their cPanel domains.

Behaves like case 1 from there on, but the token is stored encrypted
in `~user/.zonemirror/config.json` (the v0.1 layout).

### Case 3 — per-zone token (advanced)

A variant of case 2 where the user pastes a token scoped to exactly
one zone. UI is identical; the difference is invisible to the user.
The "narrowest token wins" precedence is implemented in
`ZoneResolver::resolveFor($user, $domain)`.

### Case 4 — manual (fallback)

For a domain that doesn't match any admin or user token, the UI shows
a list of the records cPanel currently has locally and a banner: *"To
mirror this domain manually, paste these records into your Cloudflare
zone."* The daemon also runs a periodic verifier that queries CF's
public DNS and tells the user whether the records are in sync; that's
the only piece of "fidelity" we keep for the manual path.

## Resolution precedence

When the daemon processes an event for `(user, domain)`:

1. If the user has set up a `user-token` (case 2/3) that covers this
   domain, use it.
2. Else if any admin token covers this domain (case 1), use the
   narrowest-scoped one.
3. Else mark the domain `manual`; no daemon action.

The resolution is computed at event-handling time, not at enrollment
time, so admin token changes flow through without per-user reconfig.

## Data model

```
/var/cpanel/zonemirror/
  master.key             0600 root  AEAD key for admin secrets
  admin-tokens.json      0600 root  [{id, name, ciphertext, scope_hint, added_at, last_verified_at}]
  zone-index.sqlite      0644 root  zones(id, cf_zone_id, name, admin_token_id, cf_account_id, last_seen_at)
  system.json            0644 root  global config (dry_run, allowed_users, rate limit)
  enrolled-users         0644 root  list of cPanel users that opted in
  logs/zonemirror.log    0644 root  daemon log

/home/<user>/.zonemirror/
  master.key             0600 user  AEAD key for THIS user's secrets only
  config.json            0600 user  {connections: [{domain, source: admin|user, zone_id, enabled, ...}], user_token_ciphertext?}
  queue.sqlite           0600 user  hook → daemon event queue
  log.txt                0600 user  user-facing log
```

`config.json` schema is a list of "connections" instead of v0.1's single
`{zone_id, zone_name, token, enabled}`. v0.1 configs migrate to one
connection of `source: user`.

## Conflicts

When the daemon syncs a domain for the first time it builds a diff:

```
Local cPanel zone   ←→   Cloudflare zone
A    @     1.2.3.4       A   @    9.9.9.9
MX   @     ASPMX...      (missing)
TXT  @     v=spf1...     TXT @    v=spf1 different
```

Each row is one *proposal*. The UI renders the diff with three actions
per row: `accept` (apply to CF), `ignore` (mark as deliberate
divergence), `edit` (modify the proposal before applying). Nothing
is applied automatically on first connect; the user has to accept
the bundle once. Subsequent local DNS edits flow through the normal
hook/daemon path because the user has already accepted the policy.

## Compatibility with v0.1

Things that survive:

- The per-user AEAD key under `~user/.zonemirror/master.key`. Case 2
  reuses it verbatim.
- The Zone Editor hooks, the daemon, the dry-run flag, the allowlist.
- The systemd units and the auto-update timer.

Things that change shape:

- `~user/.zonemirror/config.json` gets a richer schema; a one-shot
  migrator detects v0.1 shape (`token` + `zone_id` at the top level)
  and rewrites it as `connections: [{source: 'user', ...}]`. No data
  loss.
- The cPanel UI is rebuilt around the connection list. The old
  "paste token + paste domain" form moves to a `+ Use my own Cloudflare
  account` link.
- A new WHM UI replaces the v0.1 admin page (which only carried
  dry-run + allowlist toggles). The new page is the home for admin
  tokens, zone index status, and per-domain conflict review.

## Milestones

**M1 — admin token storage + zone indexer**

- `AdminToken` value object + `AdminTokenStorage` (encrypted JSON list).
- `ZoneIndex` SQLite + read/write APIs.
- `IndexZonesJob` (daemon-side, root) that walks every admin token's
  `/zones` endpoint and rebuilds the index.
- WHM UI for add/list/remove tokens, with verify-on-save against CF.
- Status pill per token: `ok` / `unauthorized` / `expired` /
  `partial-scope`.
- Done = admin can add a token in WHM, see "12 zones indexed, ok",
  and the SQLite file shows the rows.

**M2 — cPanel "Connect domain" 1-click**

- Plugin home renders one row per cPanel domain of the calling user
  (UAPI `DomainInfo::list_domains`).
- Each row shows the domain status against the zone index.
- One-click `Connect` for `available` domains: writes a connection
  entry to `~user/.zonemirror/config.json` with `source: admin`, no
  token paste.
- "Use my own Cloudflare account" link routed to a kept-but-de-emphasized
  case-2 form.
- Done = customer with a domain in an indexed zone can connect it
  without typing anything.

**M3 — A/MX auto-sync from local cPanel DNS**

- `LocalZoneReader` reads via WHM API1 `dumpzone` (root) and
  normalises to ZoneMirror's DnsRecord domain object.
- On first connect, the daemon runs a `ReconciliationProposal`
  (current CF zone vs current local zone) and stores the diff.
- Only A and MX records in this milestone; other types are flagged
  `out-of-scope-for-now`.
- Done = connecting a domain produces a queued proposal visible in
  the UI, dry-run logs `would create/update/delete N records`.

**M4 — conflict review UI (per-record accept/ignore/edit)**

- Diff rendering in the cPanel UI.
- Accept → enqueue events to the existing hook queue.
- Ignore → write a divergence marker so the proposal isn't
  re-surfaced on next sync.
- Edit → inline form bound to a single DnsRecord.
- Done = customer can resolve a non-trivial diff without typing
  record syntax.

**M5 — email stack (SPF / DKIM / DMARC) auto-propose**

- Read DKIM via UAPI `EmailAuth::fetch_dkim_validity` (key material
  via `EmailAuth::install_dkim_keys` if missing).
- SPF: take whatever the cPanel zone has at `@ IN TXT v=spf1 ...`.
- DMARC: propose a sensible default (`p=none` rua=...@<domain>`) if
  none exists; never overwrite a stricter policy.
- Goes through the M4 conflict UI.

**M6 — case 2 with auto-detected domain + case 4 verifier**

- Case 2 form drops the "type your domain" field; we list the user's
  cPanel domains and they tick the ones the pasted token covers.
- Case 4 daemon job: for `manual` domains, periodically query CF's
  public resolver and write a `last-checked` + `in-sync?` status the
  UI surfaces.
- Done = no path in the UI asks the user to type a domain.

## Out of scope for v0.2

- Multi-account inside a single admin token (CF tokens are per-account
  by design; we just store multiple tokens).
- Reseller-aware admin tokens (cPanel resellers don't see WHM > Plugins
  by default; revisit in v0.3).
- IPv6/AAAA sync (likely in M3.5 once the diff machinery is settled).
- DNSSEC. Cloudflare manages it; we surface its status but don't
  touch it.
