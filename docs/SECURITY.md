# Security

## Table of contents

- [Scope](#scope)
- [Threat model](#threat-model)
- [Logging and secrets](#logging-and-secrets)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Scope

The client (`Client`, `Beacon` facade, `Options` / DSN parsing), scrubbing and redaction, the transports (Symfony
HttpClient, PSR-18), the in-memory queue, error handlers, the PSR-15 middleware and the Monolog handler. Not covered:
the Beacon server, the host's HTTP client configuration and the host's own `before_send` callbacks.

## Threat model

| Threat | Mitigation |
| --- | --- |
| DSN secret leaked through URLs, bodies, logs or exceptions | Secret only in the `X-Beacon-Auth` header; configuration and transport errors never include the DSN ([PRIVACY.md](PRIVACY.md#credentials)) |
| Personal data sent to Beacon | Credentials, cookies and sensitive keys scrubbed **before** `before_send`; end-user IP stripped unless `send.client` is enabled |
| Redirect to a third-party host carrying the auth header | Symfony transport, Guzzle and Symfony `Psr18Client` never follow redirects; other PSR-18 clients must be configured without redirects |
| Host application broken by monitoring | Captures never throw; re-entrant captures ignored |
| Slow or unreachable Beacon pinning PHP workers | Per-request `timeout` + `max_duration`; shutdown flush bounded by `shutdown_timeout`; queue bounded (100 envelopes / 10 MiB) |
| Memory growth in long-running workers | Bounded buffers (breadcrumbs 50, spans 100, tags 32, extras 64, source cache 64 files); `reset()` between requests; ingest-path cache bounded (32 targets) |

## Logging and secrets

- Transport failures go to the injected PSR-3 logger with the URL actually used and the status; never the DSN secret,
  headers or the envelope body.
- No secrets are committed: tests and docs use placeholder DSNs only.

## Release security checklist (12.4.1)

| Item | Status (0.2.x) |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` present | ✅ |
| `.env` files ignored; no secrets in the repository | ✅ |
| Safe defaults (no DSN = disabled client, `send.client` off, scrubbing on) | ✅ |
| Input validation (options, DSN) | ✅ (`ConfigurationException`, `InvalidDsnException`, messages without secrets) |
| `composer audit` clean | ✅ (CI) |
| No secrets / personal data in logs | ✅ |
| Bounded network waits and memory | ✅ |
| REQ-SEC-004 AI audit recorded | 0.1.0 (Appendix JO); 0.2.0 audited (LO, see CHANGELOG), appendix pending |

## AI security audit (REQ-SEC-004)

Recorded in the nowo-tech security analysis (Appendix JO, beacon-php 0.1.0). Re-audit before each minor that changes
the transports, scrubbing or the wire format.

## Reporting a vulnerability

See [.github/SECURITY.md](../.github/SECURITY.md).
