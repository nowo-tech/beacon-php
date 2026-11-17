# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/beacon-php` (`library`, framework-free) |
| Audited revision | 0.2.0 + Phase C (2026-11-17) |
| Audit date | 2026-11-17 |
| Method | Manual review of every class that holds state under `src/`; PHPStan `ruleset-classic` + `ruleset-worker` (`composer phpstan`); Igor (`composer igor`); `tests/Unit/WorkerRequestLoopTest.php` (one client, 2,000 requests alternating two tenants, `reset()` between requests and nothing else: no state of a request is visible in the next, memory drift < 1 MiB) |
| **Verdict** | ✅ **Viable** — the per-request state lives in four small objects that `Client::reset()` clears; every other piece of process-wide state is bounded and holds no request, user or tenant data |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [State reviewed](#state-reviewed)
- [Justified findings](#justified-findings)
- [Rules for the next changes](#rules-for-the-next-changes)

## Execution model assumed

FrankenPHP worker mode boots the application once and serves many requests with the same process. The package has
no kernel and no container: the host (a framework adapter, a PSR-15 middleware, `BeaconMiddleware`, an Octane or
queue listener) calls `Client::reset()` / `Beacon::reset()` after each request or job. The audit assumes exactly
that and nothing more: **no kernel reset, no services resetter**.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Per-request state | ✅ | `Scope` (tags, user, extra), `BreadcrumbBuffer`, `SpanBuffer` and `TraceIdProvider` are cleared by `Client::reset()`; the `captured` map (exception → event id) is a `WeakMap` replaced at reset; `WorkerRequestLoopTest` proves no leak between two tenants over 2,000 requests |
| Request object | ✅ | `BeaconMiddleware` sets the PSR-7 request on `Psr7RequestContextProvider` and clears it in `finally`; `GlobalsRequestContextProvider` reads `$_SERVER` through a closure at call time and keeps nothing |
| Bounded buffers | ✅ | Breadcrumbs 50, spans 100, `QueuedTransport` 100 envelopes / 10 MiB with oldest-first eviction, the Symfony HttpClient transport at most `$maxPending` requests, the source-line cache of `EnvelopeBuilder` a fixed number of files (LRU) |
| Process-wide state | ✅ (justified) | `Beacon` facade (one client), `ErrorHandler` (process handlers, restored by `unregister()`), `IngestPathResolver::shared()` (server capability per target, at most 32 entries): see [Justified findings](#justified-findings) |
| Memory growth | ✅ | < 1 MiB drift after warm-up over 1,500 requests with `reset()` between them |
| Superglobals, `putenv`, `setlocale`, timezone | ✅ | Only the read of `$_SERVER` by `GlobalsRequestContextProvider`; no `putenv`, `setlocale` or default-timezone change in `src/`; times are formatted in UTC explicitly |
| Resources held open | ✅ | No file or socket handle is kept; the async HttpClient transport finalises pending requests on `flush()` / destruct and never throws |

## State reviewed

| Class | State | Verdict |
|-------|-------|---------|
| `Client` | readonly collaborators, `lastEventId`, `closed`, `captured` (`WeakMap`) | Per client. `reset()` clears scope, breadcrumbs, spans, trace id and `captured`; `lastEventId` is overwritten by the next capture |
| `Scope`, `BreadcrumbBuffer`, `SpanBuffer`, `TraceIdProvider` | request data | Cleared by `reset()`, bounded |
| `QueuedTransport` | envelope queue, byte count, failure counters, back-off deadline | Bounded; holds already-scrubbed envelope bodies only (never the DSN secret); flushed by `flush()` / `close()` |
| `SymfonyHttpClientTransport` | pending async requests | Bounded by `$maxPending`; `__destruct()` finalises them |
| `EnvelopeBuilder` | source-line cache | Keyed by file path (code, not request data), LRU with a fixed maximum |
| `Beacon` | static client + error handler | The package's only global handle; `init()` closes the previous client and restores the previous handlers first |
| `ErrorHandler` | process error / exception / shutdown handlers, `captured`, `lastErrorKey`, reserved memory | One handler per `init()`; `unregister()` restores the previous handlers and frees the reserved memory; a static map of registered handlers lets the shutdown function reach a live handler through `WeakReference` |
| `IngestPathResolver` | known targets (target → bool) | Process-wide on purpose (a worker probes each older Beacon once); at most 32 entries, oldest dropped; `reset()` forgets every target; no key, secret, request, user or tenant data |
| `Psr7RequestContextProvider` | current PSR-7 request | Set and cleared by `BeaconMiddleware` around each request |

## Justified findings

`composer phpstan` and `composer igor` flag the state above because a library that captures errors has to keep some
process state. Each finding is either bounded request state cleared by `reset()` or an intentional process-wide
hook; none is fixed by changing the code without removing a feature.

- **PHPStan** (`phpstan.neon.dist`): `frankenphp.worker.noMutableStaticProperty`, `noSetErrorExceptionHandler`,
  `noPersistentIniSet` and `noRegisterShutdownFunction` are ignored for `src/Beacon.php`,
  `src/ErrorHandler/ErrorHandler.php` and `src/Ingest/IngestPathResolver.php` only. Tests that install and restore
  process handlers, `putenv` values or `exit` in a smoke script are ignored for those test files only.
- **Igor** (`igor-baseline.json`): every entry carries its reason: buffers and the scope are request state cleared by
  `Client::reset()`; queue counters are bounded and flushed; the error handler is the feature; the `Beacon` facade is
  the documented single global client.
- The remediation stays PHP-FPM compatible: the package never relies on a FrankenPHP-only API.

## Rules for the next changes

1. New per-request state belongs in an object that `Client::reset()` clears, and has a bound.
2. A new process-wide cache holds no request, user or tenant data, has a maximum size and a `reset()`.
3. No `static` locals, no `putenv`, `setlocale`, `date_default_timezone_set` or `ini_set` in `src/` outside the
   error handler's out-of-memory path.
4. Re-audit (this file, `WorkerRequestLoopTest`, the Igor baseline) whenever a class holding state is added.
