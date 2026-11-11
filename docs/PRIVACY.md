# Privacy and data handling

## Credentials

- The DSN secret is sent **only** in the `X-Beacon-Auth: Beacon beacon_key=…, beacon_secret=…` header. The ingest URL
  is `{origin}/api/ingest/v1/{project}/envelope/` or its alias `{origin}/api/{project}/envelope/` (no credentials). By default the envelope header carries no `dsn` at all
  (`envelope_dsn: none`, like beacon-js `beaconServerAuth()`).
- `envelope_dsn: full` restores BeaconBundle ≤ 1.19's header (`"dsn":"https://PUBLIC:SECRET@…"`, the secret in the
  request body and in anything that stores bodies). Beacon removes it before queueing, but proxies and the in-memory
  queue see it. Use it only where a proxy strips custom headers.
- Error messages from transports are passed through a sanitiser that replaces the secret and the auth header value;
  `Dsn::__debugInfo()` masks the secret (`var_dump`, `print_r`). `Dsn::toString()` keeps it (bundle compatibility):
  never log it.
- DSN keys with control characters are refused (header injection).
- Redirects are never followed by the Symfony HttpClient transport, Guzzle (auto-created, injected or discovered)
  and Symfony's `Psr18Client`. Any **other PSR-18 client keeps its own redirect policy**: a 3xx it returns is a failed
  send and is never re-sent, but one it follows internally has already carried the header to the target host.
  Configure such clients without redirects (see [CONFIGURATION.md](CONFIGURATION.md#delivery)).

## What is sent

An event carries `event_id`, `timestamp`, `datetime`, `platform`, `level`, `logger`, the message and / or the exception
chain (type, value, frames with file, line, function, `in_app`, source lines), `server_name`, `environment`,
`release`, `contexts` (runtime, OS, framework, SQL facts of database exceptions), `tags` (incl. `trace_id`), `extra`,
`fingerprint`, `breadcrumbs`, the request (redacted URL, method, query string, route, controller, query parameter
names, allow-listed headers: `host`, `user-agent`, `accept`, `accept-language`, `accept-encoding`, `content-type`,
`origin`, `referer`, `x-requested-with`), and `user` when the application sets one (or `send.user` with a provider).

## What is never sent (defaults)

- **The end user's IP.** With `send.client: false` (the default, as in BeaconBundle) the request snapshot has no
  client block, and the IP stripper removes, as the very last step after `before_send`: `user.ip_address`,
  `client.ip` (request, `contexts.request`, `extra.http`), `REMOTE_ADDR` in `request.env`, every header that can carry a
  client address (`X-Forwarded-For`, `X-Real-IP`, `Forwarded`, `CF-Connecting-IP`, `True-Client-IP`, `X-Client-IP` and
  any name with the word addr / address / ip / client / forwarded / via / socket) and every `{{auto}}` value.
  `send.client: true` is the explicit opt-in (IP + User-Agent).
- Cookies, request bodies, `Authorization` and other credential headers: they are not in the header allow-list; if a
  custom provider or `extra` adds them, the scrubber filters them.
- Frame arguments (`send.stack_vars: false`).

## Redaction and scrubbing

Two layers, both before `before_send`:

1. **EnvelopeBuilder** (always, identical to BeaconBundle): messages and exception values lose PEM private keys, URL
   passwords, JWTs, `Bearer` / `Basic` tokens, Stripe / AWS / GitHub / Slack / Google / LLM-provider keys and
   `password=…` / `"api_key": "…"` pairs; URLs and the referer lose userinfo and secret-like query values (`token`,
   `api_key`, `signature`, `sig`, `code`, `hash`, …); SQL in `contexts.db` has its string literals replaced by `'?'`;
   Monolog context and frame vars are redacted by key.
2. **PayloadScrubber** (`scrub: true`, default): keys matching password / secret / token / api key / auth /
   credential / dsn / salt / nonce / session / cookie / csrf / xsrf / card number / cvv / iban / ssn / pass become
   `[Filtered]` in `extra`, `contexts`, `tags`, breadcrumb `data` and `request.data`; string values get the same
   credential patterns; Luhn-valid card numbers are masked in text and integers; credential headers and
   `request.cookies` are filtered; structures deeper than 16 levels are replaced by `[Filtered]`.

## Limits

- Detection is mostly key- and pattern-based. Free-text secrets that look like ordinary words, personal data in
  exception messages (an e-mail in a database error, for example) and IP literals written by the application into
  `extra`, tags or breadcrumbs are not detected: drop them in `before_send`.
- Source context lines are read from the files on disk: a secret hard-coded next to the failing line is sent with
  the frame (lower `stack_context_lines` or disable `send.stacktrace` if that matters).
- Not scrubbed client-side (Beacon's server scrubber still redacts secret-shaped values there): span
  `description` / `data`, the transaction name, the culprit, top-level message `stacktrace` frames and source
  context lines. Keep credentials out of span descriptions (strip query strings from URLs).
- Luhn masking also hits the occasional 13–19 digit identifier that happens to pass the check.
- The in-memory queue holds already-scrubbed bodies only; it is lost when the process dies.
