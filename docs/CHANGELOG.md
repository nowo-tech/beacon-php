# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - Unreleased

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
