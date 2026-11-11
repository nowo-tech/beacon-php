# beacon-php

[![CI](https://github.com/nowo-tech/beacon-php/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/beacon-php/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.2%20%E2%80%93%208.5-777bb4)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Framework-free PHP client for **Beacon**, the nowo-tech error-tracking and monitoring server. It captures exceptions,
messages, PHP errors and fatal errors, performance transactions, deploy markers and scheduler check-ins, and sends
them to Beacon's Envelope ingest.

- **No framework required**: plain PHP, Laravel, Slim / any PSR-15 stack, CLI workers. The Symfony integration is
  [nowo-tech/beacon-bundle](https://github.com/nowo-tech/BeaconBundle), which will build on this package.
- **Transport over PSR-18** (any client, auto-discovered or injected) or the optional Symfony HttpClient adapter
  (per-request timeout, non-blocking sends). Optional bounded in-memory queue that respects `Retry-After`.
- **Private by default**: the DSN secret only travels in the `X-Beacon-Auth` header; credentials, cookies and
  sensitive keys are scrubbed **before** `before_send`; the end user's IP is never sent unless you enable
  `send.client`. See [docs/PRIVACY.md](docs/PRIVACY.md).
- **Safe**: a capture never throws into your app, re-entrant captures are ignored, flushing at shutdown is bounded by
  `shutdown_timeout`, clients share no request state (only a bounded ingest path cache), `reset()` clears request
  state in long-running workers.
- **Versioned ingest paths** (`/api/ingest/v1/…`, Beacon ≥ 1.147) with a one-time fallback to the legacy alias on
  older Beacons (`ingest_paths`).
- **Same wire format as BeaconBundle** (byte-for-byte golden tests), so the bundle can delegate to it.

> Status: **0.1.0, unreleased.** Requires PHP 8.2 – 8.5.

## Installation

```bash
composer require nowo-tech/beacon-php symfony/http-client
```

Any PSR-18 client works instead of `symfony/http-client` (for example `guzzlehttp/guzzle`, or `php-http/discovery`
with your client). See [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Quick start

```php
use Nowo\Beacon\Beacon;

Beacon::init([
    'dsn'         => getenv('BEACON_DSN'), // https://PUBLIC_KEY:SECRET_KEY@beacon.example.com/PROJECT_ID
    'environment' => 'production',
    'release'     => 'shop@1.4.2',
]);

try {
    checkout();
} catch (Throwable $e) {
    Beacon::captureException($e, ['order_id' => 42]);
}

Beacon::captureMessage('Payment provider slow', 'warning');
```

`Beacon::init()` also installs the error, uncaught-exception and fatal-shutdown handlers (chaining the previous
ones) and flushes at shutdown. An empty DSN disables everything (captures return `null`).

Prefer instances (dependency injection, several projects, tests)?

```php
use Nowo\Beacon\Client;
use Nowo\Beacon\Options;

$client = new Client(Options::fromDsn($_ENV['BEACON_DSN']));
$client->captureMessage('Imported 120 rows', 'info');
```

## Documentation

| Topic | File |
| --- | --- |
| Installation and HTTP clients | [docs/INSTALLATION.md](docs/INSTALLATION.md) |
| Usage: plain PHP, Laravel, Slim, workers, Monolog, check-ins, deploys | [docs/USAGE.md](docs/USAGE.md) |
| Every option | [docs/CONFIGURATION.md](docs/CONFIGURATION.md) |
| What is sent, what never is, scrubbing | [docs/PRIVACY.md](docs/PRIVACY.md) |
| Wire format and BeaconBundle parity / adapter plan | [docs/BUNDLE-ADAPTER.md](docs/BUNDLE-ADAPTER.md) |
| Changelog / upgrading | [docs/CHANGELOG.md](docs/CHANGELOG.md), [docs/UPGRADING.md](docs/UPGRADING.md) |
| Security policy | [SECURITY.md](SECURITY.md) |
| Contributing, development stack, release | [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md), [docs/RELEASE.md](docs/RELEASE.md) |

## Development

```bash
make up              # Docker dev container (PHP 8.4 + PCOV)
make qa              # cs-check + PHPStan (max) + PHPUnit
make test-coverage   # 100% line coverage
make test-matrix     # PHPUnit on PHP 8.2, 8.3, 8.4, 8.5
make parity BUNDLE=../BeaconBundle   # byte-for-byte comparison with a BeaconBundle checkout
make down
```

## License

MIT, see [LICENSE](LICENSE).
