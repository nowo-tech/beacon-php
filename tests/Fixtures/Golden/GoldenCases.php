<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures\Golden;

use Closure;
use DateTimeImmutable;
use Error;
use Exception;
use LogicException;
use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer;
use Nowo\Beacon\Context\UserContextProviderInterface;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Envelope\EnvelopeBuilder;
use Nowo\Beacon\Envelope\EnvelopeDsnMode;
use Nowo\Beacon\Envelope\SendOptions;
use Nowo\Beacon\Request\RequestSnapshot;
use Nowo\Beacon\Scope\Scope;
use Nowo\Beacon\Tests\Fixtures\FixedClock;
use Nowo\Beacon\Tests\Fixtures\StaticRequestProvider;
use Nowo\Beacon\Tests\Golden\BundleGoldenTest;
use Nowo\Beacon\Tests\Parity\BundleParityTest;
use Nowo\Beacon\Tests\Parity\BundleUserProvider;
use Nowo\Beacon\Trace\TraceIdProvider;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer as BundleBreadcrumbBuffer;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder as BundleEnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\SendOptions as BundleSendOptions;
use Nowo\BeaconBundle\Scope\Scope as BundleScope;
use Nowo\BeaconBundle\Trace\TraceIdProvider as BundleTraceIdProvider;
use PDOException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

use function array_keys;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_replace;
use function rtrim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Deterministic inputs fed to both beacon-php's EnvelopeBuilder (header mode Full, scrubbing off) and BeaconBundle
 * 1.19's EnvelopeBuilder. Output is normalised the same way for both (event id → `bbbb…`, breadcrumb timestamps → 0)
 * and compared byte for byte, live ({@see BundleParityTest}) and against the recorded
 * files in `tests/Fixtures/Golden/bundle-1.19/` ({@see BundleGoldenTest}).
 *
 * Inputs avoid environment-dependent data (PHP version, OS release, absolute paths of real files).
 */
final class GoldenCases
{
    public const DSN = 'https://pubkey:secret@beacon.example.com:9447/1';

    public const EVENT_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const TRACE_ID = 'trace-golden-0001';

    public const CLOCK = '2024-07-20T16:01:00.123456Z';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * beacon-php output for one case (normalised).
     */
    public static function core(string $name): string
    {
        $case = self::definitions()[$name];

        return self::normalize(self::run($case, self::coreBuilderFactory()));
    }

    /**
     * BeaconBundle output for one case (normalised); needs the bundle classes (BEACON_BUNDLE_SRC).
     */
    public static function bundle(string $name): string
    {
        $case = self::definitions()[$name];

        return self::normalize(self::run($case, self::bundleBuilderFactory()));
    }

