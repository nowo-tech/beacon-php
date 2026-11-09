<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Parity;

use DateTimeImmutable;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Envelope\EnvelopeBuilder as CoreBuilder;
use Nowo\Beacon\Envelope\EnvelopeDsnMode;
use Nowo\Beacon\Envelope\SendOptions as CoreSendOptions;
use Nowo\Beacon\Tests\Fixtures\FixedClock;
use Nowo\Beacon\Tests\Fixtures\Golden\GoldenCases;
use Nowo\Beacon\Tests\Golden\BundleGoldenTest;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\SendOptions as BundleSendOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function class_exists;
use function count;
use function file_put_contents;
use function getenv;
use function sprintf;

/**
 * Live comparison with a BeaconBundle checkout (`make parity BUNDLE=../bundles/BeaconBundle`): both builders get the
 * same input in the same process. With `BEACON_GOLDEN_UPDATE=1` the bundle output is (re)recorded as golden files.
 * Skipped when the bundle classes are not mapped (BEACON_BUNDLE_SRC).
 */
final class BundleParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(EnvelopeBuilder::class)) {
            self::markTestSkipped('Set BEACON_BUNDLE_SRC to a BeaconBundle src/ directory to run the live parity tests.');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        return BundleGoldenTest::cases();
    }

    #[DataProvider('cases')]
    public function testSameBytesAsBundle(string $name): void
    {
        $bundle = GoldenCases::bundle($name);
        if (getenv('BEACON_GOLDEN_UPDATE') === '1') {
            file_put_contents(sprintf('%s/%s.ndjson', BundleGoldenTest::DIR, $name), $bundle);
        }

        self::assertSame($bundle, GoldenCases::core($name));
    }

    /**
     * Environment-dependent data (PHP version, OS release, source lines of a real file, frame vars of a real trace)
     * compared in-process: default send options plus `stack_vars`.
     */
    public function testRealThrowableWithRuntimeOsSourceContextAndFrameVars(): void
    {
        $throwable = $this->fail_with(['password' => 'hunter2', 'id' => 7], 'plain-arg');
        $clock     = new FixedClock(new DateTimeImmutable(GoldenCases::CLOCK));

        $core = new CoreBuilder(
            environment: 'prod',
            serverName: 'host',
            sendOptions: new CoreSendOptions(stackVars: true),
            stackContextLines: 3,
            clock: $clock,
            dsnMode: EnvelopeDsnMode::Full,
            eventIdGenerator: static fn (): string => GoldenCases::EVENT_ID,
        );
        $bundle = new EnvelopeBuilder(
            environment: 'prod',
            serverName: 'host',
            sendOptions: new BundleSendOptions(stackVars: true),
            stackContextLines: 3,
            clock: $clock,
        );

        $coreBody   = $core->buildEventEnvelope((new DsnParser())->parse(GoldenCases::DSN), '', 'error', $throwable, ['k' => 'v']);
        $bundleBody = $bundle->buildEventEnvelope((new BeaconDsnParser())->parse(GoldenCases::DSN), '', 'error', $throwable, ['k' => 'v']);

        self::assertStringContainsString('"pre_context"', $coreBody);
        self::assertStringContainsString('"vars"', $coreBody);
        self::assertStringContainsString('"runtime"', $coreBody);
        self::assertSame(GoldenCases::normalize($bundleBody), GoldenCases::normalize($coreBody));
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function fail_with(array $credentials, string $label): RuntimeException
    {
        try {
            throw new RuntimeException('boom ' . $label . ' ' . count($credentials));
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }
}
