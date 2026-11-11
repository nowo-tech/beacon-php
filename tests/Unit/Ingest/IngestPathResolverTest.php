<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Ingest;

use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Ingest\IngestPathMode;
use Nowo\Beacon\Ingest\IngestPathResolver;
use PHPUnit\Framework\TestCase;
use ValueError;

use function gc_collect_cycles;
use function memory_get_usage;

final class IngestPathResolverTest extends TestCase
{
    public function testUnknownTargetIsProbedOnTheVersionedPath(): void
    {
        $resolver = new IngestPathResolver();
        $dsn      = $this->dsn('a.example', '1');

        self::assertSame(IngestPathMode::Auto, $resolver->mode());
        self::assertTrue($resolver->useVersioned($dsn));
        self::assertTrue($resolver->isProbing($dsn));
        self::assertSame(0, $resolver->count());
    }

    public function testOnlyProofOfTheRouteMeansVersioned(): void
    {
        foreach ([200, 202, 204, 400, 401, 403, 413, 415, 422] as $status) {
            $resolver = new IngestPathResolver();
            $dsn      = $this->dsn('a.example', '1');

            self::assertFalse($resolver->versionedAnswered($dsn, $status), (string) $status);
            self::assertTrue($resolver->useVersioned($dsn));
            self::assertFalse($resolver->isProbing($dsn), (string) $status);
        }
    }

    public function testTransientAnswersTeachNothing(): void
    {
        // LO-M1: a proxy, a load balancer or an outage in front of an older Beacon must not pin the versioned path.
        foreach ([301, 307, 402, 408, 409, 410, 429, 500, 502, 503, 504] as $status) {
            $resolver = new IngestPathResolver();
            $dsn      = $this->dsn('a.example', '1');

            self::assertFalse($resolver->versionedAnswered($dsn, $status), (string) $status);
            self::assertTrue($resolver->isProbing($dsn), (string) $status);
            self::assertSame(0, $resolver->count());
        }
    }

    public function testAuditPocTransientThenOldBeaconIsDeliveredThroughLegacy(): void
    {
        // Audit LO PoC A: 503 on the first probe, then the 404 of a Beacon < 1.147.
        $resolver = new IngestPathResolver();
        $dsn      = $this->dsn('a.example', '1');

        self::assertFalse($resolver->versionedAnswered($dsn, 503));
        self::assertTrue($resolver->useVersioned($dsn));
        self::assertTrue($resolver->versionedAnswered($dsn, 404), 'the next 404 still falls back');
        $resolver->legacyAnswered($dsn, 202);
        self::assertFalse($resolver->useVersioned($dsn));
    }

    public function testKnownVersionedTargetSelfHealsOnA404(): void
    {
        // Audit LO PoC: 404 after the versioned path was learned (Beacon downgrade, stale entry).
        $resolver = new IngestPathResolver();
        $dsn      = $this->dsn('a.example', '1');

        $resolver->versionedAnswered($dsn, 202);
        self::assertFalse($resolver->isProbing($dsn));

        self::assertTrue($resolver->versionedAnswered($dsn, 404), 'resend once to the legacy path');
        self::assertTrue($resolver->isProbing($dsn), 'the entry is forgotten');
        $resolver->legacyAnswered($dsn, 202);
        self::assertFalse($resolver->useVersioned($dsn));
    }

    public function testMissingRouteFallsBackAndLearnsLegacy(): void
    {
        foreach ([404, 405] as $status) {
            $resolver = new IngestPathResolver('auto');
            $dsn      = $this->dsn('a.example', '1');

            self::assertTrue($resolver->mayFallBack());
            self::assertTrue($resolver->versionedAnswered($dsn, $status));
            self::assertTrue($resolver->isProbing($dsn), 'nothing is learned before the legacy answer');
            $resolver->legacyAnswered($dsn, 202);
            self::assertFalse($resolver->useVersioned($dsn));
            self::assertFalse($resolver->isProbing($dsn));
            // Another request started on the versioned path also re-sends.
            self::assertTrue($resolver->versionedAnswered($dsn, 404));
        }
    }

    public function testLegacyAnswers(): void
    {
        $resolver = new IngestPathResolver();
        $dsn      = $this->dsn('a.example', '1');

        $resolver->versionedAnswered($dsn, 404);
        $resolver->legacyAnswered($dsn, 503);
        self::assertTrue($resolver->isProbing($dsn), 'a transient legacy answer teaches nothing');

        $resolver->legacyAnswered($dsn, 401);
        self::assertFalse($resolver->useVersioned($dsn), 'a Beacon 401 proves the legacy route');
    }

    public function testBothPathsMissingKeepsTheVersionedPath(): void
    {
        $resolver = new IngestPathResolver();
        $dsn      = $this->dsn('a.example', '1');

        self::assertTrue($resolver->versionedAnswered($dsn, 404));
        $resolver->legacyAnswered($dsn, 404);

        self::assertTrue($resolver->useVersioned($dsn));
        self::assertFalse($resolver->isProbing($dsn));
    }