    /**
     * Replace the random event id and the breadcrumb timestamps (microtime) by constants.
     */
    public static function normalize(string $body): string
    {
        $lines = explode("\n", rtrim($body, "\n"));
        foreach ($lines as $index => $line) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (isset($decoded['breadcrumbs']) && is_array($decoded['breadcrumbs']) && isset($decoded['breadcrumbs']['values']) && is_array($decoded['breadcrumbs']['values'])) {
                foreach ($decoded['breadcrumbs']['values'] as $i => $crumb) {
                    if (is_array($crumb)) {
                        $crumb['timestamp']                   = 0.5;
                        $decoded['breadcrumbs']['values'][$i] = $crumb;
                    }
                }
                $line = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $lines[$index] = (string) preg_replace('/"event_id":"[0-9a-f]{32}"/', '"event_id":"' . self::EVENT_ID . '"', $line);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array{send: array<string, bool>, request?: array<string, mixed>, user?: array<string, string>, tags?: array<string, string>, breadcrumbs?: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>, build: Closure(object, object): string} $case
     * @param Closure(array<string, mixed>): array{0: object, 1: object} $factory returns [builder, dsn]
     */
    private static function run(array $case, Closure $factory): string
    {
        [$builder, $dsn] = $factory($case);

        return ($case['build'])($builder, $dsn);
    }

    /**
     * @return Closure(array<string, mixed>): array{0: object, 1: object}
     */
    private static function coreBuilderFactory(): Closure
    {
        return static function (array $case): array {
            $scope = new Scope();
            $scope->setTags($case['tags'] ?? []);
            $crumbs = new BreadcrumbBuffer();
            foreach ($case['breadcrumbs'] ?? [] as $crumb) {
                $crumbs->add($crumb[0], $crumb[1], $crumb[2], $crumb[3]);
            }
            $trace = new TraceIdProvider();
            $trace->set(self::TRACE_ID);

            $request = null;
            if (isset($case['request'])) {
                $request = new StaticRequestProvider(self::snapshot(self::symfonyRequest($case['request'])));
            }

            $user = isset($case['user']) ? new class($case['user']) implements UserContextProviderInterface {
                /** @param array<string, string> $user */
                public function __construct(private readonly array $user)
                {
                }

                public function getUserContext(): array
                {
                    return $this->user;
                }
            } : null;

            $builder = new EnvelopeBuilder(
                environment: 'test',
                release: '1.2.3',
                serverName: 'ci-host',
                sendOptions: SendOptions::fromArray($case['send']),
                userContextProvider: $user,
                breadcrumbBuffer: $crumbs,
                requestProvider: $request,
                scope: $scope,
                traceIdProvider: $trace,
                clock: new FixedClock(new DateTimeImmutable(self::CLOCK)),
                dsnMode: EnvelopeDsnMode::Full,
                eventIdGenerator: static fn (): string => self::EVENT_ID,
            );

            return [$builder, (new DsnParser())->parse(self::DSN)];
        };
    }

    /**
     * @return Closure(array<string, mixed>): array{0: object, 1: object}
     */
    private static function bundleBuilderFactory(): Closure
    {
        return static function (array $case): array {
            $scope = new BundleScope();
            $scope->setTags($case['tags'] ?? []);
            $crumbs = new BundleBreadcrumbBuffer();
            foreach ($case['breadcrumbs'] ?? [] as $crumb) {
                $crumbs->add($crumb[0], $crumb[1], $crumb[2], $crumb[3]);
            }
            $trace = new BundleTraceIdProvider();
            $trace->set(self::TRACE_ID);

            $stack = null;
            if (isset($case['request'])) {
                $stack = new RequestStack([self::symfonyRequest($case['request'])]);
            }

            $user = isset($case['user']) ? new BundleUserProvider($case['user']) : null;

            $builder = new BundleEnvelopeBuilder(
                environment: 'test',
                release: '1.2.3',
                serverName: 'ci-host',
                sendOptions: BundleSendOptions::fromArray($case['send']),
                userContextProvider: $user,
                breadcrumbBuffer: $crumbs,
                requestStack: $stack,
                scope: $scope,
                traceIdProvider: $trace,
                clock: new FixedClock(new DateTimeImmutable(self::CLOCK)),
            );

            return [$builder, (new BeaconDsnParser())->parse(self::DSN)];
        };
    }

    /**
     * What BeaconBundle's adapter will do: Symfony Request → RequestSnapshot.
     */
    public static function snapshot(Request $request): RequestSnapshot
    {
        $headers = [];
        foreach (array_keys($request->headers->all()) as $name) {
            $headers[(string) $name] = (string) $request->headers->get((string) $name);
        }

        $route      = $request->attributes->get('_route');
        $controller = $request->attributes->get('_controller');
        $ip         = $request->getClientIp();
        $ua         = $request->headers->get('User-Agent');

        $queryKeys = [];
        foreach (array_keys($request->query->all()) as $key) {
            $queryKeys[] = (string) $key;
        }

        return new RequestSnapshot(
            url: $request->getUri(),
            method: $request->getMethod(),
            queryString: $request->getQueryString(),
            route: is_string($route) ? $route : null,
            controller: is_string($controller) ? $controller : null,
            queryKeys: $queryKeys,
            clientIp: $ip,
            userAgent: is_string($ua) ? $ua : null,
            headers: $headers,
        );
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function symfonyRequest(array $spec): Request
    {
        /** @var array<string, string> $server */
        $server  = $spec['server'] ?? [];
        $request = Request::create((string) $spec['uri'], (string) ($spec['method'] ?? 'GET'), [], [], [], $server);
        /** @var array<string, string> $attributes */
        $attributes = $spec['attributes'] ?? [];
        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }

        return $request;
    }

    /**
     * Exception chain with fixed files, lines and traces (no real file: no source context, stable everywhere).
     */
    public static function checkoutException(): RuntimeException
    {
        $inner = self::shape(new LogicException('card gateway said api_key=sk_live_0123456789abcdef'), '/srv/app/src/Gateway/Card.php', 77, [
            ['file' => '/srv/app/src/Checkout.php', 'line' => 40, 'function' => 'charge', 'class' => 'App\\Gateway\\Card', 'type' => '->'],
        ]);

        return self::shape(new RuntimeException('payment declined', 0, $inner), '/srv/app/src/Checkout.php', 42, [
            ['file' => '/srv/app/src/Controller/PayController.php', 'line' => 18, 'function' => 'pay', 'class' => 'App\\Checkout', 'type' => '->'],
            ['file' => '/srv/app/vendor/symfony/http-kernel/HttpKernel.php', 'line' => 181, 'function' => 'handleRaw', 'class' => 'Symfony\\Component\\HttpKernel\\HttpKernel', 'type' => '->'],
            ['function' => '{closure}'],
            ['file' => '/srv/app/public/index.php', 'line' => 9, 'function' => 'require_once'],
        ]);
    }

    public static function databaseException(): PDOException
    {
        $exception            = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane@example.com' for key 'email' (Connection: mysql, SQL: insert into users (email) values ('jane@example.com'))");
        $exception->errorInfo = ['23000', 1062, "Duplicate entry 'jane@example.com'"];

        return self::shape($exception, '/srv/app/src/Repository/UserRepository.php', 12, []);
    }

    /**
     * @param list<array<string, mixed>> $trace
     *
     * @template T of Throwable
     *
     * @param T $throwable
     *
     * @return T
     */
    private static function shape(Throwable $throwable, string $file, int $line, array $trace): Throwable
    {
        $class = $throwable instanceof Exception ? Exception::class : Error::class;
        (new ReflectionProperty($class, 'file'))->setValue($throwable, $file);
        (new ReflectionProperty($class, 'line'))->setValue($throwable, $line);
        (new ReflectionProperty($class, 'trace'))->setValue($throwable, $trace);

        return $throwable;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(): array
    {
        $offline = ['runtime' => false, 'os' => false, 'framework' => false];

        return [
            'event_message' => [
                'send'  => $offline + ['stacktrace' => false],
                'tags'  => ['area' => 'billing', 'tenant' => 'acme'],
                'build' => static fn (object $b, object $dsn): string => $b->buildEventEnvelope($dsn, 'checkout failed password=hunter2 Bearer abcdefgh12345678', 'warning', null, ['order' => 42, 'nested' => ['a' => [1, 2], 'empty' => []]], ['checkout', 'failed']),
            ],
            'event_exception' => [
                'send'  => $offline,
                'build' => static fn (object $b, object $dsn): string => $b->buildEventEnvelope($dsn, '', 'error', self::checkoutException(), ['handled' => false]),
            ],
            'event_exception_no_stacktrace' => [
                'send'  => $offline + ['stacktrace' => false, 'environment' => false, 'release' => false, 'server_name' => false],
                'build' => static fn (object $b, object $dsn): string => $b->buildEventEnvelope($dsn, 'wrapped: payment declined', 'error', self::checkoutException()),
            ],
            'event_database' => [
                'send'  => $offline,
                'build' => static fn (object $b, object $dsn): string => $b->buildEventEnvelope($dsn, 'db failure', 'error', self::databaseException()),
            ],
            'event_request' => [
                'send'    => $offline + ['stacktrace' => false],
                'request' => [
                    'uri'    => 'https://user:pass@shop.example/checkout/pay?token=abc123&page=2&api_key=k3y&q=caf%C3%A9',
                    'method' => 'POST',
                    'server' => [
                        'HTTP_USER_AGENT'      => 'Mozilla/5.0 Golden',
                        'HTTP_ACCEPT'          => 'text/html',
                        'HTTP_REFERER'         => 'https://shop.example/login?password=hunter2&next=%2Fhome',
                        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
                        'HTTP_COOKIE'          => 'session=secret',
                        'HTTP_AUTHORIZATION'   => 'Bearer abcdefgh12345678',
                        'REMOTE_ADDR'          => '198.51.100.7',
                    ],
                    'attributes' => ['_route' => 'checkout_pay', '_controller' => 'App\\Controller\\PayController::__invoke'],
                ],
                'build' => static fn (object $b, object $dsn): string => $b->buildEventEnvelope($dsn, 'payment failed', 'error', null, ['request_uri' => 'kept-by-caller']),
            ],
            'event_request_client' => [
                'send'    => $offline + ['stacktrace' => false, 'client' => true],
                'request' => [
                    'uri'    => 'https://shop.example/health',
                    'server' => ['HTTP_USER_AGENT' => 'curl/8.0', 'REMOTE_ADDR' => '198.51.100.7'],
                ],
                'build' => static fn (object $b, object $dsn): string => $b->buildEventEnvelope($dsn, 'health degraded', 'info'),
            ],
            'event_user_breadcrumbs' => [
                'send'        => $offline + ['stacktrace' => false, 'user' => true],
                'user'        => ['id' => '42', 'username' => 'jane', 'email' => 'jane@example.com'],
                'breadcrumbs' => [
                    ['cart loaded', 'app', 'info', ['items' => 3]],
                    ['GET https://api.example/v1', 'http', 'debug', ['status' => 200, 'url' => 'https://api.example/v1?token=x']],
                ],
                'build' => static fn (object $b, object $dsn): string => $b->buildEventEnvelope($dsn, 'checkout ünïcödé ✓', 'error', null, [], ['ü']),
            ],
            'transaction' => [
                'send'        => $offline,
                'tags'        => ['route' => 'checkout'],
                'breadcrumbs' => [['sql', 'db', 'info', []]],
                'request'     => ['uri' => 'https://shop.example/checkout', 'method' => 'GET'],
                'build'       => static fn (object $b, object $dsn): string => $b->buildTransactionEnvelope($dsn, 'GET /checkout', 1721491260.25, 1721491260.75, [
                    ['op' => 'db.sql.query', 'description' => 'SELECT 1', 'span_id' => 'aaaaaaaaaaaaaaaa', 'start_timestamp' => 1721491260.3, 'timestamp' => 1721491260.4],
                ], ['status_code' => 200]),
            ],
            'deploy' => [
                'send'  => $offline,
                'build' => static fn (object $b, object $dsn): string => $b->buildDeployEnvelope($dsn, ['release' => '1.2.3', 'commit' => 'abc123', 'url' => 'https://ci.example/run/1', 'name' => '', 'deployed_at' => 1721491200.5]),
            ],
            'deploy_defaults' => [
                'send'  => $offline + ['environment' => false],
                'build' => static fn (object $b, object $dsn): string => $b->buildDeployEnvelope($dsn, ['release' => '1.2.4']),
            ],
            'queue_snapshot' => [
                'send'  => $offline,
                'build' => static fn (object $b, object $dsn): string => $b->buildQueueSnapshotEnvelope($dsn, [['name' => 'async', 'count' => 3, 'failure' => false], ['name' => 'failed', 'count' => null, 'failure' => true]]),
            ],
            'schedule_catalog' => [
                'send'  => $offline,
                'build' => static fn (object $b, object $dsn): string => $b->buildScheduleCatalogEnvelope($dsn, [['name' => 'default', 'entries' => [['recurring_id' => 'r1', 'trigger' => 'every 5 minutes', 'description' => null]]]]),
            ],
            'check_in' => [
                'send'  => $offline + ['server_name' => false],
                'build' => static fn (object $b, object $dsn): string => $b->buildCheckInEnvelope($dsn, ['run_id' => 'run-1', 'schedule' => 'default', 'recurring_id' => 'r1', 'status' => 'error', 'triggered_at' => 1721491260.0, 'error' => 'RuntimeException']),
            ],
        ];
    }
}
