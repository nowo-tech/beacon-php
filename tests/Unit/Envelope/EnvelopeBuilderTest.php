<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Envelope;

use Exception;
use LogicException;
use Nowo\Beacon\Breadcrumb\BreadcrumbBuffer;
use Nowo\Beacon\Context\UserContextProviderInterface;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Envelope\EnvelopeBuilder;
use Nowo\Beacon\Envelope\EnvelopeDsnMode;
use Nowo\Beacon\Envelope\SendOptions;
use Nowo\Beacon\Request\RequestSnapshot;
use Nowo\Beacon\Scope\Scope;
use Nowo\Beacon\Tests\Fixtures\FixedClock;
use Nowo\Beacon\Tests\Fixtures\StaticRequestProvider;
use Nowo\Beacon\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

use function array_column;
use function count;
use function explode;
use function file_put_contents;
use function json_decode;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_VERSION;

final class EnvelopeBuilderTest extends TestCase
{
    private Dsn $dsn;

    protected function setUp(): void
    {
        $this->dsn = (new DsnParser())->parse('https://pk:topsecret@beacon.example/1');
    }

    public function testDefaultHeaderCarriesNoDsnAndPublicModeHasNoSecret(): void
    {
        $default = (new EnvelopeBuilder(clock: new FixedClock()))->buildDeployEnvelope($this->dsn, ['release' => '1']);
        self::assertStringNotContainsString('topsecret', $default);
        self::assertArrayNotHasKey('dsn', $this->decode($default)[0]);

        $public = (new EnvelopeBuilder(dsnMode: EnvelopeDsnMode::Public))->buildCheckInEnvelope($this->dsn, ['status' => 'ok']);
        self::assertSame('https://pk@beacon.example/1', $this->decode($public)[0]['dsn']);
        self::assertStringNotContainsString('topsecret', $public);

        $full = (new EnvelopeBuilder(dsnMode: EnvelopeDsnMode::Full))->buildScheduleCatalogEnvelope($this->dsn, []);
        self::assertSame('https://pk:topsecret@beacon.example/1', $this->decode($full)[0]['dsn']);
    }

    public function testDefaultsAndContexts(): void
    {
        $builder = new EnvelopeBuilder(framework: ['name' => 'laravel', 'version' => '12.0.0']);
        $payload = $this->decode($builder->buildEventEnvelope($this->dsn, 'hello', 'info'))[2];

        self::assertSame('production', $payload['environment']);
        self::assertSame('unknown', $payload['server_name']);
        self::assertArrayNotHasKey('release', $payload);
        self::assertSame(['name' => 'php', 'version' => PHP_VERSION], $payload['contexts']['runtime']);
        self::assertSame(['name' => 'laravel', 'version' => '12.0.0'], $payload['contexts']['framework']);
        self::assertArrayHasKey('os', $payload['contexts']);
        self::assertArrayNotHasKey('tags', $payload);
        self::assertArrayNotHasKey('extra', $payload);
        // Message events carry the caller's stack trace, without beacon-php frames.
        self::assertArrayHasKey('stacktrace', $payload);
        $functions = array_column($payload['stacktrace']['frames'], 'function');
        self::assertContains(self::class . '->testDefaultsAndContexts', $functions);
        foreach ($functions as $function) {
            self::assertStringStartsNotWith('Nowo\\Beacon\\Envelope', (string) $function);
        }
        self::assertSame(self::class . '->testDefaultsAndContexts', $payload['culprit']);
    }

    public function testInternalPathsCanHideEveryFrameAndPrefixesHideAdapterFrames(): void
    {
        $all = new EnvelopeBuilder(internalPaths: ['/']);
        self::assertArrayNotHasKey('stacktrace', $this->decode($all->buildEventEnvelope($this->dsn, 'm'))[2]);

        $prefixed = new EnvelopeBuilder(internalClassPrefixes: ['Nowo\\Beacon\\Tests\\']);
        $payload  = $this->decode($prefixed->buildEventEnvelope($this->dsn, 'm'))[2];
        foreach (array_column($payload['stacktrace']['frames'], 'function') as $function) {
            self::assertStringStartsNotWith('Nowo\\Beacon\\Tests\\', (string) $function);
        }
    }

    public function testFramesWithoutFileAreKept(): void
    {
        $builder = new EnvelopeBuilder(sendOptions: new SendOptions(runtime: false, os: false));
        $bodies  = array_map(fn (int $i): string => $builder->buildEventEnvelope($this->dsn, 'from callback ' . $i), [1]);

        $frames = $this->decode($bodies[0])[2]['stacktrace']['frames'];
        self::assertContains('[internal]', array_column($frames, 'filename'));
    }

