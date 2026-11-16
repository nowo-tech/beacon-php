# beacon-php

[![CI](https://github.com/nowo-tech/beacon-php/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/beacon-php/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/beacon-php.svg?style=flat)](https://packagist.org/packages/nowo-tech/beacon-php) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/beacon-php.svg)](https://packagist.org/packages/nowo-tech/beacon-php) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/beacon-php.svg?style=social&label=Star)](https://github.com/nowo-tech/beacon-php) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** [Install from Packagist](https://packagist.org/packages/nowo-tech/beacon-php) · Give it a **star** on [GitHub](https://github.com/nowo-tech/beacon-php) so more developers can find it.

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

> Status: **0.2.x** (pre-1.0). Requires PHP 8.2 – 8.5.

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

## Development

```bash
make up              # Docker dev container (PHP 8.4 + PCOV)
make qa              # cs-check + PHPStan (max) + PHPUnit
make test-coverage   # 100% line coverage
make test-matrix     # PHPUnit on PHP 8.2, 8.3, 8.4, 8.5
make parity BUNDLE=../BeaconBundle   # byte-for-byte comparison with a BeaconBundle checkout
make down
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md) (plain PHP, Laravel, Slim, workers, Monolog, check-ins, deploys)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Privacy: what is sent, what never is, scrubbing](docs/PRIVACY.md)
- [Wire format and BeaconBundle parity / adapter plan](docs/BUNDLE-ADAPTER.md)
- [PSR evaluation (REQ-CS-007)](docs/PSR.md)
- [Security policy (reporting)](.github/SECURITY.md)

## Tests and coverage

- Tests: PHPUnit (unit, golden wire-format fixtures, BeaconBundle parity, smoke) on PHP 8.2–8.5, plus Monolog 2 and
  no-mbstring cells
- PHP: 100% (1817/1817 lines in CI, `make test-coverage`)
- TS/JS: N/A
- Python: N/A

## License

MIT, see [LICENSE](LICENSE).
