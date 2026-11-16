# Feature Specification: beacon-php baseline (100% code coverage)

**Feature Branch**: `001-baseline`
**Created**: 2026-11-16
**Status**: Implemented (0.2.x)
**Input**: Existing package `nowo-tech/beacon-php` (Beacon SDK plan 2026-11-09)

## Summary

beacon-php is the framework-free PHP client of Beacon. It captures exceptions, messages, PHP errors and fatal errors,
performance transactions, deploy markers, scheduler check-ins and queue snapshots and sends them as Envelopes to
Beacon's ingest over PSR-18 or Symfony HttpClient, with privacy defaults, bounded waits and bounded memory. Its wire
format is byte-identical to BeaconBundle's.

## User Scenarios & Testing

### User Story 1 — Capture without risk (Priority: P1)

**Acceptance**: **Given** a DSN, **When** the application calls `captureException()` while Beacon is down or slow,
**Then** the call returns without throwing and the send is bounded by `timeout` / `max_duration`; the shutdown flush
is bounded by `shutdown_timeout`.

### User Story 2 — Long-running workers (Priority: P1)

**Acceptance**: **Given** one client serving many jobs, **When** the worker calls `reset()` between jobs, **Then**
scope, breadcrumbs, spans, trace id and request context of the previous job are gone and memory stays bounded.

### User Story 3 — Privacy by default (Priority: P1)

**Acceptance**: the DSN secret appears only in `X-Beacon-Auth`; credentials, cookies and sensitive keys are scrubbed
before `before_send`; the end-user IP is removed unless `send.client` is enabled.

### User Story 4 — Same bytes as BeaconBundle (Priority: P2)

**Acceptance**: golden fixtures and the live parity run produce byte-identical Envelopes for the same input.

## Requirements

### Client

- **FR-CLIENT-001**: `ClientInterface` / `Client` capture exceptions, messages and transactions, build Envelopes and
  hand them to a transport; `Version` identifies the SDK in `User-Agent`.
- **FR-CLIENT-002**: the `Beacon` facade holds one current client (`NullClient` before `init()` or without a DSN).
- **FR-CLIENT-003**: optional capabilities for check-ins, deploy markers and queue snapshots.
- **FR-CFG-001**: `Options` is immutable and strictly validated (`ConfigurationException`); the DSN is parsed by
  `DsnParser` (`InvalidDsnException`); no exception message contains the DSN or a secret.
- **FR-SAFE-001**: a capture never throws into the application (encoding failures drop the event; re-entrant captures
  are ignored); `ErrorHandler` reports PHP errors, uncaught exceptions and fatal shutdowns.

### Wire format and ingest

- **FR-WIRE-001**: `EnvelopeBuilder` produces single-item Envelopes byte-identical to BeaconBundle's.
- **FR-INGEST-001** (0.2.0): versioned ingest paths with a one-time fallback to the legacy alias (`IngestPaths`,
  `IngestPathMode`, `IngestPathResolver`, bounded to 32 targets).

### Context and workers

- **FR-CTX-001**: scope tags / user / extras, bounded breadcrumbs (50) and spans (100), trace id and database facts
  are merged into the next event or transaction.
- **FR-CTX-002**: the current request comes from a provider (superglobals or PSR-7), read as a `RequestSnapshot`.
- **FR-WORKER-001**: per-request / per-job state is cleared by `reset()` (`LifecycleInterface`); process-wide caches
  are bounded.

### Privacy

- **FR-PRIV-001**: `send.*` switches decide which context leaves the process; IP stripped unless `send.client`; the
  secret only in the auth header.
- **FR-PRIV-002**: `PayloadScrubber` (on by default) and `SensitiveValueRedactor` scrub before `before_send`;
  `FrameVarsSerializer` builds safe frame vars.

### Transports and integrations

- **FR-TRANS-001**: `TransportFactory` picks Symfony HttpClient, PSR-18 or the null transport
  (`MissingHttpClientException` when nothing is available).
- **FR-TRANS-002**: every send is bounded; the optional `QueuedTransport` is a bounded FIFO honouring `Retry-After`;
  `IngestResponseHandler` maps responses to `TransportResult` and logs without secrets.
- **FR-INT-001**: optional PSR-15 middleware and Monolog 2/3 handler.

## Success Criteria

- **SC-001**: **49/49** files under `src/` mapped in [`code-inventory.md`](code-inventory.md).
- **SC-002**: line coverage of `src/` = 100% (CI).
- **SC-003**: golden and parity suites green.

## Non-goals

- Symfony bundle integration (BeaconBundle), the Beacon server, framework-specific service providers.

## Validation

```bash
make release-check
git ls-files src | wc -l   # 49
```
