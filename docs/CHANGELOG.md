# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[0.3.2] - 2026-11-20](#032---2026-11-20)
- [[0.3.1] - 2026-11-17](#031---2026-11-17)
- [[0.3.0] - 2026-11-17](#030---2026-11-17)
- [[0.2.0] - 2026-11-11](#020---2026-11-11)
- [[0.1.0] - 2026-11-09](#010---2026-11-09)

## [Unreleased]

### Changed

- PHP 8.6: `composer.json` allows PHP below 8.7 (was below 8.6). CI adds a forward-compatibility workflow with a
  PHP 8.6 cell and a Symfony 8.2 cell (both stable, no allowed failures).

## [0.3.2] - 2026-11-20

### Security

- `Scrubber\SensitiveValueRedactor` fails closed: when a PCRE call fails (backtrack, JIT stack or match limit), the
  text becomes `SensitiveValueRedactor::REDACTION_FAILED` (`[Filtered: redaction failed]`) and a key counts as
  sensitive; the original value is never returned. The same applies to `PayloadScrubber::maskCards()` /
  `isSensitiveKey()`, `DatabaseExceptionContext::scrubSql()` and `IpStripper::isClientAddressHeader()`. Quantifiers
  that could backtrack are possessive or bounded. Patterns stay byte-oriented, so invalid UTF-8 never makes them fail.
- Free text is cut at `MAX_MESSAGE_LENGTH` (64 KiB, ` [truncated]` appended) before redaction. When the cut falls
  inside a word, that word (the trailing run of non-whitespace, up to 4 KiB) is dropped too, so a token, URL, JWT
  or key cut in half is never sent in part; the cut never splits a valid UTF-8 character. `Scope` tag values longer
  than 200 characters and frame-var strings longer than 1,024 characters are redacted before they are cut.

### Changed

- The redaction rules are aligned with BeaconBundle 1.27 (the stricter rule kept where the two differed): more
  sensitive key names (`passphrase`, `private`, `cookie`, `session`, `signature`, `iban` and whole-token `pass`,
  `pwd`, `pin`, `otp`, `cvv`, `cvc`, `card`), `NAME=value` pairs inside longer identifiers (`DB_PASSWORD`,
  `dbPassword`), quoted values filtered whole (an unclosed quoted value to the end of the text), Beacon worker keys
  (`bwk_…`), Luhn-valid card numbers in messages, and the URL parameters `key`, `reset` and `sid`. URL passwords may
  contain `/` or `@` or have an empty user, in free text and in a string that starts with `scheme://user:pass@`. A
  string with URI userinfo also gets the free-text patterns, and neither a `[Filtered]` left inside an unquoted value
  by an earlier rule nor a `[` or `]` in it ends that value (so `[token=abc]` loses its final `]`). New public constants: `REDACTION_FAILED`, `MAX_MESSAGE_LENGTH`,
  `TRUNCATED`.

## [0.3.1] - 2026-11-17

### Fixed

- Audit SG-L2 (correctness, no security impact): about 0.18% of random 32-hex trace ids contain a Luhn-valid 13-19 digit run, so `PayloadScrubber` masked part of them (`[Filtered]f7639f97b1a282bbd3`) and event-to-trace correlation broke. The values of `trace_id`, `span_id` and `parent_span_id` keys (`tags`, `extra`, `contexts.trace`, breadcrumb data, any depth, key match case-insensitive) are now left intact when they are id-shaped (`[A-Za-z0-9._-]{8,128}`, the shape `TraceIdProvider` accepts); free text or URLs under those keys are still scrubbed. Card masking in text is unchanged: a card number glued to letters is still masked. A bare digit-only id under those keys stays unmasked by design. Known gap, not changed here: card numbers separated by `+` or `%20` are not masked (separate follow-up).

## [0.3.0] - 2026-11-17

### BREAKING (0.3.0)

- REQ-PHP-001: `Scope`, `Dsn`, `SpanBuffer`, `BreadcrumbBuffer`, `TraceIdProvider` and `Dsn\InvalidDsnException` are now `final`. Every other public class was already `final` or is an interface. Code that extends one of them must switch to composition (see UPGRADING). BeaconBundle extends all six and must be migrated before it can require `^0.3`: `Scope\Scope`, `Instrumentation\SpanBuffer`, `Breadcrumb\BreadcrumbBuffer` and `Trace\TraceIdProvider` (each only adds Symfony's `ResetInterface`), `Dsn\BeaconDsn extends Dsn` and `Dsn\InvalidBeaconDsnException extends InvalidDsnException`. The migration path is in UPGRADING ("BeaconBundle").
- `Dsn` is `final readonly` and holds the secret in a `\SensitiveParameterValue` (audit SE-BP-M1): `var_dump()`, `print_r()`, `var_export()`, `json_encode()`, array casts and exception traces never show it. `serialize()` / `unserialize()` of a `Dsn` (or of anything that holds one) now throw `LogicException`, as BeaconBundle's `BeaconDsn` already did. `getSecretKey()`, `getBeaconAuthHeader()` and `toString()` are unchanged.

### Changed (tooling)

- `nowo-tech/phpstan-frankenphp` (classic + worker rulesets, REQ-CS-005) and `igor-php/igor-php` (REQ-CS-008, `composer igor`, `make igor`, CI step, `release-check`) are require-dev; the intentional process-wide state of the facade, the error handlers and the ingest-path cache is a justified PHPStan ignore list and Igor baseline.
- `tests/Unit/WorkerRequestLoopTest.php`: 2,000 requests on one client with `reset()` between them, no state leaks and memory drift under 1 MiB (REQ-RUNTIME-002); `docs/FRANKENPHP-WORKER-AUDIT.md`. No change to `src/`.

### Documentation

- Stale "Not final so that framework adapters can extend it" docblocks removed (audit SE-BP-L2).
- The live parity suite (`make parity`, CI job `bundle-parity`) stays pinned to BeaconBundle 1.19.1, the release the recorded goldens come from (header mode `full`). BeaconBundle 1.21+ sends no DSN header and extends the classes that are now `final`, so it cannot be loaded against 0.3.0; the suite moves to the migrated bundle together with the adapter (audit SE Info).
- Org scaffold (REQ compliance sweep 2026-11-16): `CODE_OF_CONDUCT.md`, docs (SECURITY with threat model and release checklist, ENGRAM, SPEC-DRIVEN-DEVELOPMENT, SPEC-KIT, PSR, tables of contents), the security policy moved to `.github/SECURITY.md`, Spec Kit baseline `specs/001-baseline` (49/49 files), README badges / Found this useful / canonical Documentation / Tests and coverage, `.cursor` rules + MCP, issue and PR templates, Copilot instructions, PR lint, stale and sync-releases workflows; `release-check` gains `check-open-prs`, `composer-sync` and `cs-fix`. No change to `src/`.

## [0.2.0] - 2026-11-11

Versioned ingest paths (Beacon 1.147) with a safe fallback to the legacy routes. Audit: Appendix LO and Delta LO, clean.

### Added

- Versioned ingest paths (Beacon 1.147, D-20261110-04 amendment): envelopes go to
  `/api/ingest/v1/{projectId}/envelope/` first. New option `ingest_paths`: `auto` (default) | `versioned` | `legacy`
  (same values as beacon-js `ingestPaths` and BeaconBundle `nowo_beacon.ingest_paths`).
- `auto`: on HTTP 404 / 405 from the versioned path the same envelope is sent **once** to the legacy
  `/api/{projectId}/envelope/` (a permanent alias on every Beacon) and the triggering 404 / 405 is not logged. The
  answer is remembered per Beacon target (origin + project id) by `Ingest\IngestPathResolver`, newest answer wins.
  Only a 2xx or a Beacon 400 / 401 / 403 / 413 / 415 / 422 proves a route; 408, 429, 5xx, other statuses and network
  failures teach nothing and are never re-sent by this logic (audit LO-M1). A target known as versioned that answers
  404 / 405 is forgotten and the envelope re-sent once to the legacy path (self-heal). 404 on both paths keeps the
  versioned path. `SymfonyHttpClientTransport` (sync and `async`) and `Psr18Transport`.
- `Ingest\IngestPaths::provesRoute()` and `ROUTE_PROOF_STATUSES`; `IngestPathResolver::mayFallBack()`.
- `Ingest\IngestPaths`: versioned and legacy URLs for the envelope, OTLP/HTTP (`otlpUrl()`, `otlpEndpoint()`) and
  remote Messenger commands (`messengerClaimUrl()`, `messengerResultUrl()`).
- `ingest_path_resolver` option and `getEnvelopeUrl()` on both HTTP transports (URL the next send starts with).

### Changed

- The default `auto` resolver is process-wide (`IngestPathResolver::shared()`), bounded to 32 targets (oldest dropped)
  and holds only a server capability, so it is safe in FrankenPHP worker mode without kernel reset and in other
  long-running workers. Under PHP-FPM it lasts one request: against Beacon < 1.147 use `ingest_paths: legacy`.
- Log records of the bundled transports carry the URL actually used in `url` (versioned or legacy; audit LO-I2).
  `Dsn::getEnvelopeUrl()` keeps returning the legacy URL (BeaconBundle compatibility).

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
