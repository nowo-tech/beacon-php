<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Monolog;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Nowo\Beacon\Monolog\BeaconHandler;
use Nowo\Beacon\Tests\Fixtures\RecordingClient;
use Nowo\Beacon\Transport\IngestResponseHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BeaconHandlerTest extends TestCase
{
    public function testForwardsMessagesWithBundleShape(): void
    {
        $client = new RecordingClient();
        $logger = new Logger('app', [new BeaconHandler($client, Level::Warning)]);

        $logger->info('ignored below threshold');
        $logger->warning('disk {pct}%', ['pct' => 91, 'api_key' => 'k', 'dsn' => 'https://a:b@c/1']);
        $logger->critical('db down');

        self::assertCount(2, $client->events);
        self::assertSame('disk {pct}%', $client->events[0]['message']);
        self::assertSame('warning', $client->events[0]['level'] ?? null);
        self::assertSame(['channel' => 'app', 'level' => 'WARNING'], $client->events[0]['extra']['monolog']);
        self::assertSame(['pct' => 91, 'api_key' => '[Filtered]', 'dsn' => '[Filtered]'], $client->events[0]['extra']['context']);
        self::assertSame('error', $client->events[1]['level'] ?? null);
        self::assertArrayNotHasKey('context', $client->events[1]['extra']);
    }

    public function testExceptionContextIsCapturedAsException(): void
    {
        $client  = new RecordingClient();
        $handler = new BeaconHandler($client, 'debug');
        $logger  = new Logger('worker', [$handler]);

        $logger->error('job failed', ['exception' => new RuntimeException('boom'), 'job' => 7]);
        $logger->notice('note');

        self::assertSame('exception', $client->events[0]['type']);
        self::assertSame('boom', $client->events[0]['message']);
        self::assertSame(['job' => 7], $client->events[0]['extra']['context']);
        self::assertSame('info', $client->events[1]['level'] ?? null);
    }

    public function testSkipsOwnTransportRecordsDisabledClientAndBubbles(): void
    {
        $client  = new RecordingClient();
        $handler = new BeaconHandler($client, 100, false);
        $record  = new LogRecord(new DateTimeImmutable(), 'beacon', Level::Error, 'Beacon ingest transport failed.', [IngestResponseHandler::LOG_CONTEXT_FLAG => true]);

        self::assertTrue($handler->handle($record));
        self::assertSame([], $client->events);

        $client->enabled = false;
        $handler->handle(new LogRecord(new DateTimeImmutable(), 'app', Level::Error, 'm'));
        self::assertSame([], $client->events);

        $client->enabled   = true;
        $client->onCapture = static function (): never {
            throw new RuntimeException('reporting failed');
        };
        self::assertTrue($handler->handle(new LogRecord(new DateTimeImmutable(), 'app', Level::Error, 'm')));
        $handler->close();
    }

    public function testMonolog2ArrayRecordsAndBatch(): void
    {
        $client  = new RecordingClient();
        $handler = new BeaconHandler($client, 300);

        $handler->handleBatch([
            ['message' => 'legacy', 'level' => 400, 'level_name' => 'ERROR', 'channel' => 'old', 'context' => ['user_id' => 3]],
            ['message' => 'too low', 'level' => 200, 'channel' => 'old'],
            ['message' => 'broken level', 'level' => 'x'],
            ['message' => ['not-a-string'], 'level' => 300, 'context' => 'not-an-array'],
        ]);

        self::assertCount(2, $client->events);
        self::assertSame('legacy', $client->events[0]['message']);
        self::assertSame(['channel' => 'old', 'level' => 'ERROR'], $client->events[0]['extra']['monolog']);
        self::assertSame(['user_id' => 3], $client->events[0]['extra']['context']);
        self::assertSame('', $client->events[1]['message']);
        self::assertSame('warning', $client->events[1]['level'] ?? null);
        self::assertFalse($handler->isHandling(['level' => 100]));
    }
}
