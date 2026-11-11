# Upgrading

## Unreleased

## To 0.2.0

From **0.1.0**:

```bash
composer update nowo-tech/beacon-php
```

No action needed. Envelopes now go to `/api/ingest/v1/{projectId}/envelope/` (Beacon ≥ 1.147). Against an older
Beacon the first envelope answers 404 there and is re-sent once to `/api/{projectId}/envelope/`; that answer is
remembered for the process, so later envelopes need one request in a long-running worker.

- **PHP-FPM / short-lived processes against Beacon < 1.147:** the cache lives for one request, so every request that
  sends something costs one extra 404. Set `ingest_paths: legacy` until Beacon is upgraded.

- Pin the paths with `ingest_paths: legacy` (older Beacon, no probe at all) or `ingest_paths: versioned` (Beacon ≥ 1.147,
  never falls back).
- Firewalls or proxies that allow-list ingest URLs must allow `/api/ingest/v1/{projectId}/envelope/` too.
- After upgrading Beacon without restarting a long-running worker, the worker keeps the legacy alias (which keeps
  working); `IngestPathResolver::shared()->reset()` makes it probe again.
- `Dsn::getEnvelopeUrl()` still returns the legacy URL; use `Ingest\IngestPaths::envelopeUrl($dsn, true)` or the
  transport's `getEnvelopeUrl()` for the URL the next send starts with. Log records show the URL actually used.
- A custom `transport` is used as is: it chooses its own URL.

## To 0.1.0

First release; nothing to upgrade from.

### Coming from BeaconBundle

Symfony applications keep using `nowo-tech/beacon-bundle`; a future bundle minor will depend on this package
without behaviour change (see [BUNDLE-ADAPTER.md](BUNDLE-ADAPTER.md)). Using beacon-php directly in a non-Symfony
PHP application with the same Beacon project:

| BeaconBundle | beacon-php |
| --- | --- |
| `nowo_beacon.dsn` | `dsn` |
| `nowo_beacon.environment` / `release` / `server_name` | same keys |
| `nowo_beacon.send.*` | `send.*` (same keys and defaults) |
| `nowo_beacon.timeout` / `verify_peer` | same keys |
| `nowo_beacon.transport.mode: async` | `async: true` (Symfony HttpClient) or `queue: true` |
| `nowo_beacon.before_send` (service id) | `before_send` (callable) |
| `BeaconClientInterface` | `Nowo\Beacon\ClientInterface` |
| envelope header `dsn` with secret | not sent by default (`envelope_dsn: full` to restore) |
