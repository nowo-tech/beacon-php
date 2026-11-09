<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit;

use Nowo\Beacon\Beacon;
use Nowo\Beacon\Client;
use Nowo\Beacon\NullClient;
use Nowo\Beacon\Options;
use Nowo\Beacon\Tests\Fixtures\NonLifecycleClient;
use Nowo\Beacon\Tests\Fixtures\RecordingClient;
use Nowo\Beacon\Tests\Fixtures\RecordingTransport;
use Nowo\Beacon\Version;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BeaconTest extends TestCase
{
    protected function tearDown(): void
    {
        Beacon::close();
    }

    public function testFacadeBeforeInitIsANoOp(): void
    {
        self::assertInstanceOf(NullClient::class, Beacon::getClient());
        self::assertFalse(Beacon::isEnabled());
        self::assertNull(Beacon::captureMessage('m'));
        self::assertTrue(Beacon::flush());
        self::assertTrue(Beacon::close());
        self::assertNull(Beacon::getErrorHandler());
    }

    public function testInitInstallsHandlersAndForwardsEverything(): void
    {
        $transport = new RecordingTransport();
        $client    = Beacon::init(Options::fromArray(['dsn' => 'https://pk:sk@b.example/1', 'transport' => $transport, 'send' => ['stacktrace' => false], 'shutdown_timeout' => 0.3]));

        self::assertSame($client, Beacon::getClient());
        self::assertTrue(Beacon::isEnabled());
        self::assertNotNull(Beacon::getErrorHandler());

        Beacon::setTag('area', 'billing');
        Beacon::setTags(['tenant' => 'acme']);
        Beacon::setUser(['id' => '1']);
        Beacon::setExtra('job', 'import');
        Beacon::addBreadcrumb('step');
        self::assertNotNull(Beacon::captureMessage('hello', 'info', ['a' => 1], ['fp']));
        $payload = $transport->lastPayload();
        self::assertSame('billing', $payload['tags']['area']);
        self::assertSame(['id' => '1'], $payload['user']);
        self::assertSame('import', $payload['extra']['job']);

        self::assertNotNull(Beacon::captureException(new RuntimeException('boom')));
        self::assertNotNull(Beacon::captureTransaction('t', 1.0, 2.0));
        self::assertNotNull(Beacon::captureDeploy(['release' => '1.0.0']));
        self::assertNotNull(Beacon::captureCheckIn(['status' => 'ok']));
        self::assertNotNull(Beacon::captureScheduleCatalog([]));
        self::assertNotNull(Beacon::captureQueueSnapshot([]));
        self::assertCount(7, $transport->bodies);

        self::assertTrue(Beacon::flush(0.1));
        Beacon::reset();
        self::assertSame([], $client->getTags());

        self::assertTrue(Beacon::close());
        self::assertFalse($client->isEnabled());
        self::assertSame([0.1, 0.3], $transport->flushes);
        self::assertNull(Beacon::getErrorHandler());
    }

    public function testInitFromStringOrArrayReplacesThePreviousClient(): void
    {
        $first  = Beacon::init('');
        $second = Beacon::init(['dsn' => '', 'error_handler' => false]);

        self::assertNotSame($first, $second);
        self::assertFalse($second->isEnabled());
        self::assertInstanceOf(Client::class, Beacon::getClient());
    }

    public function testCustomClientsWithoutOptionalCapabilities(): void
    {
        $client = new RecordingClient();
        Beacon::setClient($client);

        self::assertNull(Beacon::captureDeploy(['release' => '1']));
        self::assertNull(Beacon::captureCheckIn([]));
        self::assertNull(Beacon::captureScheduleCatalog([]));
        self::assertNull(Beacon::captureQueueSnapshot([]));
        Beacon::setUser(['id' => '1']);
        Beacon::setExtra('k', 'v');
        Beacon::reset();
        self::assertSame(1, $client->resets);
        self::assertTrue(Beacon::flush());
        self::assertTrue(Beacon::close(0.2));
        self::assertSame([null, 0.2], $client->flushes);

        Beacon::setClient(new class extends NonLifecycleClient {
        });
        self::assertTrue(Beacon::flush());
        Beacon::reset();
        self::assertTrue(Beacon::close());
    }

    public function testVersion(): void
    {
        self::assertSame('beacon-php/' . Version::VERSION, Version::userAgent());
    }
}
