<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit;

use Nowo\Beacon\Client;
use Nowo\Beacon\Envelope\EnvelopeBuilder;
use Nowo\Beacon\NullClient;
use Nowo\Beacon\Options;
use Nowo\Beacon\Request\RequestSnapshot;
use Nowo\Beacon\Tests\Fixtures\ArrayLogger;
use Nowo\Beacon\Tests\Fixtures\RecordingTransport;
use Nowo\Beacon\Tests\Fixtures\StaticRequestProvider;
use Nowo\Beacon\Transport\IngestResponseHandler;
use Nowo\Beacon\Transport\SymfonyHttpClientTransport;
use Nowo\Beacon\Transport\TransportInterface;
use Nowo\Beacon\Transport\TransportResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;

use function implode;

use const INF;

final class ClientTest extends TestCase
{
    private const DSN = 'https://pk:client-secret@b.example/1';

    /**
     * @param array<string, mixed> $options
     */
    private function client(array $options = [], ?RecordingTransport $transport = null): Client
    {
        return new Client(Options::fromArray($options + ['dsn' => self::DSN, 'send' => ['stacktrace' => false]]), $transport ?? new RecordingTransport());
    }

    public function testCaptureExceptionAndMessageReturnEventIds(): void
    {
        $transport = new RecordingTransport();
        $client    = $this->client([], $transport);

        $id = $client->captureException(new RuntimeException('boom'), ['order' => 1], ['fp']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $id);
        self::assertSame($id, $client->getLastEventId());
        [$header, $item, $payload] = $transport->lastEnvelope();
        self::assertSame($id, $header['event_id']);
        self::assertArrayNotHasKey('dsn', $header);
        self::assertSame('event', $item['type']);
        self::assertSame('boom', $payload['message']);
        self::assertSame(['fp'], $payload['fingerprint']);
        self::assertStringNotContainsString('client-secret', implode('', $transport->bodies));

        self::assertNotNull($client->captureMessage('hello', 'info'));
        self::assertSame('info', $transport->lastPayload()['level']);
    }

    public function testScrubberBeforeSendAndIpStripperOrder(): void
    {
        $seen      = null;
        $transport = new RecordingTransport();
        $client    = $this->client([
            'before_send' => static function (array $payload) use (&$seen): array {
                $seen                          = $payload;
                $payload['user']['ip_address'] = '203.0.113.1';
                $payload['tags']['added']      = 'yes';

                return $payload;
            },
        ], $transport);
        $client->setUser(['id' => '7', 'ip_address' => '198.51.100.1']);

        $client->captureMessage('m', 'error', ['password' => 'p', 'note' => 'ok']);

        self::assertIsArray($seen);
        self::assertSame('[Filtered]', $seen['extra']['password']);
        self::assertSame('198.51.100.1', $seen['user']['ip_address']);
        $payload = $transport->lastPayload();
        self::assertSame(['id' => '7'], $payload['user']);
        self::assertSame('yes', $payload['tags']['added']);
    }

    public function testSendClientKeepsIpAndScrubFalseKeepsExtra(): void
    {
        $transport = new RecordingTransport();
        $client    = $this->client(['send' => ['stacktrace' => false, 'client' => true], 'scrub' => false], $transport);
        $client->setUser(['ip_address' => '198.51.100.1']);
        $client->captureMessage('m', 'error', ['password' => 'p']);

        $payload = $transport->lastPayload();
        self::assertSame('198.51.100.1', $payload['user']['ip_address']);
        self::assertSame('p', $payload['extra']['password']);
    }

    public function testBeforeSendCanDropOrFail(): void
    {
        $transport = new RecordingTransport();
        self::assertNull($this->client(['before_send' => static fn (): ?array => null], $transport)->captureMessage('m'));
        self::assertNull($this->client(['before_send' => static fn (): string => 'x'], $transport)->captureMessage('m'));
        self::assertNull($this->client(['before_send' => static function (): never {
            throw new RuntimeException('hook bug');
        }], $transport)->captureMessage('m'));
        self::assertSame([], $transport->bodies);
    }

    public function testMetadataEnvelopesSkipHooks(): void
    {
        $transport = new RecordingTransport();
        $client    = $this->client(['before_send' => static fn (): ?array => null], $transport);

        self::assertNotNull($client->captureDeploy(['release' => '1.0.0']));
        self::assertNotNull($client->captureQueueSnapshot([['name' => 'q', 'count' => 1, 'failure' => false]]));
        self::assertNotNull($client->captureScheduleCatalog([['name' => 'default', 'entries' => []]]));
        self::assertNotNull($client->captureCheckIn(['status' => 'ok']));
        self::assertCount(4, $transport->bodies);
        self::assertSame('check_in', $transport->lastEnvelope()[1]['type']);
    }

    public function testTransactionDrainsSpanBuffer(): void
    {
        $transport = new RecordingTransport();
        $client    = $this->client([], $transport);
        $client->getSpanBuffer()->add('db', 'SELECT 1', 1.0, 1.5);

        $client->captureTransaction('GET /', 1.0, 2.0, [['op' => 'http', 'description' => 'GET /api']], ['code' => 200]);

        $payload = $transport->lastPayload();
        self::assertSame('transaction', $payload['type']);
        self::assertCount(2, $payload['spans']);
        self::assertSame('db', $payload['spans'][0]['op']);
        self::assertSame([], $client->getSpanBuffer()->all());
    }

