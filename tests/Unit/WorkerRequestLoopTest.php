<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit;

use Nowo\Beacon\Client;
use Nowo\Beacon\Ingest\IngestPathResolver;
use Nowo\Beacon\Options;
use Nowo\Beacon\Tests\Fixtures\RecordingTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function count;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;

/**
 * REQ-RUNTIME-002 — one long-lived client served many "requests" the way a FrankenPHP worker does, with the host
 * calling `reset()` between requests and nothing else (no kernel or container reset): the state of one request never
 * reaches the next, and memory stays bounded.
 */
final class WorkerRequestLoopTest extends TestCase
{
    private const REQUESTS = 2000;

    public function testRequestStateNeverLeaksIntoTheNextRequest(): void
    {
        $transport = new RecordingTransport();
        $client    = new Client(Options::fromArray(['dsn' => 'https://pk:client-secret@b.example/1', 'send' => ['stacktrace' => false]]), $transport);

        for ($i = 0; $i < self::REQUESTS; ++$i) {
            $tenant = 'tenant-' . ($i % 2 === 0 ? 'a' : 'b');

            // A new request starts clean: nothing of the previous one is left.
            self::assertSame([], $client->getTags(), sprintf('tags leaked into request %d', $i));
            self::assertCount(0, $client->getBreadcrumbs()->all(), sprintf('breadcrumbs leaked into request %d', $i));
            self::assertNull($client->getScope()->getUser(), sprintf('user leaked into request %d', $i));

            $client->setTag('tenant', $tenant);
            $client->setUser(['id' => 'user-' . $i]);
            $client->addBreadcrumb('step ' . $i, 'request');
            self::assertNotNull($client->captureMessage('request ' . $i, 'info'));

            $payload = $transport->lastPayload();
            self::assertSame($tenant, $payload['tags']['tenant']);
            self::assertSame(['id' => 'user-' . $i], $payload['user']);
            self::assertSame('step ' . $i, $payload['breadcrumbs']['values'][0]['message']);
            self::assertCount(1, $payload['breadcrumbs']['values']);

            $transport->bodies = [];
            $client->reset();
        }
    }

    public function testMemoryAndProcessWideCacheStayBoundedOverManyRequests(): void
    {
        $transport = new RecordingTransport();
        $client    = new Client(Options::fromArray(['dsn' => 'https://pk:client-secret@b.example/1', 'send' => ['stacktrace' => false]]), $transport);

        $run = static function () use ($client, $transport): void {
            for ($i = 0; $i < 500; ++$i) {
                $client->setTag('tenant', 'tenant-' . ($i % 7));
                $client->setUser(['id' => 'user-' . $i]);
                $client->addBreadcrumb('step ' . $i, 'request', 'info', ['i' => $i]);
                $client->captureMessage('request ' . $i);
                $transport->bodies = [];
                $client->reset();
            }
        };

        $run(); // warm-up: lazily built objects exist from here on
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($round = 0; $round < 3; ++$round) {
            $run();
        }
        gc_collect_cycles();
        $drift = memory_get_usage() - $before;

        $resolver = IngestPathResolver::shared();
        $known    = (new ReflectionClass($resolver))->getProperty('known')->getValue($resolver);
        self::assertIsArray($known);

        self::assertLessThan(1024 * 1024, $drift, sprintf('memory drift of %d bytes over %d requests', $drift, 3 * 500));
        self::assertLessThanOrEqual(IngestPathResolver::MAX_ENTRIES, count($known));
    }
}
