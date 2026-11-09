<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Monolog;

use Monolog\Logger;
use Nowo\Beacon\Monolog\BeaconHandler;
use Nowo\Beacon\Tests\Fixtures\RecordingClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs unchanged on Monolog 2 (array records) and Monolog 3 (LogRecord); CI runs it on both.
 */
final class MonologVersionNeutralTest extends TestCase
{
    public function testRealLoggerOnTheInstalledMonolog(): void
    {
        $client = new RecordingClient();
        $logger = new Logger('app', [new BeaconHandler($client, 300)]);

        $logger->info('below');
        $logger->warning('careful', ['token' => 't', 'id' => 3]);
        $logger->error('failed', ['exception' => new RuntimeException('boom')]);

        self::assertCount(2, $client->events);
        self::assertSame('careful', $client->events[0]['message']);
        self::assertSame('warning', $client->events[0]['level'] ?? null);
        self::assertSame(['channel' => 'app', 'level' => 'WARNING'], $client->events[0]['extra']['monolog']);
        self::assertSame(['token' => '[Filtered]', 'id' => 3], $client->events[0]['extra']['context']);
        self::assertSame('exception', $client->events[1]['type']);
    }
}
