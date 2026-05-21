# Security Policy

## Supported versions

Only the latest minor release line receives security patches.

## Reporting a vulnerability

**Do not open a public GitHub issue.** Email `security@zonemirror.com` with:

- A description of the issue and its potential impact.
- Steps to reproduce or a proof-of-concept.
- Your suggested mitigation, if any.

We will acknowledge within **3 business days** and aim to ship a fix within **14 days** for
high-severity issues.

If we agree the issue is valid, we credit you in the release notes unless you prefer to stay
anonymous.

## Disclosure

We follow coordinated disclosure: please give us a reasonable window (usually 14-30 days depending
on severity) before public disclosure.

## Hardening summary

- API tokens are encrypted at rest (`ConfigCrypto`, libsodium AEAD with OpenSSL AES-256-GCM
  fallback).
- Logs are passed through `TokenRedactor` before write.
- Hooks run with the cPanel user's privileges; the daemon runs as root via systemd with a hardened
  unit (`NoNewPrivileges`, `ProtectHome=read-only`, `MemoryDenyWriteExecute`).
- All UI forms validate a CSRF token with `hash_equals`.
- Both UI pages set a strict `Content-Security-Policy` (no inline JS, no remote origins).

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the layered design and trust boundaries that
frame these mitigations.
