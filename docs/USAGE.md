# Usage

- [Plain PHP](#plain-php)
- [Instances instead of the facade](#instances-instead-of-the-facade)
- [Laravel-style applications](#laravel-style-applications)
- [Slim and other PSR-15 stacks](#slim-and-other-psr-15-stacks)
- [Long-running workers](#long-running-workers)
- [Monolog](#monolog)
- [Context: tags, user, extra, breadcrumbs, trace id](#context)
- [before_send](#before_send)
- [Transactions, deploys, check-ins, queue snapshots](#transactions-deploys-check-ins-queue-snapshots)
- [Error handlers](#error-handlers)

## Plain PHP

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Nowo\Beacon\Beacon;

Beacon::init([
    'dsn'         => getenv('BEACON_DSN') ?: '',   // empty: disabled
    'environment' => 'production',
    'release'     => 'shop@1.4.2',
]);

Beacon::setTag('tenant', 'acme');
Beacon::setUser(['id' => (string) $userId]);

try {
    $order->pay();
} catch (PaymentDeclined $e) {
    $eventId = Beacon::captureException($e, ['order_id' => $order->id], ['payment', 'declined']);
    // show $eventId to support staff if you like
}

Beacon::captureMessage('Stock below threshold', 'warning', ['sku' => 'A-1']);
```

`Beacon::init()` accepts a DSN string, an options array ([docs/CONFIGURATION.md](CONFIGURATION.md)) or an `Options`
object, and returns the `Client`. It installs the PHP error handler (warnings and notices), the uncaught-exception
handler and a shutdown function that reports fatal errors and flushes within `shutdown_timeout` (2 s). Pass
`'error_handler' => false` to install nothing but the shutdown flush.

Uncaught exceptions are reported, then handed to the previous exception handler or rethrown, so PHP still prints /
logs them and exits with code 255.

## Instances instead of the facade

The facade is a convenience over one `Client`. Code with a container, several Beacon projects or tests should use
instances; nothing is global:

```php
use Nowo\Beacon\Client;
use Nowo\Beacon\ErrorHandler\ErrorHandler;
use Nowo\Beacon\Options;

$client = new Client(Options::fromArray([
    'dsn'     => $_ENV['BEACON_DSN'],
    'release' => '2.0.0',
]));

// Optional: the handlers Beacon::init() would install.
$handlers = ErrorHandler::register($client, $client->getOptions()->errorHandler, $client->getOptions()->shutdownTimeout);

$client->captureMessage('Report generated', 'info');

$handlers->unregister();   // restores the previous handlers (e.g. in tests)
```

A registered handler stays alive until `unregister()`, even if you drop the returned object (a fatal-only handler
has no PHP handler closure keeping it).

```php
```

`Client` implements `ClientInterface` (same methods as BeaconBundle's `BeaconClientInterface`),
`CheckInCapableInterface`, `DeployCapableInterface`, `QueueSnapshotCapableInterface` and `LifecycleInterface`
(`flush()`, `reset()`). Type-hint the interfaces; `NullClient` is the no-op implementation.

## Laravel-style applications

Laravel already installs its own error and exception handlers, so let it report through Beacon and keep only
beacon-php's fatal-error and flush shutdown function. A service provider:

```php
namespace App\Providers;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nowo\Beacon\Beacon;
use Nowo\Beacon\Client;
use Nowo\Beacon\ClientInterface;

final class BeaconServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn (): Client => Beacon::init([
            'dsn'           => (string) config('services.beacon.dsn'),
            'environment'   => app()->environment(),
            'release'       => config('app.version'),
            'framework'     => ['name' => 'laravel', 'version' => Application::VERSION],
            // Laravel reports errors and exceptions itself; keep fatal errors + the bounded shutdown flush.
            'error_handler' => ['errors' => 0, 'exceptions' => false, 'fatal' => true],
        ]));
        $this->app->alias(Client::class, ClientInterface::class);
    }

    public function boot(): void
    {
        // Resolve eagerly: the fatal-error handler and the shutdown flush exist from the first request on.
        $this->app->make(Client::class);
    }
}
```

Report every exception Laravel reports (`bootstrap/app.php`, Laravel 11+):

```php
->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions): void {
    $exceptions->report(static function (Throwable $e): void {
        app(Nowo\Beacon\ClientInterface::class)->captureException($e);
    });
})
```

Request context: under PHP-FPM the default `GlobalsRequestContextProvider` reads `$_SERVER`. Under Octane (or to
use Laravel's resolved route name), give the client a provider built from the current request:

```php
use Nowo\Beacon\Request\RequestContextProviderInterface;
use Nowo\Beacon\Request\RequestSnapshot;

final class LaravelRequestProvider implements RequestContextProviderInterface
{
    public function getRequest(): ?RequestSnapshot
    {
        if (app()->runningInConsole() || !app()->bound('request')) {
            return null;
        }
        $request = request();
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = (string) ($values[0] ?? '');
        }

        return new RequestSnapshot(
            url: $request->fullUrl(),
            method: $request->getMethod(),
            queryString: $request->getQueryString(),
            route: $request->route()?->getName(),
            queryKeys: array_map('strval', array_keys($request->query())),
            clientIp: $request->ip(),            // only sent with send.client = true
            userAgent: $request->userAgent(),
            headers: $headers,                   // only the allow-listed headers are sent
        );
    }
}
// 'request_provider' => new LaravelRequestProvider(),
```

Report each exception through **one** path: either the `report()` hook above, or a `beacon` Monolog channel in the
log stack (Laravel logs every reported exception with `context.exception`). Using both is harmless with the default
`dedupe: true` (the same Throwable instance is sent once), but keep the channel for log records then. A Monolog
channel (`config/logging.php`), using the container binding above, for when you do not use the `report()` hook:

```php
'beacon' => [
    'driver'       => 'monolog',
    'handler'      => Nowo\Beacon\Monolog\BeaconHandler::class,
    'handler_with' => ['level' => 'error'],
],
```

Octane / queue workers: flush and reset after each request or job (see [Long-running workers](#long-running-workers)),
for example in a `RequestTerminated` / `JobProcessed` listener: `Beacon::flush(); Beacon::reset();`.

## Slim and other PSR-15 stacks

`BeaconMiddleware` (needs `psr/http-server-middleware`) exposes the PSR-7 request to the client, captures what
escapes the inner handler and rethrows it, and clears the request afterwards.

```php
use Nowo\Beacon\Beacon;
use Nowo\Beacon\Http\BeaconMiddleware;
use Nowo\Beacon\Request\Psr7RequestContextProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteInterface;

$requests = new Psr7RequestContextProvider(
    // Route pattern for grouping (Slim stores the matched route object in the "__route__" attribute).
    static fn (ServerRequestInterface $r): ?string => ($route = $r->getAttribute('__route__')) instanceof RouteInterface
        ? $route->getPattern()
        : null,
);

$client = Beacon::init([
    'dsn'              => $_ENV['BEACON_DSN'],
    'request_provider' => $requests,
    'framework'        => ['name' => 'slim', 'version' => Slim\App::VERSION],
]);

$app = AppFactory::create();
// Slim runs the last added middleware first: Beacon inside routing, error middleware outermost.
$app->add(new BeaconMiddleware($client, $requests, resetAfterRequest: true));
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, true);
```

`resetAfterRequest: true` flushes and resets the client after every request: use it with servers that keep the
process alive but handle **one request at a time** per process (PHP-FPM, RoadRunner, FrankenPHP worker mode, Octane
without concurrent tasks).

Concurrent runtimes (Swoole / OpenSwoole coroutines, ReactPHP, AMPHP) are **not supported by this setup**: one
client has one scope and one request provider, so two requests in flight would overwrite each other's request,
user and tags, and one request's `reset()` would wipe the other's. There, create a `Client` and a
`Psr7RequestContextProvider` per request (or per coroutine context) and flush it at the end of that request.

## Long-running workers

In a process that handles many requests or jobs, state must not leak from one to the next:

```php
use Nowo\Beacon\Client;
use Nowo\Beacon\Options;

$client = new Client(Options::fromArray([
    'dsn'   => $_ENV['BEACON_DSN'],
    'queue' => ['max_envelopes' => 100, 'max_bytes' => 5 * 1024 * 1024], // deferred, bounded
]));

while ($job = $consumer->next()) {
    $client->setTraceId($job->traceId());     // optional correlation id
    try {
        $handler->handle($job);
    } catch (Throwable $e) {
        $client->captureException($e, ['job' => $job->name()]);
    } finally {
        $client->flush(2.0);                   // deadline 2 s (+ at most one running request's timeout)
        $client->reset();                      // tags, user, extra, breadcrumbs, spans, trace id
    }
}
```

- `reset()` sends nothing; `flush($timeout)` waits at most `$timeout` seconds.
- With `queue`, a 429 / 5xx / network error keeps the envelope (3 attempts) and pauses sending for `Retry-After`
  (≤ 60 s); 401 / 403 drops the queue and pauses for an hour.
- With `async: true` (Symfony HttpClient only) sends start immediately without waiting; `flush()` reads the results.
- Every `Client` owns its state: one client per tenant / project is fine.
- One job at a time per client: never share a client between coroutines or concurrent tasks.

## Monolog

```php
use Monolog\Logger;
use Nowo\Beacon\Monolog\BeaconHandler;

$logger = new Logger('app');
$logger->pushHandler(new BeaconHandler($client, 'error'));   // Monolog 2 or 3; level int, name or Level

$logger->error('Import failed', ['file' => 'a.csv', 'exception' => $e]);   // sent as an exception event
$logger->critical('Queue stalled', ['queue' => 'mail']);                    // sent as a message event
```

Context goes to `extra.context` with secret-like keys redacted; `extra.monolog` carries the channel and level.
Records beacon-php writes about its own transport failures are never forwarded (no report → fail → log loop).

## Context

```php
$client->setTag('area', 'billing');            // ≤ 32 tags, key ≤ 32 chars, value ≤ 200 chars, scalars only
$client->setTags(['tenant' => 'acme', 'plan' => 'pro']);
$client->setUser(['id' => '42', 'username' => 'jane']);   // explicit: you decide what identifies the user
$client->setExtra('import_id', 'imp-9');       // merged into every event (call extra wins)
$client->addBreadcrumb('Cart loaded', 'app', 'info', ['items' => 3]);   // ≤ 50, attached to the next event
$client->setTraceId($request->getHeaderLine('X-Beacon-Trace-Id'));     // invalid ids are ignored
```

Every event carries `tags.trace_id` and `extra.trace_id` (generated when not set) to correlate events of one request.

## before_send

```php
Beacon::init([
    'dsn'         => $dsn,
    'before_send' => static function (array $event): ?array {
        if (($event['exception']['values'][0]['type'] ?? null) === NotFoundHttpException::class) {
            return null;                        // drop
        }
        unset($event['extra']['internal_note']);

        return $event;
    },
]);
```

The hook receives the payload already scrubbed (see [PRIVACY.md](PRIVACY.md)) as plain arrays, and runs for events
and transactions only. Returning anything but an array, or throwing, drops the event. The user IP is removed after
the hook unless `send.client` is enabled.

## Transactions, deploys, check-ins, queue snapshots

```php
$start = microtime(true);
$client->getSpanBuffer()->addTimed('db.sql.query', 'SELECT * FROM orders', 0.012);
// … work …
$client->captureTransaction('GET /orders', $start, microtime(true), [
    ['op' => 'http.client', 'description' => 'GET https://api.example/rates', 'start_timestamp' => $s, 'timestamp' => $e],
]);

$client->captureDeploy(['release' => 'shop@1.4.2', 'commit' => 'abc123', 'url' => 'https://ci.example/run/1']);

$client->captureScheduleCatalog([['name' => 'default', 'entries' => [['recurring_id' => 'nightly', 'trigger' => 'every day at 02:00', 'description' => 'Nightly export']]]]);
$client->captureCheckIn(['run_id' => $runId, 'schedule' => 'default', 'recurring_id' => 'nightly', 'status' => 'in_progress', 'triggered_at' => microtime(true)]);
$client->captureCheckIn(['run_id' => $runId, 'schedule' => 'default', 'recurring_id' => 'nightly', 'status' => 'ok', 'triggered_at' => $t]);

$client->captureQueueSnapshot([['name' => 'mail', 'count' => 12, 'failure' => false], ['name' => 'failed', 'count' => 3, 'failure' => true]]);
```

These metadata envelopes have the same shape as BeaconBundle's and skip `before_send`.

## Error handlers

`ErrorHandler` (installed by `Beacon::init()` unless `error_handler: false`):

| Option (`error_handler.*`) | Default | Meaning |
| --- | --- | --- |
| `errors` | warnings + notices (`ErrorHandler::DEFAULT_LEVELS`) | PHP error levels reported as messages; `0` installs no error handler. `ErrorHandler::levelsFromNames(['warning', 'deprecated'])` builds a mask |
| `capture_silenced` | `false` | also report `@`-silenced errors and levels excluded by `error_reporting()` |
| `swallow` | `false` | without a previous handler, return `true` for reported levels (PHP's own output is skipped) |
| `exceptions` | `true` | install the uncaught-exception handler |
| `fatal` | `true` | report fatal errors (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`) at shutdown |

The previous error / exception handlers always run after beacon-php's. An exception is never captured twice
(including an `ErrorException` a framework built from a warning already reported, and PHP's "Uncaught …" fatal after a
rethrow). 32 KiB are reserved so an out-of-memory fatal can still be reported.
