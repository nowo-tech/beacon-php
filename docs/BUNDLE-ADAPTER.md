# Wire format, BeaconBundle parity and the adapter plan

## Wire format (shared with BeaconBundle 1.19)

- `POST {scheme}://{host}[:{port}]/api/ingest/v1/{project_id}/envelope/` (Beacon ≥ 1.147), legacy alias
  `POST {scheme}://{host}[:{port}]/api/{project_id}/envelope/` after a 404 / 405 (`ingest_paths`, same values and
  rules as BeaconBundle 1.23 `nowo_beacon.ingest_paths`)
- Headers: `Content-Type: application/x-beacon-envelope`, `User-Agent: beacon-php/0.1.0`,
  `X-Beacon-Auth: Beacon beacon_key={public}, beacon_secret={secret}`.
- Body: three NDJSON lines and a trailing newline, encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`:
  1. envelope header `{"event_id": 32-hex, ["dsn": …,] "sent_at": "Y-m-d\TH:i:s.u\Z"}`
  2. item header `{"type": "event" | "transaction" | "deploy" | "queue_snapshot" | "schedule_catalog" | "check_in",
     "content_type": "application/json"}`
  3. payload (key order and content identical to the bundle's `EnvelopeBuilder`)
- Responses: 2xx accepted; 429 (`Retry-After`, `X-Beacon-Rate-Limit`) and 5xx retryable; 401 / 403 bad credentials;
  other 4xx rejected. Log messages and context keys are the bundle's.

beacon-js uses the same ingest with a `length` item header and `sdk` blocks; Beacon accepts both.

## Parity evidence

- `tests/Golden/BundleGoldenTest.php`: 13 recorded envelopes (`tests/Fixtures/Golden/bundle-1.19/*.ndjson`) produced by
  BeaconBundle 1.19.1's `EnvelopeBuilder`; beacon-php's builder (`envelope_dsn: full`) reproduces them **byte for byte**:
  message with tags / extra / fingerprint, exception chain with in-app and vendor frames, exception without
  stacktrace / environment / release / server name, PDO exception with `contexts.db`, request with redaction and the
  header allow-list, request with `send.client`, user provider + breadcrumbs + Unicode, transaction with spans and
  request, deploy (full and defaults), queue snapshot, schedule catalog, check-in.
- `tests/Parity/BundleParityTest.php` (`make parity BUNDLE=…`, CI job `bundle-parity`): the same 13 cases live against
  a bundle checkout, plus a real exception with runtime / OS contexts, source lines and frame vars.
- Normalisation in both: the random event id and breadcrumb timestamps (the bundle has no injectable generator).

## Intentional differences (core defaults vs bundle)

| Topic | BeaconBundle 1.19 | beacon-php default | Adapter setting for zero change |
| --- | --- | --- | --- |
| Envelope header `dsn` | full DSN **with secret** | none | `envelope_dsn: full` |
| Payload scrubber before `before_send` | message / URL redaction only | + key / card scrubbing of extra, contexts, tags, breadcrumbs | `scrub: false` |
| IP stripping after `before_send` | none (`send.client` gates the request IP only) | always unless `send.client` | no-op for bundle payloads unless `before_send` adds an IP; make it opt-out if the owner wants strict zero change |
| Redirects | client default (follows) | not followed (Symfony transport, Guzzle, Symfony `Psr18Client`; a 3xx is a failed send) | `SymfonyHttpClientTransport(maxRedirects: null)` |
| Same Throwable captured twice | sent twice | sent once (`dedupe`) | `dedupe: false` |
| PHP-warning fingerprint | raw message | message redacted (`SensitiveValueRedactor::redactMessage`) | port the fix to `BeaconPhpWarningHandler` (grouping changes only for messages that contained secrets) |
| Invalid UTF-8 in a payload | `RuntimeException` from the builder | replaced by U+FFFD | (bug fix; output identical for valid input) |
| Control characters in DSN keys | accepted | refused | (hardening; no real DSN has them) |
| Default environment | `prod` (builder default, the bundle always passes one) | `production` | bundle passes its value |
| `User-Agent` | `beacon-bundle/x.y.z` | `beacon-php/x.y.z` | pass `$userAgent` to the transport |

## Adapter plan (BeaconBundle minor, no behaviour change)

1. `composer.json`: `"nowo-tech/beacon-php": "^0.1"` (or `^1.0` once tagged).
2. Value objects extend the core classes (they are non-final for this):
   `BeaconDsn extends Nowo\Beacon\Dsn\Dsn`, `InvalidBeaconDsnException extends InvalidDsnException`,
   `Scope extends Nowo\Beacon\Scope\Scope implements ResetInterface`, same for `BreadcrumbBuffer`, `TraceIdProvider`,
   `SpanBuffer`. `BeaconDsnParser::parse()` delegates to `DsnParser` and re-wraps the result / exception.
3. `Support\SensitiveValueRedactor`, `Support\FrameVarsSerializer`, `Context\DatabaseExceptionContext`: static
   facades forwarding to `Nowo\Beacon\Scrubber\*` / `Nowo\Beacon\Context\*` (identical rules), or `class_alias`.
4. `Envelope\EnvelopeBuilder`: keeps its constructor; builds a core `EnvelopeBuilder` with
   `dsnMode: EnvelopeDsnMode::Full`, `framework: ['name' => 'symfony', 'version' => Kernel::VERSION]` when the kernel
   exists, `requestProvider:` a `SymfonyRequestContextProvider` (RequestStack → `RequestSnapshot`, exactly what
   `GoldenCases::snapshot()` does), `culpritThrowableResolver:` `MessengerContext::rootCause()` for
   `HandlerFailedException`, `internalClassPrefixes: ['Nowo\BeaconBundle\']`, `internalPaths: [bundle src/]`; every
   `build*Envelope()` forwards.
5. `Envelope\EnvelopeTransport` / `AsyncEnvelopeTransport`: wrap `SymfonyHttpClientTransport` (`maxRedirects: null`,
   bundle User-Agent, `async`), keep `send(): bool`, `sendDetailed(): TransportResult`, `startRequest()` /
   `finalizeResponse()` (internal) and `LOG_CONTEXT_FLAG` (same value as `IngestResponseHandler::LOG_CONTEXT_FLAG`).
   Messenger transport and `IngestHttpClientFactory` (retries) stay in the bundle.
6. `Client\BeaconClient`: implements the bundle interfaces and delegates to a core `Client` built with
   `scrub: false`, `dedupe: false`, the bundle's `before_send`, an adapter transport (bundle `EnvelopeTransportInterface` → core
   `TransportInterface`), the builder above and the bundle's state objects. Keep the bundle's null-scope semantics
   (`getTags()` returns `[]` when no scope service exists).
7. `Monolog\BeaconMonologHandler`: keep the class (it extends `AbstractProcessingHandler`, processors keep working)
   and reuse the core redaction; or alias `Nowo\Beacon\Monolog\BeaconHandler` in a later major.
8. `BeaconPhpWarningHandler` / `BeaconFatalErrorHandler`: keep the bundle's kernel-aware static dispatch (one handler
   per process for many kernels); the event shapes already match `ErrorHandler`'s, which the bundle may use later.
9. Proof: copy `tests/Golden` + `GoldenCases` into the bundle and run them against the adapter; the bundle's own suite
   (and `make check-envelope-goldens` against symfony-beacon) must stay green unchanged.

Owner decisions to take before or with the adapter minor: keep the secret in the bundle's envelope header
(`full`) or move to `none` (Beacon authenticates by header first; beacon-js Node already omits it); adopt the
scrubber; adopt no-redirects.
