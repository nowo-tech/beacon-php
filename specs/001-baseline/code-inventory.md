# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)
**Package**: `nowo-tech/beacon-php`
**Last audited**: 2026-11-16 (`git ls-files src | wc -l` → 49)

## Client and facade

Requirements: FR-CLIENT-001, FR-CLIENT-002.

| Source file | Role | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Beacon.php` | Static facade over one current client | FR-CLIENT-002 | Mapped |
| `Client.php` | Default client: builds envelopes, hands them to a transport | FR-CLIENT-001, FR-SAFE-001 | Mapped |
| `ClientInterface.php` | Public reporting API | FR-CLIENT-001 | Mapped |
| `NullClient.php` | No-op client before init / disabled | FR-CLIENT-002 | Mapped |
| `CheckInCapableInterface.php` | Scheduler check-ins capability | FR-CLIENT-003 | Mapped |
| `DeployCapableInterface.php` | Deploy markers capability | FR-CLIENT-003 | Mapped |
| `QueueSnapshotCapableInterface.php` | Queue snapshots capability | FR-CLIENT-003 | Mapped |
| `LifecycleInterface.php` | Flush + per-request / per-job reset | FR-WORKER-001 | Mapped |
| `Options.php` | Immutable configuration, strict validation | FR-CFG-001 | Mapped |
| `Version.php` | SDK identity in User-Agent | FR-CLIENT-001 | Mapped |

## DSN and exceptions

Requirements: FR-CFG-001, FR-PRIV-001.

| Source file | Role | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Dsn/Dsn.php` | Parsed DSN (authority + project id) | FR-CFG-001 | Mapped |
| `Dsn/DsnParser.php` | DSN parser | FR-CFG-001 | Mapped |
| `Dsn/InvalidDsnException.php` | Invalid DSN, message without the secret | FR-CFG-001, FR-PRIV-001 | Mapped |
| `Exception/BeaconExceptionInterface.php` | Marker of SDK exceptions | FR-CFG-001 | Mapped |
| `Exception/ConfigurationException.php` | Invalid options, message without secrets | FR-CFG-001, FR-PRIV-001 | Mapped |
| `Exception/EnvelopeEncodingException.php` | Payload not encodable (event dropped) | FR-SAFE-001 | Mapped |
| `Exception/MissingHttpClientException.php` | No transport and no supported client | FR-TRANS-001 | Mapped |

## Envelope and ingest paths

Requirements: FR-WIRE-001, FR-INGEST-001.

| Source file | Role | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Envelope/Envelope.php` | Single-item envelope (header, item header, payload) | FR-WIRE-001 | Mapped |
| `Envelope/EnvelopeBuilder.php` | Envelope building, byte parity with BeaconBundle | FR-WIRE-001 | Mapped |
| `Envelope/EnvelopeDsnMode.php` | What the envelope header `dsn` carries | FR-WIRE-001, FR-PRIV-001 | Mapped |
| `Envelope/SendOptions.php` | `send.*` context switches (BeaconBundle keys) | FR-PRIV-001 | Mapped |
| `Ingest/IngestPathMode.php` | `ingest_paths` option | FR-INGEST-001 | Mapped |
| `Ingest/IngestPathResolver.php` | Versioned/legacy path memory per target, bounded (32) | FR-INGEST-001, FR-WORKER-001 | Mapped |
| `Ingest/IngestPaths.php` | Versioned and legacy ingest URLs | FR-INGEST-001 | Mapped |

## Context, scope and buffers

Requirements: FR-CTX-001, FR-WORKER-001.

| Source file | Role | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Breadcrumb/BreadcrumbBuffer.php` | Bounded breadcrumb trail (50) | FR-CTX-001, FR-WORKER-001 | Mapped |
| `Span/SpanBuffer.php` | Bounded performance spans (100) | FR-CTX-001, FR-WORKER-001 | Mapped |
| `Scope/Scope.php` | Tags, user, extras per request / job | FR-CTX-001, FR-WORKER-001 | Mapped |
| `Trace/TraceIdProvider.php` | Request / job correlation id | FR-CTX-001, FR-WORKER-001 | Mapped |
| `Context/DatabaseExceptionContext.php` | SQL / SQLSTATE facts from a throwable chain | FR-CTX-001 | Mapped |
| `Context/UserContextProviderInterface.php` | User snapshot SPI (`send.user`) | FR-CTX-001, FR-PRIV-001 | Mapped |
| `Request/RequestContextProviderInterface.php` | Current request SPI | FR-CTX-002 | Mapped |
| `Request/GlobalsRequestContextProvider.php` | Request from superglobals | FR-CTX-002 | Mapped |
| `Request/Psr7RequestContextProvider.php` | PSR-7 request holder (PSR-15 stacks) | FR-CTX-002, FR-WORKER-001 | Mapped |
| `Request/RequestSnapshot.php` | Framework-neutral request facts (`send.request`) | FR-CTX-002, FR-PRIV-001 | Mapped |