    public function testMessageStackVarsAreRedacted(): void
    {
        $builder = new EnvelopeBuilder(sendOptions: new SendOptions(stackVars: true));
        $secret  = strrev('2retnuh'); // not a literal: the source line is part of the frame context
        $body    = $this->buildFrom($builder, ['password' => $secret, 'page' => 2]);

        self::assertStringNotContainsString($secret, $body);
        self::assertStringContainsString('"input":{"password":"[Filtered]","page":2}', $body);
    }

    public function testScopeUserWinsOverProviderAndScopeExtraMerges(): void
    {
        $scope = new Scope();
        $scope->setExtra('job', 'import');
        $scope->setExtra('k', 'scope');
        $provider = new class implements UserContextProviderInterface {
            /** @var array{id?: string}|null */
            public ?array $user = ['id' => 'provider'];

            public function getUserContext(): ?array
            {
                return $this->user;
            }
        };
        $builder = new EnvelopeBuilder(sendOptions: new SendOptions(stacktrace: false, user: true), userContextProvider: $provider, scope: $scope);

        $payload = $this->decode($builder->buildEventEnvelope($this->dsn, 'm', 'error', null, ['k' => 'call']))[2];
        self::assertSame(['id' => 'provider'], $payload['user']);
        self::assertSame(['k' => 'call', 'job' => 'import'], $payload['extra']);

        $scope->setUser(['id' => 'explicit']);
        self::assertSame(['id' => 'explicit'], $this->decode($builder->buildEventEnvelope($this->dsn, 'm'))[2]['user']);

        $scope->setUser(null);
        $provider->user = [];
        self::assertArrayNotHasKey('user', $this->decode($builder->buildEventEnvelope($this->dsn, 'm'))[2]);
        $provider->user = null;
        self::assertArrayNotHasKey('user', $this->decode($builder->buildEventEnvelope($this->dsn, 'm'))[2]);

        $noUser         = new EnvelopeBuilder(sendOptions: new SendOptions(stacktrace: false), userContextProvider: $provider);
        $provider->user = ['id' => 'x'];
        self::assertArrayNotHasKey('user', $this->decode($noUser->buildEventEnvelope($this->dsn, 'm'))[2]);
    }

    public function testTraceIdSeedsTagAndExtraAndBreadcrumbsAreConsumed(): void
    {
        $scope  = new Scope();
        $trace  = new TraceIdProvider();
        $crumbs = new BreadcrumbBuffer();
        $crumbs->add('one');
        $builder = new EnvelopeBuilder(sendOptions: new SendOptions(stacktrace: false), breadcrumbBuffer: $crumbs, scope: $scope, traceIdProvider: $trace);

        $payload = $this->decode($builder->buildTransactionEnvelope($this->dsn, 'job', 1.0, 2.0))[2];
        self::assertSame($trace->get(), $payload['tags']['trace_id']);
        self::assertSame($trace->get(), $payload['extra']['trace_id']);
        self::assertSame('one', $payload['breadcrumbs']['values'][0]['message']);
        self::assertSame([], $crumbs->all());

        $withoutScope = new EnvelopeBuilder(sendOptions: new SendOptions(stacktrace: false), traceIdProvider: $trace);
        $payload      = $this->decode($withoutScope->buildEventEnvelope($this->dsn, 'm'))[2];
        self::assertArrayNotHasKey('tags', $payload);
        self::assertSame($trace->get(), $payload['extra']['trace_id']);
    }

    public function testRequestIsOptionalAndPartial(): void
    {
        $provider = new StaticRequestProvider();
        $builder  = new EnvelopeBuilder(sendOptions: new SendOptions(stacktrace: false, client: true), requestProvider: $provider);
        self::assertArrayNotHasKey('request', $this->decode($builder->buildEventEnvelope($this->dsn, 'm'))[2]);

        $provider->request = new RequestSnapshot('https://h/x', 'GET', null, '', '', [], '', '');
        $payload           = $this->decode($builder->buildEventEnvelope($this->dsn, 'm'))[2];
        self::assertSame(['url' => 'https://h/x', 'method' => 'GET', 'query_string' => ''], $payload['request']);
        self::assertArrayNotHasKey('http', $payload['extra']);

        $off = new EnvelopeBuilder(sendOptions: new SendOptions(stacktrace: false, request: false), requestProvider: $provider);
        self::assertArrayNotHasKey('request', $this->decode($off->buildEventEnvelope($this->dsn, 'm'))[2]);
    }

