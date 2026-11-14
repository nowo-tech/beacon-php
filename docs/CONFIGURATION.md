# Configuration

`Options::fromArray()` (and `Beacon::init([...])`) accept these keys. Unknown keys and wrong types throw
`ConfigurationException`; an invalid DSN throws `InvalidDsnException`. Messages never contain the DSN or the secret.
Names match BeaconBundle's `nowo_beacon` configuration where both exist.

## Core

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `dsn` | `?string` | env `BEACON_DSN` | `https://PUBLIC_KEY:SECRET_KEY@host[:port]/PROJECT_ID` (numeric id or project UUID). Empty or null: disabled client |
| `enabled` | `bool` | `true` | Master switch |
| `environment` | `string` | env `BEACON_ENVIRONMENT`, else `production` | Event `environment` |
| `release` | `?string` | env `BEACON_RELEASE` | Event `release` |
| `server_name` | `?string` | `gethostname()` | Event `server_name` (sent while `send.server_name` is true, as in BeaconBundle) |
| `send` | `array<string,bool>` | see below | Context categories |
| `stack_context_lines` | `int ≥ 0` | `5` | Source lines around each frame |
| `before_send` | `callable(array): ?array` | `null` | Last chance to edit or drop events and transactions (runs after scrubbing) |
| `dedupe` | `bool` | `true` | `captureException()` sends the same Throwable instance once and returns the first event id (framework report hook + Monolog channel + middleware). BeaconBundle's adapter sets `false` |
| `scrub` | `bool` | `true` | Payload scrubber before `before_send` ([PRIVACY.md](PRIVACY.md)). BeaconBundle's adapter sets `false` to keep its bytes |
| `envelope_dsn` | `none` \| `public` \| `full` | `none` | DSN in the envelope header. `full` puts the **secret in the body** (BeaconBundle ≤ 1.19 behaviour); avoid it |
| `logger` | `Psr\Log\LoggerInterface` | `null` | Transport / capture failures (records flagged `nowo_beacon_transport`) |
| `clock` | `Psr\Clock\ClockInterface` | system | Event timestamps (tests) |