    public function testNewestAnswerWins(): void
    {
        $resolver = new IngestPathResolver();
        $dsn      = $this->dsn('a.example', '1');

        $resolver->versionedAnswered($dsn, 200);
        $resolver->legacyAnswered($dsn, 200);

        self::assertFalse($resolver->useVersioned($dsn));
        self::assertSame(1, $resolver->count());
    }

    public function testTargetsAreKeptPerOriginAndProject(): void
    {
        $resolver = new IngestPathResolver();
        $legacy   = $this->dsn('old.example', '1');
        $unknown  = $this->dsn('old.example', '2');
        $other    = $this->dsn('old.example:8443', '1');

        $resolver->versionedAnswered($legacy, 404);
        $resolver->legacyAnswered($legacy, 200);
        // An unknown project on the same old Beacon answers 404 twice: it must not pin the other project.
        $resolver->versionedAnswered($unknown, 404);
        $resolver->legacyAnswered($unknown, 404);

        self::assertFalse($resolver->useVersioned($legacy));
        self::assertTrue($resolver->useVersioned($unknown));
        self::assertTrue($resolver->isProbing($other));
        self::assertTrue($resolver->isProbing($this->dsn('old.example', '1', 'http')));
        // Keys and secrets never matter.
        self::assertFalse($resolver->useVersioned(new Dsn('https', 'other-key', 'other-secret', 'old.example', null, '1')));
    }

    public function testForcedModesNeverProbe(): void
    {
        $dsn = $this->dsn('a.example', '1');

        $versioned = new IngestPathResolver(IngestPathMode::Versioned);
        self::assertFalse($versioned->mayFallBack());
        self::assertTrue($versioned->useVersioned($dsn));
        self::assertFalse($versioned->isProbing($dsn));
        self::assertFalse($versioned->versionedAnswered($dsn, 404));
        $versioned->legacyAnswered($dsn, 200);
        self::assertSame(0, $versioned->count());

        $legacy = new IngestPathResolver('legacy');
        self::assertFalse($legacy->useVersioned($dsn));
        self::assertFalse($legacy->isProbing($dsn));
        self::assertFalse($legacy->versionedAnswered($dsn, 200));
        self::assertSame(0, $legacy->count());
    }

    public function testUnknownModeIsRefused(): void
    {
        $this->expectException(ValueError::class);
        new IngestPathResolver('v2');
    }

    public function testBoundedOldestFirstAndReset(): void
    {
        $resolver = new IngestPathResolver();
        for ($i = 0; $i <= IngestPathResolver::MAX_ENTRIES; ++$i) {
            $resolver->versionedAnswered($this->dsn('b' . $i . '.example', '1'), 404);
            $resolver->legacyAnswered($this->dsn('b' . $i . '.example', '1'), 200);
        }

        self::assertSame(IngestPathResolver::MAX_ENTRIES, $resolver->count());
        self::assertTrue($resolver->isProbing($this->dsn('b0.example', '1')), 'the oldest entry is dropped');
        self::assertFalse($resolver->useVersioned($this->dsn('b1.example', '1')));

        // A newer answer refreshes an entry, so b1 now outlives b2.
        $resolver->versionedAnswered($this->dsn('b1.example', '1'), 200);
        $resolver->versionedAnswered($this->dsn('new.example', '1'), 200);
        self::assertTrue($resolver->useVersioned($this->dsn('b1.example', '1')));
        self::assertFalse($resolver->isProbing($this->dsn('b1.example', '1')));
        self::assertTrue($resolver->isProbing($this->dsn('b2.example', '1')));
        self::assertFalse($resolver->useVersioned($this->dsn('b' . IngestPathResolver::MAX_ENTRIES . '.example', '1')));

        $resolver->reset();
        self::assertSame(0, $resolver->count());
        self::assertTrue($resolver->isProbing($this->dsn('b1.example', '1')));
    }

    public function testLongRunningWorkerStaysBounded(): void
    {
        $resolver = new IngestPathResolver();
        $warm     = 0;
        for ($i = 0; $i < 5000; ++$i) {
            if ($i === 100) {
                gc_collect_cycles();
                $warm = memory_get_usage();
            }
            $dsn = $this->dsn('tenant' . $i . '.example', (string) ($i + 1));
            $resolver->versionedAnswered($dsn, $i % 2 === 0 ? 200 : 404);
            $resolver->legacyAnswered($dsn, 200);
        }
        gc_collect_cycles();

        self::assertSame(IngestPathResolver::MAX_ENTRIES, $resolver->count());
        self::assertLessThan(64 * 1024, memory_get_usage() - $warm);
    }

    public function testSharedResolverIsOneAutoInstance(): void
    {
        $shared = IngestPathResolver::shared();

        self::assertSame($shared, IngestPathResolver::shared());
        self::assertSame(IngestPathMode::Auto, $shared->mode());
    }

    private function dsn(string $host, string $project, string $scheme = 'https'): Dsn
    {
        return (new DsnParser())->parse($scheme . '://pk:test-secret-not-real@' . $host . '/' . $project);
    }
}
