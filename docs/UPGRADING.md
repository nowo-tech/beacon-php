# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.3.2](#to-032)
- [To 0.3.1](#to-031)
- [To 0.3.0](#to-030)
- [To 0.3.0](#to-030-1)
  - [BeaconBundle](#beaconbundle)
- [To 0.2.0](#to-020)
- [To 0.1.0](#to-010)
  - [Coming from BeaconBundle](#coming-from-beaconbundle)

## Unreleased

## To 0.3.2

From **0.3.1**:

```bash
composer update nowo-tech/beacon-php
```

## To 0.3.1

From **0.3.0**:

```bash
composer update nowo-tech/beacon-php
```

## To 0.3.0

From **0.2.0**:

```bash
composer update nowo-tech/beacon-php
```

## To 0.3.0

**Breaking:** `Nowo\Beacon\Scope\Scope`, `Dsn\Dsn`, `Dsn\InvalidDsnException`, `Span\SpanBuffer`, `Breadcrumb\BreadcrumbBuffer` and `Trace\TraceIdProvider` are `final`.

- Do not extend them. Wrap the instance and delegate (composition), for example a `MyDsn` that holds a `Dsn` and exposes extra methods.
- To customise behaviour, use the interfaces and extension points that exist: `ClientInterface`, `TransportInterface`, `RequestContextProviderInterface`, `UserContextProviderInterface`, `LifecycleInterface`, `BeaconExceptionInterface` (catch or implement it instead of extending `InvalidDsnException`).
- Mocking in tests: build a real instance (they have no I/O) or mock the interface your code depends on.
- `Dsn` can no longer be serialized: `serialize()` and `unserialize()` throw `LogicException` (the secret must not reach a cache, session or queue message). Store the DSN string in your configuration and parse it again with `DsnParser` where needed. `getSecretKey()` still returns the string.

### BeaconBundle

BeaconBundle (up to 1.26) extends all six classes and must be migrated before it requires `^0.3`:

| Bundle class | Today | After |
| --- | --- | --- |
| `Scope\Scope` | `extends Nowo\Beacon\Scope\Scope implements ResetInterface` | delete it; register `Nowo\Beacon\Scope\Scope` as a service |
| `Instrumentation\SpanBuffer` | `extends Nowo\Beacon\Span\SpanBuffer implements ResetInterface` | delete it; register `Nowo\Beacon\Span\SpanBuffer` as a service |
| `Breadcrumb\BreadcrumbBuffer` | `extends Nowo\Beacon\Breadcrumb\BreadcrumbBuffer implements ResetInterface` | delete it; register `Nowo\Beacon\Breadcrumb\BreadcrumbBuffer` as a service |
| `Trace\TraceIdProvider` | `extends Nowo\Beacon\Trace\TraceIdProvider implements ResetInterface`, `REQUEST_ATTRIBUTE` | delete it; register `Nowo\Beacon\Trace\TraceIdProvider` as a service; move the constant |
| `Dsn\BeaconDsn` | `extends Dsn`, secret in `SensitiveParameterValue`, `__serialize()` throws | composition (holds a core `Dsn`) or retired |
| `Dsn\InvalidBeaconDsnException` | `extends InvalidDsnException` | standalone, implementing `BeaconExceptionInterface` |

- **State classes.** The four core state classes each have a public `reset()` and none of them needs `ResetInterface` to be reset: register them as services tagged `kernel.reset` with `method: reset`, for example

  ```yaml
  Nowo\Beacon\Scope\Scope:
      tags:
          - { name: kernel.reset, method: reset }
  ```

  and the same for `Span\SpanBuffer`, `Breadcrumb\BreadcrumbBuffer` and `Trace\TraceIdProvider`. Keep the old bundle class names as service aliases if applications inject them, and point the request-scope listener's `$scopeState` iterator at the core service ids.
- **`TraceIdProvider::REQUEST_ATTRIBUTE`** (`'_beacon_trace_id'`) moves to a bundle class, for example a constant on `EventListener\BeaconTraceRequestListener`; keep the value so request attributes read by applications do not change.
- **`BeaconDsn`.** The core `Dsn` now protects the secret itself (`SensitiveParameterValue`, `__serialize()` / `__unserialize()` throw, masked `__debugInfo()`), which is what `BeaconDsn` added. Either retire `BeaconDsn` and type-hint `Nowo\Beacon\Dsn\Dsn`, or keep it as a final class that holds a core `Dsn` and delegates every getter (it is then no longer a `Dsn`: methods that pass it to core code pass the inner `Dsn`).
- **`InvalidBeaconDsnException`.** Make it a standalone `final class InvalidBeaconDsnException extends \InvalidArgumentException implements Nowo\Beacon\Exception\BeaconExceptionInterface`; `BeaconDsnParser::parse()` catches the core `InvalidDsnException` and rethrows it with the same message and the core exception as `previous`. Code that catches `BeaconExceptionInterface` or `\InvalidArgumentException` keeps working; code that catches `InvalidDsnException` must catch `BeaconExceptionInterface` instead.

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
