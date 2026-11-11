<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit;

use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\Envelope\EnvelopeDsnMode;
use Nowo\Beacon\ErrorHandler\ErrorHandler;
use Nowo\Beacon\Exception\ConfigurationException;
use Nowo\Beacon\Ingest\IngestPathMode;
use Nowo\Beacon\Ingest\IngestPathResolver;
use Nowo\Beacon\Options;
use Nowo\Beacon\Tests\Fixtures\ArrayLogger;
use Nowo\Beacon\Tests\Fixtures\FakePsr18Client;
use Nowo\Beacon\Tests\Fixtures\FixedClock;
use Nowo\Beacon\Tests\Fixtures\RecordingTransport;
use Nowo\Beacon\Tests\Fixtures\StaticRequestProvider;
use Nowo\Beacon\Transport\QueuedTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function putenv;

use const INF;
use const NAN;

final class OptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('BEACON_DSN');
        putenv('BEACON_ENVIRONMENT');
        putenv('BEACON_RELEASE');
    }

    public function testDefaults(): void
    {
        $options = Options::fromDsn('https://pk:sk@b.example/1');

        self::assertTrue($options->isActive());
        self::assertSame('production', $options->environment);
        self::assertNull($options->release);
        self::assertNotSame('', $options->serverName);
        self::assertSame(5.0, $options->timeout);
        self::assertSame(2.0, $options->shutdownTimeout);
        self::assertSame(EnvelopeDsnMode::None, $options->envelopeDsn);
        self::assertTrue($options->scrub);
        self::assertTrue($options->dedupe);
        self::assertFalse($options->async);
        self::assertNull($options->queue);
        self::assertFalse($options->send->client);
        self::assertSame(['errors' => ErrorHandler::DEFAULT_LEVELS, 'capture_silenced' => false, 'exceptions' => true, 'fatal' => true, 'swallow' => false], $options->errorHandler);
        self::assertSame([], $options->internalPaths);
        self::assertSame(IngestPathMode::Auto, $options->ingestPaths);
        self::assertSame(IngestPathResolver::shared(), $options->ingestPathResolver);
    }

    public function testIngestPathsOptions(): void
    {
        $versioned = Options::fromArray(['dsn' => '', 'ingest_paths' => 'versioned']);
        self::assertSame(IngestPathMode::Versioned, $versioned->ingestPaths);
        self::assertSame(IngestPathMode::Versioned, $versioned->ingestPathResolver->mode());
        self::assertNotSame(IngestPathResolver::shared(), $versioned->ingestPathResolver);

        self::assertSame(IngestPathMode::Legacy, Options::fromArray(['dsn' => '', 'ingest_paths' => IngestPathMode::Legacy])->ingestPathResolver->mode());
        self::assertSame(IngestPathResolver::shared(), Options::fromArray(['dsn' => '', 'ingest_paths' => 'auto'])->ingestPathResolver);

        $own     = new IngestPathResolver('legacy');
        $options = Options::fromArray(['dsn' => '', 'ingest_path_resolver' => $own]);
        self::assertSame($own, $options->ingestPathResolver);
        self::assertSame(IngestPathMode::Legacy, $options->ingestPaths);
        self::assertSame($own, Options::fromArray(['dsn' => '', 'ingest_path_resolver' => $own, 'ingest_paths' => 'legacy'])->ingestPathResolver);
    }

    public function testEnvironmentVariablesFillMissingKeys(): void
    {
        putenv('BEACON_DSN=https://pk:sk@env.example/2');
        putenv('BEACON_ENVIRONMENT=staging');
        putenv('BEACON_RELEASE=9.9.9');

        $options = Options::fromArray([]);
        self::assertSame('env.example', $options->dsn?->getHost());
        self::assertSame('staging', $options->environment);
        self::assertSame('9.9.9', $options->release);

        $explicit = Options::fromArray(['dsn' => null, 'environment' => 'dev', 'release' => '']);
        self::assertFalse($explicit->isActive());
        self::assertNull($explicit->release);
    }

    public function testFullConfiguration(): void
    {
        $transport = new RecordingTransport();
        $options   = Options::fromArray([
            'dsn'                     => 'https://pk:sk@b.example/1',
            'enabled'                 => true,
            'environment'             => 'test',
            'release'                 => '1.0.0',
            'server_name'             => 'web-1',
            'send'                    => ['client' => true],
            'stack_context_lines'     => 2,
            'timeout'                 => 1,
            'verify_peer'             => false,
            'shutdown_timeout'        => 0.5,
            'before_send'             => 'array_filter',
            'transport'               => $transport,
            'http_client'             => new FakePsr18Client(),
            'async'                   => true,
            'queue'                   => ['max_envelopes' => 5, 'max_bytes' => 100],
            'logger'                  => new ArrayLogger(),
            'clock'                   => new FixedClock(),
            'envelope_dsn'            => 'public',
            'scrub'                   => false,
            'error_handler'           => ['errors' => 0, 'fatal' => false],
            'framework'               => ['name' => 'slim', 'version' => '4.14.0'],
            'request_provider'        => new StaticRequestProvider(),
            'culprit_resolver'        => static fn ($t) => $t,
            'internal_class_prefixes' => ['App\\Beacon\\'],
            'internal_paths'          => ['/srv/lib/'],
        ]);

        self::assertSame('web-1', $options->serverName);
        self::assertSame(1.0, $options->timeout);
        self::assertSame(['max_envelopes' => 5, 'max_bytes' => 100], $options->queue);
        self::assertSame(EnvelopeDsnMode::Public, $options->envelopeDsn);
        self::assertSame(['name' => 'slim', 'version' => '4.14.0'], $options->framework);
        self::assertSame(0, $options->errorHandler['errors'] ?? null);
        self::assertSame($transport, $options->transport);
        self::assertNotNull($options->beforeSend);
        self::assertSame(['App\\Beacon\\'], $options->internalClassPrefixes);

        self::assertSame(['max_envelopes' => QueuedTransport::DEFAULT_MAX_ENVELOPES, 'max_bytes' => QueuedTransport::DEFAULT_MAX_BYTES], Options::fromArray(['dsn' => '', 'queue' => true])->queue);
        self::assertNull(Options::fromArray(['dsn' => '', 'error_handler' => false])->errorHandler);
        self::assertSame(EnvelopeDsnMode::Full, Options::fromArray(['dsn' => '', 'envelope_dsn' => EnvelopeDsnMode::Full])->envelopeDsn);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['dns' => 'x'], 'Unknown Beacon option "dns"'];
        yield 'dsn type' => [['dsn' => 5], 'The "dsn" option must be a string or null.'];
        yield 'send type' => [['send' => true], 'The "send" option must be an array'];
        yield 'envelope dsn' => [['envelope_dsn' => 'secret'], 'The "envelope_dsn" option must be'];
        yield 'envelope dsn type' => [['envelope_dsn' => 1], 'The "envelope_dsn" option must be'];
        yield 'enabled' => [['enabled' => 1], 'The "enabled" option must be a boolean.'];
        yield 'environment' => [['environment' => ''], 'The "environment" option must be a non-empty string.'];
        yield 'release' => [['release' => 5], 'The "release" option must be a non-empty string.'];
        yield 'server name' => [['server_name' => 7], 'The "server_name" option must be a non-empty string.'];
        yield 'context lines' => [['stack_context_lines' => -1], 'The "stack_context_lines" option must be an integer >= 0.'];
        yield 'timeout' => [['timeout' => 0], 'The "timeout" option must be a finite number between 0.1 and 60.'];
        yield 'timeout type' => [['timeout' => '5'], 'The "timeout" option must be a finite number'];
        yield 'timeout nan' => [['timeout' => NAN], 'The "timeout" option must be a finite number between 0.1 and 60.'];
        yield 'timeout too long' => [['timeout' => 61], 'The "timeout" option must be a finite number'];
        yield 'shutdown inf' => [['shutdown_timeout' => INF], 'The "shutdown_timeout" option must be a finite number between 0 and 300.'];
        yield 'shutdown nan' => [['shutdown_timeout' => NAN], 'The "shutdown_timeout" option must be a finite number'];
        yield 'dedupe' => [['dedupe' => 'yes'], 'The "dedupe" option must be a boolean.'];
        yield 'before send' => [['before_send' => 'not_a_function_anywhere'], 'The "before_send" option must be callable.'];
        yield 'transport' => [['transport' => new stdClass()], 'The "transport" option must be an instance of'];
        yield 'http client' => [['http_client' => new stdClass()], 'The "http_client" option must be a PSR-18 client'];
        yield 'queue type' => [['queue' => 'yes'], 'The "queue" option must be a boolean or an array'];
        yield 'queue key' => [['queue' => ['size' => 1]], 'Unknown "queue" option "size".'];
        yield 'queue value' => [['queue' => ['max_envelopes' => 0]], 'The "max_envelopes" option must be an integer >= 1.'];
        yield 'error handler type' => [['error_handler' => 'on'], 'The "error_handler" option must be a boolean or an array.'];
        yield 'error handler key' => [['error_handler' => ['levels' => 1]], 'Unknown "error_handler" option "levels".'];
        yield 'framework' => [['framework' => ['name' => 'x']], 'The "framework" option must be null or'];
        yield 'prefixes type' => [['internal_paths' => 'x'], 'The "internal_paths" option must be a list of strings.'];
        yield 'ingest paths value' => [['ingest_paths' => 'v2'], 'The "ingest_paths" option must be "auto", "versioned" or "legacy".'];
        yield 'ingest paths type' => [['ingest_paths' => true], 'The "ingest_paths" option must be "auto", "versioned" or "legacy".'];
        yield 'ingest path resolver' => [['ingest_path_resolver' => new stdClass()], 'The "ingest_path_resolver" option must be an instance of'];
        yield 'ingest paths mismatch' => [['ingest_paths' => 'legacy', 'ingest_path_resolver' => new IngestPathResolver()], 'The "ingest_paths" option and the mode of "ingest_path_resolver" differ.'];
        yield 'prefixes item' => [['internal_paths' => ['']], 'The "internal_paths" option must be a list of non-empty strings.'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidOptions(array $options, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        Options::fromArray($options + ['dsn' => '']);
    }

    public function testInvalidDsnMessageHasNoSecret(): void
    {
        try {
            Options::fromDsn('ftp://pk:verysecret@b.example/1');
            self::fail('Expected InvalidDsnException');
        } catch (InvalidDsnException $exception) {
            self::assertStringNotContainsString('verysecret', $exception->getMessage());
        }
    }
}
