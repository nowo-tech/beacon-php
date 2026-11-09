<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Transport;

use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Tests\Fixtures\ArrayLogger;
use Nowo\Beacon\Transport\IngestResponseHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IngestResponseHandlerTest extends TestCase
{
    public function testStatusesAreClassifiedAndLoggedLikeTheBundle(): void
    {
        $logger  = new ArrayLogger();
        $handler = new IngestResponseHandler((new DsnParser())->parse('https://pk:sk-value@b.example/1'), $logger);
        $headers = static fn (string $name): ?string => ['retry-after' => '30', 'x-beacon-rate-limit' => 'project'][$name] ?? null;

        self::assertTrue($handler->fromStatus(200, $headers)->isAccepted());

        $limited = $handler->fromStatus(429, $headers);
        self::assertFalse($limited->isAccepted());
        self::assertSame(30.0, $limited->retryAfter);
        self::assertSame('project', $limited->rateLimit);

        $unavailable = $handler->fromStatus(503, $headers);
        self::assertSame(30.0, $unavailable->retryAfter);

        self::assertSame(401, $handler->fromStatus(401, $headers)->statusCode);
        self::assertSame(400, $handler->fromStatus(400, static fn (): ?string => null)->statusCode);
        self::assertNull($handler->fromStatus(429, static fn (): ?string => null)->retryAfter);

        $messages = array_column($logger->records, 'message');
        self::assertSame([
            'Beacon ingest rate limited (HTTP 429). Respect Retry-After before retrying.',
            'Beacon ingest rejected envelope.',
            'Beacon ingest authentication rejected. Confirm BEACON_DSN includes public:secret and matches the project.',
            'Beacon ingest rejected envelope.',
            'Beacon ingest rate limited (HTTP 429). Respect Retry-After before retrying.',
        ], $messages);
        self::assertSame('30', $logger->records[0]['context']['retry_after']);
        self::assertArrayNotHasKey('retry_after', $logger->records[4]['context']);
        foreach ($logger->records as $record) {
            self::assertTrue($record['context'][IngestResponseHandler::LOG_CONTEXT_FLAG]);
            self::assertSame('https://b.example/api/1/envelope/', $record['context']['url']);
        }
    }

    public function testFailuresNeverLeakTheSecret(): void
    {
        $logger  = new ArrayLogger();
        $handler = new IngestResponseHandler((new DsnParser())->parse('https://pk:sk-value@b.example/1'), $logger);

        $result = $handler->fromFailure(new RuntimeException('cURL error with header X-Beacon-Auth: Beacon beacon_key=pk, beacon_secret=sk-value and sk-value'));
        self::assertStringNotContainsString('sk-value', (string) $result->getErrorMessage());
        self::assertStringNotContainsString('sk-value', (string) json_encode($logger->records));
        self::assertSame('Beacon ingest transport failed.', $logger->records[0]['message']);
    }

    public function testRetryAfterParsing(): void
    {
        self::assertNull(IngestResponseHandler::parseRetryAfter(null));
        self::assertNull(IngestResponseHandler::parseRetryAfter(' '));
        self::assertNull(IngestResponseHandler::parseRetryAfter('soon'));
        self::assertSame(12.0, IngestResponseHandler::parseRetryAfter('12'));
        self::assertSame(60.0, IngestResponseHandler::parseRetryAfter('Wed, 21 Oct 2015 07:29:00 GMT', 1445412480.0));
        self::assertSame(0.0, IngestResponseHandler::parseRetryAfter('Wed, 21 Oct 2015 07:29:00 GMT', 1445412600.0));
        self::assertGreaterThan(0.0, IngestResponseHandler::parseRetryAfter('+1 hour'));
    }
}
