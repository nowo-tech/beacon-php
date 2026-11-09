<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Dsn;

use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\Exception\BeaconExceptionInterface;
use PHPUnit\Framework\TestCase;

use function print_r;
use function var_export;

final class DsnTest extends TestCase
{
    public function testPublicStringAndDebugInfoNeverContainTheSecret(): void
    {
        $dsn = (new DsnParser())->parse('https://pk:s3cr3tValue@beacon.example:9447/019fea2d-507b-7890-8b33-ca488db6f696');

        self::assertSame('https://pk@beacon.example:9447/019fea2d-507b-7890-8b33-ca488db6f696', $dsn->toPublicString());
        self::assertSame('https://beacon.example:9447/api/019fea2d-507b-7890-8b33-ca488db6f696/envelope/', $dsn->getEnvelopeUrl());
        self::assertStringNotContainsString('s3cr3tValue', $dsn->getEnvelopeUrl());
        self::assertStringNotContainsString('s3cr3tValue', print_r($dsn, true));
        self::assertSame('[Filtered]', $dsn->__debugInfo()['secretKey']);
        self::assertSame('Beacon beacon_key=pk, beacon_secret=s3cr3tValue', $dsn->getBeaconAuthHeader());
    }

    public function testIntegerProjectIdAndNoPort(): void
    {
        $dsn = new Dsn('http', 'pk', 'sk', 'localhost', null, 7);

        self::assertSame('7', $dsn->getProjectId());
        self::assertSame('http://pk@localhost/7', $dsn->toPublicString());
        self::assertSame('http://pk:sk@localhost/7', $dsn->toString());
        self::assertSame('http://localhost', $dsn->getOrigin());
    }

    public function testControlCharactersInKeysAreRefusedWithoutEchoingTheDsn(): void
    {
        try {
            (new DsnParser())->parse('https://pk:se%0Acret@beacon.example/1');
            self::fail('Expected InvalidDsnException');
        } catch (InvalidDsnException $exception) {
            self::assertInstanceOf(BeaconExceptionInterface::class, $exception);
            self::assertSame('DSN keys must not contain control characters.', $exception->getMessage());
            self::assertStringNotContainsString('cret', var_export($exception->getMessage(), true));
        }
    }

    public function testRejectsZeroProjectAndEmptyPath(): void
    {
        $parser = new DsnParser();
        foreach (['https://pk:sk@h/0' => 'positive integer', 'https://pk:sk@h/' => 'must be a positive numeric', 'https://pk:sk@h/abc' => 'must be a positive numeric'] as $dsn => $message) {
            try {
                $parser->parse($dsn);
                self::fail('Expected InvalidDsnException for ' . $dsn);
            } catch (InvalidDsnException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }
}
