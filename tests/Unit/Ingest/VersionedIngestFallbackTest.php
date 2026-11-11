<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Ingest;

use Closure;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Ingest\IngestPathMode;
use Nowo\Beacon\Ingest\IngestPathResolver;
use Nowo\Beacon\Tests\Fixtures\ArrayLogger;
use Nowo\Beacon\Transport\Psr18Transport;
use Nowo\Beacon\Transport\SymfonyHttpClientTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_column;
use function array_shift;

/**
 * Versioned ingest path with a one-time legacy fallback (`ingest_paths: auto`), same cases as beacon-js #8
 * (ingest-paths.test.ts) and BeaconBundle #43 (VersionedIngestFallbackTest).
 */
final class VersionedIngestFallbackTest extends TestCase
{
    private const VERSIONED = 'https://b.example/api/ingest/v1/1/envelope/';

    private const LEGACY = 'https://b.example/api/1/envelope/';

    /** @var list<array{url: string, body: string}> */
    private array $requests = [];

    // --- Symfony HttpClient -------------------------------------------------------------------------------------

    public function testVersionedAnswerIsRememberedAndUsedFromThenOn(): void
    {
        $transport = $this->symfony([202, 202]);

        self::assertTrue($transport->send('a')->isAccepted());
        self::assertTrue($transport->send('b')->isAccepted());

        self::assertSame([self::VERSIONED, self::VERSIONED], $this->urls());
        self::assertFalse(IngestPathResolver::shared()->isProbing($this->dsn()));
        self::assertSame(self::VERSIONED, $transport->getEnvelopeUrl());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function missingRoutes(): iterable
    {
        yield '404' => [404];
        yield '405' => [405];
    }

    #[DataProvider('missingRoutes')]
    public function testMissingRouteFallsBackOnceThenGoesStraightToLegacy(int $status): void
    {
        $logger    = new ArrayLogger();
        $transport = $this->symfony([$status, 202, 202], $logger);

        $first = $transport->send('envelope-1');
        self::assertTrue($first->isAccepted());
        self::assertSame(202, $first->statusCode);
        self::assertSame([self::VERSIONED, self::LEGACY], $this->urls());
        self::assertSame(['envelope-1', 'envelope-1'], array_column($this->requests, 'body'));
        self::assertSame([], $logger->records, 'the triggering 404 / 405 is not logged');

        $transport->send('envelope-2');
        self::assertSame([self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
        self::assertSame(self::LEGACY, $transport->getEnvelopeUrl());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function finalStatuses(): iterable
    {
        foreach ([400, 401, 403, 413, 429, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('finalStatuses')]
    public function testNeverFallsBackOnOtherStatuses(int $status): void
    {
        $logger = new ArrayLogger();
        $result = $this->symfony([$status], $logger)->send('x');

        self::assertSame($status, $result->statusCode);
        self::assertSame([self::VERSIONED], $this->urls());
        self::assertCount(1, $logger->records);
        self::assertTrue(IngestPathResolver::shared()->useVersioned($this->dsn()));
        self::assertFalse(IngestPathResolver::shared()->isProbing($this->dsn()));
    }

    public function testKnownVersionedTargetNeverFallsBack(): void
    {
        IngestPathResolver::shared()->versionedAnswered($this->dsn(), 200);

        $result = $this->symfony([404])->send('x');

        self::assertSame(404, $result->statusCode);
        self::assertSame([self::VERSIONED], $this->urls());
    }

    public function testBothPathsMissingKeepsTheVersionedPath(): void
    {
        $logger    = new ArrayLogger();
        $transport = $this->symfony([404, 404, 404], $logger);

        self::assertSame(404, $transport->send('x')->statusCode);
        self::assertCount(1, $logger->records, 'only the legacy answer is logged');
        $transport->send('y');

        self::assertSame([self::VERSIONED, self::LEGACY, self::VERSIONED], $this->urls());
    }

    public function testNetworkFailureIsNeitherRetriedNorRemembered(): void
    {
        $transport = $this->symfony([null]);

        $result = $transport->send('x');

        self::assertNull($result->statusCode);
        self::assertSame([self::VERSIONED], $this->urls());
        self::assertTrue(IngestPathResolver::shared()->isProbing($this->dsn()));
    }

    public function testLegacyNetworkFailureLearnsNothing(): void
    {
        $result = $this->symfony([404, null])->send('x');

        self::assertNull($result->statusCode);
        self::assertSame([self::VERSIONED, self::LEGACY], $this->urls());
        self::assertTrue(IngestPathResolver::shared()->isProbing($this->dsn()));
    }

    public function testLegacyRequestThatCannotStartIsReported(): void
    {
        $calls  = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            if (++$calls === 2) {
                throw new TransportException('refused');
            }

            return new MockResponse('', ['http_code' => 404]);
        });

        $result = (new SymfonyHttpClientTransport($client, $this->dsn()))->send('x');

        self::assertSame('refused', $result->getErrorMessage());
        self::assertSame(2, $calls);
        self::assertTrue(IngestPathResolver::shared()->isProbing($this->dsn()));
    }

    public function testForcedModes(): void
    {
        $versioned = $this->symfony([404], resolver: new IngestPathResolver(IngestPathMode::Versioned));
        self::assertSame(404, $versioned->send('x')->statusCode);
        self::assertSame([self::VERSIONED], $this->urls());

        $this->requests = [];
        $legacy         = $this->symfony([404], resolver: new IngestPathResolver(IngestPathMode::Legacy));
        self::assertSame(404, $legacy->send('x')->statusCode);
        self::assertSame([self::LEGACY], $this->urls());
        self::assertSame(self::LEGACY, $legacy->getEnvelopeUrl());

        self::assertSame(0, IngestPathResolver::shared()->count(), 'forced modes never touch the shared cache');
    }

    public function testAsyncFallbackHappensAtFlushAndBodiesAreKeptOnlyWhileProbing(): void
    {
        $transport = $this->symfony([404, 404, 202, 202, 202], async: true);

        self::assertTrue($transport->send('a')->queued);
        self::assertTrue($transport->send('b')->queued);
        self::assertSame([['a', true], ['b', true]], $this->pending($transport));

        self::assertTrue($transport->flush(5.0));
        // Both requests started while probing: each one falls back once.
        self::assertSame([self::VERSIONED, self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());

        $transport->send('c');
        self::assertSame([[null, false]], $this->pending($transport), 'no body is kept once the target is known');
        $transport->flush(5.0);
        self::assertSame(self::LEGACY, $this->urls()[4]);
    }

    public function testAsyncLateRequestAfterVersionedIsKnownDoesNotFallBack(): void
    {
        $transport = $this->symfony([202, 404], async: true);

        $transport->send('a');
        $transport->send('b');
        $transport->flush(5.0);

        self::assertSame([self::VERSIONED, self::VERSIONED], $this->urls());
    }

    public function testAsyncBoundAndDestructorUseTheFallback(): void
    {
        $logger    = new ArrayLogger();
        $transport = $this->symfony([404, 202, 202], $logger, async: true, maxPending: 1);

        $transport->send('a');
        $transport->send('b'); // started while still probing, then completes "a" (bound) with its fallback
        self::assertSame([self::VERSIONED, self::VERSIONED, self::LEGACY], $this->urls());

        unset($transport);
        self::assertSame([], $logger->records);
    }

    public function testExpiredFlushCancelsWithoutFallback(): void
    {
        $transport = $this->symfony([404], async: true);
        $transport->send('a');

        self::assertFalse($transport->flush(0.0));
        self::assertSame([self::VERSIONED], $this->urls());
        self::assertTrue(IngestPathResolver::shared()->isProbing($this->dsn()));
    }

    // --- PSR-18 -------------------------------------------------------------------------------------------------

    public function testPsr18FallbackOnceThenLegacy(): void
    {
        $logger    = new ArrayLogger();
        $client    = $this->psrClient([404, 202, 202]);
        $transport = $this->psr18($client, $logger);

        self::assertTrue($transport->send('a')->isAccepted());
        self::assertTrue($transport->send('b')->isAccepted());

        self::assertSame([self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
        self::assertSame(['a', 'a', 'b'], array_column($this->requests, 'body'));
        self::assertSame([], $logger->records);
        self::assertSame(self::LEGACY, $transport->getEnvelopeUrl());
    }

    public function testPsr18NoFallbackOnServerError(): void
    {
        $transport = $this->psr18($this->psrClient([503, 202]));

        self::assertSame(503, $transport->send('a')->statusCode);
        $transport->send('b');

        self::assertSame([self::VERSIONED, self::VERSIONED], $this->urls());
        self::assertSame(self::VERSIONED, $transport->getEnvelopeUrl());
    }

    public function testPsr18NetworkFailures(): void
    {
        $versionedDown = $this->psr18($this->psrClient([null]));
        self::assertNull($versionedDown->send('a')->statusCode);
        self::assertSame([self::VERSIONED], $this->urls());

        $this->requests = [];
        $legacyDown     = $this->psr18($this->psrClient([405, null]));
        self::assertNull($legacyDown->send('a')->statusCode);
        self::assertSame([self::VERSIONED, self::LEGACY], $this->urls());
        self::assertTrue(IngestPathResolver::shared()->isProbing($this->dsn()));
    }

    public function testPsr18BothMissingAndForced(): void
    {
        $transport = $this->psr18($this->psrClient([404, 404, 404]));
        self::assertSame(404, $transport->send('a')->statusCode);
        $transport->send('b');
        self::assertSame([self::VERSIONED, self::LEGACY, self::VERSIONED], $this->urls());

        $this->requests = [];
        $legacy         = $this->psr18($this->psrClient([404]), resolver: new IngestPathResolver('legacy'));
        $legacy->send('a');
        self::assertSame([self::LEGACY], $this->urls());
    }

    public function testTransportsShareTheProcessWideAnswer(): void
    {
        $this->symfony([404, 202])->send('a');
        $this->psr18($this->psrClient([202]))->send('b');

        self::assertSame([self::VERSIONED, self::LEGACY, self::LEGACY], $this->urls());
    }

    // --- helpers ------------------------------------------------------------------------------------------------

    /**
     * @param list<int|null> $statuses null = network failure
     */
    private function symfony(array $statuses, ?ArrayLogger $logger = null, bool $async = false, int $maxPending = 100, ?IngestPathResolver $resolver = null): SymfonyHttpClientTransport
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$statuses): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => (string) $options['body']];
            $status           = array_shift($statuses);

            return $status === null ? new MockResponse('', ['error' => 'Could not resolve host']) : new MockResponse('', ['http_code' => $status]);
        });

        return new SymfonyHttpClientTransport($client, $this->dsn(), logger: $logger, async: $async, maxPending: $maxPending, ingestPaths: $resolver);
    }

    /**
     * @param list<int|null> $statuses null = network failure
     */
    private function psrClient(array $statuses): ClientInterface
    {
        $record = function (RequestInterface $request): void {
            $this->requests[] = ['url' => (string) $request->getUri(), 'body' => (string) $request->getBody()];
        };

        return new class($statuses, $record) implements ClientInterface {
            /**
             * @param list<int|null> $statuses
             * @param Closure(RequestInterface): void $record
             */
            public function __construct(private array $statuses, private readonly Closure $record)
            {
            }

            public function sendRequest(RequestInterface $request): PsrResponse
            {
                ($this->record)($request);
                $status = array_shift($this->statuses);
                if ($status === null) {
                    throw new class('connection refused') extends RuntimeException implements NetworkExceptionInterface {
                        public function getRequest(): RequestInterface
                        {
                            return (new Psr17Factory())->createRequest('POST', 'https://b.example');
                        }
                    };
                }

                return new Response($status);
            }
        };
    }

    private function psr18(ClientInterface $client, ?ArrayLogger $logger = null, ?IngestPathResolver $resolver = null): Psr18Transport
    {
        $factory = new Psr17Factory();

        return new Psr18Transport($client, $factory, $factory, $this->dsn(), $logger, ingestPaths: $resolver);
    }

    /**
     * @return list<string>
     */
    private function urls(): array
    {
        return array_column($this->requests, 'url');
    }

    /**
     * Pending async entries as [kept body, versioned path].
     *
     * @return list<array{0: string|null, 1: bool}>
     */
    private function pending(SymfonyHttpClientTransport $transport): array
    {
        $pending = (fn (): array => $this->pending)->call($transport);
        $out     = [];
        foreach ($pending as [, $versioned, $body]) {
            $out[] = [$body, $versioned];
        }

        return $out;
    }

    private function dsn(): Dsn
    {
        return (new DsnParser())->parse('https://pk:test-secret-not-real@b.example/1');
    }
}
