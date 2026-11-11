<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Ingest;

use InvalidArgumentException;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Ingest\IngestPaths;
use PHPUnit\Framework\TestCase;

final class IngestPathsTest extends TestCase
{
    private const UUID = '019fea2d-507b-7890-8b33-ca488db6f696';

    public function testVersionedAndLegacyUrls(): void
    {
        $dsn = (new DsnParser())->parse('https://pk:test-secret-not-real@beacon.example:9447/' . self::UUID);
        $v1  = 'https://beacon.example:9447/api/ingest/v1/' . self::UUID;
        $old = 'https://beacon.example:9447/api/' . self::UUID;

        self::assertSame($v1 . '/envelope/', IngestPaths::envelopeUrl($dsn, true));
        self::assertSame($old . '/envelope/', IngestPaths::envelopeUrl($dsn, false));
        self::assertSame($dsn->getEnvelopeUrl(), IngestPaths::envelopeUrl($dsn, false), 'Dsn::getEnvelopeUrl() stays the legacy alias');

        foreach (IngestPaths::OTLP_SIGNALS as $signal) {
            self::assertSame($v1 . '/otlp/v1/' . $signal, IngestPaths::otlpUrl($dsn, $signal, true));
            self::assertSame($old . '/otlp/v1/' . $signal, IngestPaths::otlpUrl($dsn, $signal, false));
        }
        self::assertSame($v1 . '/otlp', IngestPaths::otlpEndpoint($dsn, true));
        self::assertSame($old . '/otlp', IngestPaths::otlpEndpoint($dsn, false));

        self::assertSame($v1 . '/messenger/commands/claim', IngestPaths::messengerClaimUrl($dsn, true));
        self::assertSame($old . '/messenger/commands/claim', IngestPaths::messengerClaimUrl($dsn, false));
        $id = '0192f3c4-5b6a-7c8d-9e0f-a1b2c3d4e5f6';
        self::assertSame($v1 . '/messenger/commands/' . $id . '/result', IngestPaths::messengerResultUrl($dsn, $id, true));
        self::assertSame($old . '/messenger/commands/' . $id . '/result', IngestPaths::messengerResultUrl($dsn, $id, false));
        self::assertSame($v1 . '/messenger/commands/a%2Fb/result', IngestPaths::messengerResultUrl($dsn, 'a/b', true));
        self::assertSame($v1 . '/x', IngestPaths::projectPathUrl($dsn, '/x', true));

        foreach ([IngestPaths::envelopeUrl($dsn, true), IngestPaths::messengerClaimUrl($dsn, true)] as $url) {
            self::assertStringNotContainsString('test-secret-not-real', $url);
            self::assertStringNotContainsString('pk', $url);
        }
    }

    public function testNumericProjectId(): void
    {
        $dsn = (new DsnParser())->parse('http://pk:sk@localhost/42');

        self::assertSame('http://localhost/api/ingest/v1/42/envelope/', IngestPaths::envelopeUrl($dsn, true));
        self::assertSame('http://localhost/api/42/envelope/', IngestPaths::envelopeUrl($dsn, false));
    }

    public function testUnknownOtlpSignal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IngestPaths::otlpUrl((new DsnParser())->parse('http://pk:sk@localhost/42'), 'profiles', true);
    }

    public function testRouteProofStatuses(): void
    {
        foreach ([200, 201, 202, 204, 299, 400, 401, 403, 413, 415, 422] as $status) {
            self::assertTrue(IngestPaths::provesRoute($status), (string) $status);
        }
        foreach ([199, 300, 307, 402, 404, 405, 408, 409, 410, 429, 500, 502, 503, 504] as $status) {
            self::assertFalse(IngestPaths::provesRoute($status), (string) $status);
        }
    }

    public function testRouteMissingStatuses(): void
    {
        self::assertTrue(IngestPaths::isRouteMissing(404));
        self::assertTrue(IngestPaths::isRouteMissing(405));
        foreach ([200, 202, 400, 401, 403, 413, 429, 500, 503] as $status) {
            self::assertFalse(IngestPaths::isRouteMissing($status));
        }
    }
}
