# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x | Yes (pre-release) |

## Reporting a vulnerability

Please do **not** open a public issue. Report privately through
[GitHub Security Advisories](https://github.com/nowo-tech/beacon-php/security/advisories/new) or by e-mail to
hectorfranco@nowo.tech, with a description, affected versions and a reproduction if possible. We acknowledge reports
within 5 working days and coordinate a fix and disclosure date with you.

## Security model (summary)

- The DSN secret only travels in the `X-Beacon-Auth` header (never in the URL, by default never in the body, never in
  exception or log messages beacon-php writes).
- Credentials, cookies and sensitive keys are scrubbed before `before_send`; the end user's IP is stripped after it
  unless `send.client` is enabled.
- Bounded memory: breadcrumbs (50), spans (100), tags (32), scope extras (64), source cache (64 files ≤ 1 MiB),
  queue (100 envelopes / 10 MiB), in-flight async responses (100).
- Captures never throw into the application; shutdown work is bounded by `shutdown_timeout`.

Details and known limits: [docs/PRIVACY.md](docs/PRIVACY.md).
