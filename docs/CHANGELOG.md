# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-09

First version: the framework-free core extracted from BeaconBundle 1.19.

### Added

- `Beacon` static facade (`init()`, `captureException()`, `captureMessage()`, `captureTransaction()`,
  `captureDeploy()`, `captureCheckIn()`, `captureScheduleCatalog()`, `captureQueueSnapshot()`, tags / user / extra /
  breadcrumbs, `flush()`, `reset()`, `close()`) and the instantiable `Client` (`new Client(Options::fromDsn(...))`).
- `ClientInterface` (same API as BeaconBundle's `BeaconClientInterface`), `CheckInCapableInterface`,
  `DeployCapableInterface`, `QueueSnapshotCapableInterface`, `LifecycleInterface`, `NullClient`.
- `Options` with validation (unknown keys and wrong types refused), `BEACON_DSN` / `BEACON_ENVIRONMENT` /
  `BEACON_RELEASE` fallbacks, BeaconBundle's `send.*` switches.
- DSN parsing (`Dsn`, `DsnParser`; numeric ids and project UUIDs), control characters refused.
- `EnvelopeBuilder`: byte-for-byte BeaconBundle 1.19 output (13 golden envelopes + live parity tests); header DSN
  mode `none` (default) / `public` / `full`.
- Transports: `Psr18Transport` (PSR-18 + PSR-17, discovery or injection), `SymfonyHttpClientTransport` (timeout,
  `max_duration`, optional non-blocking `async`, no redirects), `QueuedTransport` (bounded deferred delivery,
  `Retry-After`, backoff, auth pause), `NullTransport`, auto-detection in `TransportFactory`.
- Scrubbing before `before_send` (`PayloadScrubber`) and IP stripping after it (`IpStripper`) unless `send.client`.
- `ErrorHandler`: PHP errors (bundle event shape), uncaught exceptions (rethrown or chained), fatal errors at shutdown
  with a bounded flush; previous handlers chained, `error_reporting()` respected, no double capture.
- `Monolog\BeaconHandler` for Monolog 2 and 3.
- `Request\GlobalsRequestContextProvider`, `Request\Psr7RequestContextProvider`, `Http\BeaconMiddleware` (PSR-15).
- Scope, breadcrumbs, spans and trace id per client, with `reset()` for long-running workers.
- `dedupe` option (default on): the same Throwable instance is sent once per client.

### Security

- Redirects are never followed for Guzzle and Symfony `Psr18Client` clients (injected or discovered) either; a 3xx
  from any other PSR-18 client is a failed, non-retryable send (audit JO-M1).
- `symfony/polyfill-mbstring` is required, so hosts without ext-mbstring still send events (audit JO-M2).
- PHP-warning fingerprints are redacted like the message; `timeout` / `shutdown_timeout` must be finite and bounded
  (60 s / 300 s); `E_USER_ERROR` / `E_RECOVERABLE_ERROR` are reported at level `error`; registered error handlers
  stay alive until `unregister()`; unflushed async responses are completed on destruction.
