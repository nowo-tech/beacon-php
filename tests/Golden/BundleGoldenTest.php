<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Golden;

use Nowo\Beacon\Tests\Fixtures\Golden\GoldenCases;
use Nowo\Beacon\Tests\Parity\BundleParityTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function sprintf;

/**
 * beacon-php's EnvelopeBuilder (header mode Full, no scrubbing) must reproduce, byte for byte, the envelopes
 * BeaconBundle 1.19 produced for the same input. The files were recorded from the bundle by
 * `BEACON_GOLDEN_UPDATE=1 make parity BUNDLE=…` ({@see BundleParityTest}).
 */
final class BundleGoldenTest extends TestCase
{
    public const DIR = __DIR__ . '/../Fixtures/Golden/bundle-1.19';

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        foreach (GoldenCases::names() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('cases')]
    public function testMatchesRecordedBundleEnvelope(string $name): void
    {
        $expected = file_get_contents(sprintf('%s/%s.ndjson', self::DIR, $name));
        self::assertIsString($expected, 'Missing golden file for ' . $name);
        self::assertSame($expected, GoldenCases::core($name));
    }
}