    public function testCulpritResolverAndEmptyTraceFallback(): void
    {
        $root    = new LogicException('root');
        $wrapper = new RuntimeException('wrapper', 0, $root);
        (new ReflectionProperty(Exception::class, 'trace'))->setValue($root, [['function' => 'handle_it']]);

        $builder = new EnvelopeBuilder(sendOptions: new SendOptions(runtime: false, os: false), culpritThrowableResolver: static fn (Throwable $t): Throwable => $t->getPrevious() ?? $t);
        self::assertSame('handle_it', $this->decode($builder->buildEventEnvelope($this->dsn, 'm', 'error', $wrapper))[2]['culprit']);
    }

    public function testSourceContextLimitsAndCache(): void
    {
        $small = (string) tempnam(sys_get_temp_dir(), 'bphp');
        file_put_contents($small, "l1\nl2\nl3\n");
        $big = (string) tempnam(sys_get_temp_dir(), 'bphp');
        file_put_contents($big, str_repeat("x\n", EnvelopeBuilder::MAX_SOURCE_FILE_BYTES));

        try {
            $builder = new EnvelopeBuilder(sendOptions: new SendOptions(runtime: false, os: false), stackContextLines: 1);

            $frames = $this->exceptionFrames($builder, $this->shaped($small, 2));
            self::assertSame(['l1'], $frames[0]['pre_context']);
            self::assertSame('l2', $frames[0]['context_line']);
            self::assertSame(['l3'], $frames[0]['post_context']);

            // Cached copy (file deleted afterwards would still be served from the LRU).
            self::assertSame('l2', $this->exceptionFrames($builder, $this->shaped($small, 2))[0]['context_line']);
            self::assertArrayNotHasKey('context_line', $this->exceptionFrames($builder, $this->shaped($small, 99))[0]);
            self::assertArrayNotHasKey('context_line', $this->exceptionFrames($builder, $this->shaped($big, 1))[0]);
            self::assertArrayNotHasKey('context_line', $this->exceptionFrames($builder, $this->shaped('/nonexistent/file.php', 1))[0]);

            for ($i = 0; $i < EnvelopeBuilder::SOURCE_LINE_CACHE_MAX_FILES + 2; ++$i) {
                $this->exceptionFrames($builder, $this->shaped('/nonexistent/' . $i . '.php', 1));
            }
            $cache = (new ReflectionProperty(EnvelopeBuilder::class, 'sourceLineCache'))->getValue($builder);
            self::assertIsArray($cache);
            self::assertCount(EnvelopeBuilder::SOURCE_LINE_CACHE_MAX_FILES, $cache);
        } finally {
            unlink($small);
            unlink($big);
        }
    }

    public function testWindowsPathsAndInternalFrames(): void
    {
        $builder = new EnvelopeBuilder(sendOptions: new SendOptions(runtime: false, os: false));
        $frames  = $this->exceptionFrames($builder, $this->shaped('C:\\app\\vendor\\lib\\X.php', 3, [['file' => '', 'line' => 1, 'function' => 'f']]));

        self::assertFalse($frames[count($frames) - 1]['in_app']);
        self::assertSame('', $frames[0]['filename']);
        self::assertFalse($frames[0]['in_app']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function buildFrom(EnvelopeBuilder $builder, array $input): string
    {
        return $builder->buildEventEnvelope($this->dsn, 'with vars ' . count($input));
    }

    /**
     * @param list<array<string, mixed>> $trace
     */
    private function shaped(string $file, int $line, array $trace = []): RuntimeException
    {
        $exception = new RuntimeException('x');
        (new ReflectionProperty(Exception::class, 'file'))->setValue($exception, $file);
        (new ReflectionProperty(Exception::class, 'line'))->setValue($exception, $line);
        (new ReflectionProperty(Exception::class, 'trace'))->setValue($exception, $trace);

        return $exception;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exceptionFrames(EnvelopeBuilder $builder, Throwable $throwable): array
    {
        $payload = $this->decode($builder->buildEventEnvelope($this->dsn, 'm', 'error', $throwable))[2];

        /* @var list<array<string, mixed>> */
        return $payload['exception']['values'][0]['stacktrace']['frames'];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function decode(string $body): array
    {
        $lines = explode("\n", $body);

        /* @var array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} */
        return [
            json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR),
            json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR),
            json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
