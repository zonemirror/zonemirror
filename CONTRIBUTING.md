# Contributing

Thanks for taking the time to contribute. This project follows a small set of rules to keep the
codebase reviewable.

## Ground rules

1. **One responsibility per file.** PHP classes, enums, value objects each get their own file under
   `src/`. No "utils" or "helpers" buckets.
2. **Layers:** `Interface -> Application -> Domain`. `Infrastructure` may be referenced from any
   layer but `Domain` and `Application` should not depend on each other's implementations.
3. **No secrets in code, logs, or tests.** Run grep for `Bearer`, `token`, or 40-char identifiers
   before pushing.
4. **Hooks must never crash cPanel.** Anything thrown from a `bin/on_*` script is caught and logged;
   do not re-introduce uncaught exceptions there.
5. **Idempotency keys are deterministic.** If you add fields to the cPanel payload extractor, also
   extend `HookPayloadParser::idempotencyKey` so retries collapse correctly.

## Quality gate

Before opening a PR, run:

```bash
composer install
composer check          # lint + phpstan + phpunit
make format             # PHP + shell + prettier
```

CI runs the same commands on PHP 8.1, 8.2, and 8.3.

## Adding a new record type

1. Add the variant to `src/Domain/RecordType.php`.
2. Add a `match` arm in `src/Infrastructure/Mapping/CpanelToCloudflareMapper.php`.
3. Add comparator logic in `src/Infrastructure/Cloudflare/RecordMatcher.php` if the record uses
   structured `data` instead of plain `content`.
4. Add a test in `tests/Unit/Infrastructure/Mapping/CpanelToCloudflareMapperTest.php`.

## Reporting bugs

Open a GitHub issue with:

- cPanel/WHM version
- PHP version
- Excerpt of `/var/cpanel/cloudflare-dns-sync/logs/cf-sync.log` (tokens are auto-redacted by the
  logger, but double-check)
- Minimal reproduction steps

## Security issues

Do **not** open a public issue for security problems. See [`SECURITY.md`](SECURITY.md) for the
private disclosure path.

## Commit conventions

- One logical change per commit.
- Imperative subject line (e.g., `Fix idempotency key for SRV records`).
- Reference issue numbers in the body when applicable.

## License

By contributing, you agree your contribution is released under the MIT license that covers this
project.