    public function testScopeHelpersAndReset(): void
    {
        $transport = new RecordingTransport();
        $client    = $this->client([], $transport);
        $client->setTag('area', 'billing');
        $client->setTags(['tenant' => 'acme']);
        $client->setExtra('job', 'import');
        $client->setTraceId('inbound-trace-1');
        $client->addBreadcrumb('step', 'app', 'info', ['n' => 1]);
        self::assertSame(['area' => 'billing', 'tenant' => 'acme'], $client->getTags());

        $client->captureMessage('m');
        $payload = $transport->lastPayload();
        self::assertSame('inbound-trace-1', $payload['tags']['trace_id']);
        self::assertSame('import', $payload['extra']['job']);
        self::assertSame('step', $payload['breadcrumbs']['values'][0]['message']);

        $client->clearTags();
        self::assertSame([], $client->getTags());
        $client->setTag('x', 1);
        $client->addBreadcrumb('again');
        $client->reset();
        self::assertSame([], $client->getTags());
        self::assertSame([], $client->getBreadcrumbs()->all());
        self::assertSame([], $client->getScope()->getExtra());
        self::assertNull($client->getTraceIdProvider()->get());
    }

    public function testDisabledClosedAndReentrantCapturesReturnNull(): void
    {
        $transport = new RecordingTransport();
        self::assertNull($this->client(['enabled' => false], $transport)->captureMessage('m'));
        self::assertFalse($this->client(['dsn' => ''])->isEnabled());

        $client = $this->client([], $transport);
        self::assertTrue($client->close(1.0));
        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureException(new RuntimeException('x')));

        // A capture that triggers another capture (e.g. a warning raised while building) is ignored.
        $inner  = null;
        $nested = $this->client(['before_send' => static function (array $payload) use (&$inner, &$nested): array {
            $inner = $nested instanceof Client ? $nested->captureMessage('nested') : 'unset';

            return $payload;
        }], $transport);
        self::assertNotNull($nested->captureMessage('outer'));
        self::assertNull($inner);
        self::assertCount(1, $transport->bodies);
    }

    public function testFailuresAreLoggedWithoutTheSecret(): void
    {
        $logger   = new ArrayLogger();
        $throwing = new class implements TransportInterface {
            public function send(string $envelopeBody): TransportResult
            {
                throw new RuntimeException('exploded with client-secret inside');
            }

            public function flush(float $timeout): bool
            {
                throw new RuntimeException('flush exploded');
            }
        };
        $client = new Client(Options::fromArray(['dsn' => self::DSN, 'logger' => $logger]), $throwing);

        self::assertNull($client->captureMessage('m'));
        self::assertNull($client->captureMessage('m', 'error', ['bad' => INF]));
        self::assertFalse($client->flush());
        self::assertCount(3, $logger->records);
        self::assertSame('Beacon capture failed.', $logger->records[0]['message']);
        self::assertSame('Beacon flush failed.', $logger->records[2]['message']);
        self::assertStringNotContainsString('client-secret', (string) $logger->records[0]['context']['exception']);
        self::assertTrue($logger->records[0]['context'][IngestResponseHandler::LOG_CONTEXT_FLAG]);

        $noDsnLogger = new ArrayLogger();
        $disabled    = new Client(Options::fromArray(['dsn' => '', 'logger' => $noDsnLogger]), $throwing);
        self::assertFalse($disabled->flush(0.1));
        self::assertSame('flush exploded', $noDsnLogger->records[0]['context']['exception']);
    }

    public function testFlushUsesShutdownTimeoutByDefault(): void
    {
        $transport = new RecordingTransport();
        $client    = $this->client(['shutdown_timeout' => 0.75], $transport);
        self::assertTrue($client->flush());
        self::assertTrue($client->flush(3.0));
        self::assertSame([0.75, 3.0], $transport->flushes);
    }

    public function testDefaultWiringAndAccessors(): void
    {
        $provider = new StaticRequestProvider(new RequestSnapshot('https://h/p', 'GET'));
        $client   = new Client(Options::fromArray(['dsn' => self::DSN, 'http_client' => new MockHttpClient(), 'request_provider' => $provider, 'release' => '2.0.0']));

        self::assertInstanceOf(SymfonyHttpClientTransport::class, $client->getTransport());
        self::assertInstanceOf(EnvelopeBuilder::class, $client->getEnvelopeBuilder());
        self::assertSame('b.example', $client->getDsn()?->getHost());
        self::assertSame('2.0.0', $client->getOptions()->release);
        self::assertTrue(Client::fromDsn('')->getTransport()->flush(0.0));
        self::assertFalse(Client::fromDsn('')->isEnabled());
    }

    public function testNullClient(): void
    {
        $client = new NullClient();
        self::assertFalse($client->isEnabled());
        self::assertNull($client->captureException(new RuntimeException('x')));
        self::assertNull($client->captureMessage('m'));
        self::assertNull($client->captureTransaction('t', 1.0, 2.0));
        self::assertNull($client->captureDeploy(['release' => '1']));
        self::assertNull($client->captureCheckIn([]));
        self::assertNull($client->captureScheduleCatalog([]));
        self::assertNull($client->captureQueueSnapshot([]));
        $client->addBreadcrumb('b');
        $client->setTag('a', 'b');
        $client->setTags(['a' => 'b']);
        $client->clearTags();
        $client->reset();
        self::assertSame([], $client->getTags());
        self::assertTrue($client->flush());
    }
}
