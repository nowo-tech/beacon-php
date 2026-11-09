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
| `timeout` | `float ≥ 0.1` | `5.0` | Per-request timeout (Symfony HttpClient, auto-created Guzzle) |
| `verify_peer` | `bool` | `true` | TLS verification (Symfony HttpClient, auto-created Guzzle). `false` only for a local self-signed Beacon |
| `async` | `bool` | `false` | Symfony HttpClient: start requests without waiting; `flush()` reads the results (≤ 100 in flight) |
| `queue` | `bool` \| `{max_envelopes, max_bytes}` | `false` | Deferred delivery through a bounded in-memory FIFO (default 100 envelopes / 10 MiB) sent by `flush()` |
| `shutdown_timeout` | `float ≥ 0` | `2.0` | Upper bound for `flush()` without argument, the shutdown flush and the uncaught-exception flush |

Redirects are not followed (`max_redirects: 0` / Guzzle `allow_redirects: false`): the auth header must not reach
another host.

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