## Privacy and scrubbing

Requirements: FR-PRIV-001, FR-PRIV-002.

| Source file | Role | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Scrubber/PayloadScrubber.php` | Default scrubber, runs before `before_send` | FR-PRIV-002 | Mapped |
| `Scrubber/SensitiveValueRedactor.php` | Secret-like keys and credential values | FR-PRIV-002 | Mapped |
| `Scrubber/IpStripper.php` | End-user IP removal (unless `send.client`) | FR-PRIV-001 | Mapped |
| `Scrubber/FrameVarsSerializer.php` | Frame vars from backtrace args | FR-PRIV-002 | Mapped |

## Transports

Requirements: FR-TRANS-001, FR-TRANS-002.

| Source file | Role | Requirement IDs | Status |
| --- | --- | --- | --- |
| `Transport/TransportInterface.php` | Transport port | FR-TRANS-001 | Mapped |
| `Transport/TransportFactory.php` | Transport choice from options | FR-TRANS-001 | Mapped |
| `Transport/SymfonyHttpClientTransport.php` | Symfony HttpClient transport (timeout, max_duration, async) | FR-TRANS-001, FR-TRANS-002 | Mapped |
| `Transport/Psr18Transport.php` | PSR-18 transport | FR-TRANS-001, FR-TRANS-002 | Mapped |
| `Transport/QueuedTransport.php` | Bounded in-memory queue, Retry-After aware | FR-TRANS-002, FR-WORKER-001 | Mapped |
| `Transport/NullTransport.php` | Disabled client | FR-TRANS-001 | Mapped |
| `Transport/IngestResponseHandler.php` | Ingest responses and failures to results + logs | FR-TRANS-002, FR-PRIV-001 | Mapped |
| `Transport/TransportResult.php` | Send outcome (never the secret) | FR-TRANS-002, FR-PRIV-001 | Mapped |

## Integrations

Requirements: FR-INT-001.

| Source file | Role | Requirement IDs | Status |
| --- | --- | --- | --- |
| `ErrorHandler/ErrorHandler.php` | PHP errors, uncaught exceptions, fatal shutdown | FR-INT-001, FR-SAFE-001 | Mapped |
| `Http/BeaconMiddleware.php` | PSR-15 middleware (optional) | FR-INT-001, FR-CTX-002 | Mapped |
| `Monolog/BeaconHandler.php` | Monolog 2/3 handler (optional) | FR-INT-001 | Mapped |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Client and facade | 10 | 10 |
| DSN and exceptions | 7 | 7 |
| Envelope and ingest paths | 7 | 7 |
| Context, scope and buffers | 10 | 10 |
| Privacy and scrubbing | 4 | 4 |
| Transports | 8 | 8 |
| Integrations | 3 | 3 |
| **Total production sources** | **49** | **49** |