## Delivery

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `transport` | `TransportInterface` | auto | Use this transport as is |
| `http_client` | PSR-18 client or Symfony `HttpClientInterface` | auto-detected | See [INSTALLATION.md](INSTALLATION.md) |
| `request_factory`, `stream_factory` | PSR-17 factories | client or discovery | For a PSR-18 client |
| `timeout` | finite `float`, 0.1 – 60 | `5.0` | Per-request timeout (Symfony HttpClient, Guzzle, Symfony `Psr18Client`) |
| `verify_peer` | `bool` | `true` | TLS verification (Symfony HttpClient, auto-created Guzzle). `false` only for a local self-signed Beacon |
| `async` | `bool` | `false` | Symfony HttpClient: start requests without waiting; `flush()` reads the results (≤ 100 in flight) |
| `ingest_paths` | `auto` \| `versioned` \| `legacy` (or `Ingest\IngestPathMode`) | `auto` | Ingest paths, see [Ingest paths](#ingest-paths) |
| `ingest_path_resolver` | `Ingest\IngestPathResolver` | shared (`auto`) | Share or isolate the path cache; its mode must match `ingest_paths` |
| `queue` | `bool` \| `{max_envelopes, max_bytes}` | `false` | Deferred delivery through a bounded in-memory FIFO (default 100 envelopes / 10 MiB) sent by `flush()` |
| `shutdown_timeout` | finite `float`, 0 – 300 | `2.0` | Deadline of `flush()` without argument, of the shutdown flush and of the uncaught-exception flush. Not a hard bound: a request already running can take up to `timeout` more, and a capture made at shutdown (fatal, uncaught exception) is itself a normal send bounded by `timeout` |

Redirect policy (a 307/308 would replay `X-Beacon-Auth` and the body to the target host):

| Client | Redirects | Request timeout |
| --- | --- | --- |
| `SymfonyHttpClientTransport` (auto or `http_client: HttpClientInterface`) | never followed (`max_redirects: 0`) | `timeout` + `max_duration` |
| Guzzle (`GuzzleHttp\ClientInterface`, auto-created, injected or discovered) | never followed (`allow_redirects: false` on every send) | `timeout` + `connect_timeout` |
| Symfony `Psr18Client` (injected or discovered; symfony/http-client ≥ 6.2, older versions conflict) | never followed (wrapped with `max_redirects: 0`) | `timeout` + `max_duration` |
| any other PSR-18 client | **its own policy** — beacon-php cannot change it | **its own** |

For other PSR-18 clients a 3xx response is treated as a failed, non-retryable send (logged, never re-sent), but a
client that follows redirects internally has already sent the header to the target: configure it without redirects
and with a timeout.

### Ingest paths

Beacon 1.147 serves the canonical versioned paths; the unversioned ones stay as permanent aliases:

| Endpoint | Versioned (Beacon ≥ 1.147) | Legacy (every Beacon) |
| --- | --- | --- |
| Envelope | `/api/ingest/v1/{projectId}/envelope/` | `/api/{projectId}/envelope/` |
| OTLP/HTTP | `/api/ingest/v1/{projectId}/otlp/v1/{traces\|logs\|metrics}` | `/api/{projectId}/otlp/v1/{signal}` |
| Remote Messenger commands | `/api/ingest/v1/{projectId}/messenger/commands/claim`, `…/{id}/result` | `/api/{projectId}/messenger/commands/…` |

beacon-php sends envelopes only; `Nowo\Beacon\Ingest\IngestPaths` builds every URL for integrations.

- `auto` (default): the versioned path unless the target is known to serve the alias only. HTTP 404 or 405 there means
  an older Beacon: the same envelope is sent once more to the legacy path and only that answer is logged. A 404 / 405
  means nothing was processed, so this never duplicates an envelope. The answer is remembered per target (DSN origin +
  project id), and the newest answer replaces the older one:

  | Answer | Learned | Re-sent to the legacy path |
  | --- | --- | --- |
  | versioned 2xx, or a Beacon 400 / 401 / 403 / 413 / 415 / 422 | versioned | no |
  | versioned 404 / 405 | (forgotten if it was versioned: self-heal) | once |
  | versioned 408 / 429 / 5xx / any other status, or a network failure | nothing (the next envelope probes again) | no |
  | legacy 2xx, or a Beacon 400 / 401 / 403 / 413 / 415 / 422 | legacy | — |
  | legacy 404 / 405 too (unknown project) | versioned | — |
  | legacy 408 / 429 / 5xx / other, or a network failure | nothing | — |

  A transient 503 from a proxy or a load balancer in front of an older Beacon therefore never pins the versioned path,
  and a target known as versioned that starts answering 404 (Beacon downgrade) heals itself on the next envelope. 429
  and 5xx keep their usual handling (`queue` retries them).
- `versioned`: always the versioned path, never a fallback. `legacy`: always the alias, no probe.
- With `async`, a pending versioned request keeps a reference to its body (no copy; at most 100 pending requests); the
  legacy retry runs synchronously when `flush()` reads the status.
- Log records carry the URL actually used (`url` context: versioned or legacy).
- Cost against Beacon < 1.147: one extra 404 per probe. In a long-running worker (FrankenPHP worker mode, RoadRunner,
  a queue consumer) that is once per process and target. Under **PHP-FPM**, CGI or short CLI runs the process-wide
  cache lasts one request or run, so every request that sends anything pays it: set `ingest_paths: legacy` until
  Beacon is 1.147, then remove it (or set `versioned`).
- The cache is process-wide (`IngestPathResolver::shared()`, like beacon-js' module-level cache): at most 32 targets,
  oldest dropped, values are booleans about a server (no keys, secrets, request, user or tenant data). Pass your own
  `ingest_path_resolver` to isolate a client; `reset()` forgets every target.

Rate limits: `Retry-After`, the backoff and the one-hour pause after 401 / 403 apply only with `queue`; synchronous
delivery (the default) sends every capture, as BeaconBundle does.

## `send`

Same keys and defaults as BeaconBundle's `nowo_beacon.send`:

| Key | Default | Sends |
| --- | --- | --- |
| `environment` | `true` | `environment` |
| `release` | `true` | `release` (when set) |
| `server_name` | `true` | `server_name` |
| `stacktrace` | `true` | exception frames, culprit, current stack for messages |
| `request` | `true` | request URL / method / query (redacted), route, allow-listed headers |
| `user` | `false` | the `user_provider`'s user (an explicit `setUser()` is always sent) |
| `runtime` | `true` | `contexts.runtime` (PHP version) |
| `framework` | `true` | `contexts.framework` when the `framework` option is set |
| `os` | `true` | `contexts.os` |
| `client` | `false` | **client IP and User-Agent** in `request.client`; when false the IP stripper also removes any IP the payload carries |
| `stack_vars` | `false` | frame arguments (summarised and redacted; may contain PII) |

## Error handlers

`error_handler`: `true` (default), `false`, or an array — see [USAGE.md](USAGE.md#error-handlers).

## Integration hooks

| Key | Type | Meaning |
| --- | --- | --- |
| `request_provider` | `RequestContextProviderInterface` | Current request. Default: `GlobalsRequestContextProvider` (`$_SERVER`) |
| `user_provider` | `UserContextProviderInterface` | User snapshot, used when `send.user` is true |
| `framework` | `{name, version}` | `contexts.framework` |
| `culprit_resolver` | `callable(Throwable): Throwable` | Which throwable names the culprit (BeaconBundle: Messenger root cause) |
| `internal_class_prefixes`, `internal_paths` | `list<string>` | Extra frames hidden from message stack traces (adapters) |
