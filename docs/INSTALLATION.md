# Installation

```bash
composer require nowo-tech/beacon-php
```

Requirements: PHP 8.2 – 8.5, `ext-json`. Runtime dependencies are interfaces only (`psr/http-client`,
`psr/http-factory`, `psr/http-message`, `psr/log`, `psr/clock`); you bring the HTTP client.

## Choosing an HTTP client

When neither `transport` nor `http_client` is configured, the client picks the first available of:

| Order | Package | Transport | Timeout |
| --- | --- | --- | --- |
| 1 | `symfony/http-client` | `SymfonyHttpClientTransport` | `timeout` + `max_duration` per request (enforced) |
| 2 | `guzzlehttp/guzzle` | `Psr18Transport` over a Guzzle client built with `timeout`, `connect_timeout`, `verify`, no redirects | enforced |
| 3 | `php-http/discovery` | `Psr18Transport` over the discovered PSR-18 client | **the client's own default** |
| – | none | `MissingHttpClientException` at `init()` | – |

The order favours clients whose timeout beacon-php can enforce. Inject your own client to choose:

```php
Beacon::init([
    'dsn'             => $dsn,
    'http_client'     => $psr18Client,       // any Psr\Http\Client\ClientInterface…
    'request_factory' => $psr17Factory,      // …plus PSR-17 factories (optional with php-http/discovery,
    'stream_factory'  => $psr17Factory,      //    or when the client implements them, like Symfony's Psr18Client)
]);

Beacon::init(['dsn' => $dsn, 'http_client' => HttpClient::create()]); // Symfony HttpClientInterface
```

PSR-18 has no per-request timeout: configure it on the client you inject (a PSR-18 client with no timeout can block
the request that reports an error).

## Optional packages

| Package | Enables |
| --- | --- |
| `monolog/monolog` (2 or 3) | `Nowo\Beacon\Monolog\BeaconHandler` |
| `psr/http-server-middleware` | `Nowo\Beacon\Http\BeaconMiddleware` (Slim, Mezzio, any PSR-15 stack) |

## Environment variables

When an option is missing, `Options::fromArray()` reads `BEACON_DSN`, `BEACON_ENVIRONMENT` and `BEACON_RELEASE`.
Keep the DSN in the environment or a secret store, never in the repository.
