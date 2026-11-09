<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Dsn;

use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Dsn\InvalidDsnException;
use PHPUnit\Framework\TestCase;

use function sprintf;

final class DsnParserTest extends TestCase
{
    private DsnParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DsnParser();
    }

    public function testParsesHostPortAndProject(): void
    {
        $dsn = $this->parser->parse('https://9cb5e28adc3ed7a40052e2a17e327220:abcdef0123456789@localhost:9444/1');

        self::assertSame('https', $dsn->getScheme());
        self::assertSame('9cb5e28adc3ed7a40052e2a17e327220', $dsn->getPublicKey());
        self::assertSame('abcdef0123456789', $dsn->getSecretKey());
        self::assertSame('localhost', $dsn->getHost());
        self::assertSame(9444, $dsn->getPort());
        self::assertSame('1', $dsn->getProjectId());
        self::assertSame('https://localhost:9444', $dsn->getOrigin());
        self::assertSame('https://localhost:9444/api/1/envelope/', $dsn->getEnvelopeUrl());
        self::assertSame(
            'Beacon beacon_key=9cb5e28adc3ed7a40052e2a17e327220, beacon_secret=abcdef0123456789',
            $dsn->getBeaconAuthHeader(),
        );
    }

    public function testParsesHttpScheme(): void
    {
        $dsn = $this->parser->parse('http://public:secret@beacon.internal:9081/2');

        self::assertSame('http', $dsn->getScheme());
        self::assertSame('public', $dsn->getPublicKey());
        self::assertSame('secret', $dsn->getSecretKey());
        self::assertSame('beacon.internal', $dsn->getHost());
        self::assertSame(9081, $dsn->getPort());
        self::assertSame('2', $dsn->getProjectId());
        self::assertSame('http://beacon.internal:9081', $dsn->getOrigin());
    }

    public function testParsesSubdomainWithoutPort(): void
    {
        $dsn = $this->parser->parse('https://abc:secret@errors.example.com/3');

        self::assertSame('errors.example.com', $dsn->getHost());
        self::assertNull($dsn->getPort());
        self::assertSame('3', $dsn->getProjectId());
        self::assertSame('https://errors.example.com', $dsn->getOrigin());
        self::assertSame('https://errors.example.com/api/3/envelope/', $dsn->getEnvelopeUrl());
    }

    public function testParsesProjectUuid(): void
    {
        $uuid = '019fea2d-507b-7890-8b33-ca488db6f696';
        $dsn  = $this->parser->parse('https://key:secret@localhost:9447/' . $uuid);

        self::assertSame($uuid, $dsn->getProjectId());
        self::assertSame('https://localhost:9447/api/' . $uuid . '/envelope/', $dsn->getEnvelopeUrl());
        self::assertSame('https://key:secret@localhost:9447/' . $uuid, $dsn->toString());
    }

    public function testNormalizesProjectUuidCase(): void
    {
        $dsn = $this->parser->parse('https://key:secret@host/019FEA2D-507B-7890-8B33-CA488DB6F696');

        self::assertSame('019fea2d-507b-7890-8b33-ca488db6f696', $dsn->getProjectId());
    }

    public function testParsesRequiredSecret(): void
    {
        $dsn = $this->parser->parse('http://public:secret@beacon.internal:9081/2');

        self::assertSame('http', $dsn->getScheme());
        self::assertSame('public', $dsn->getPublicKey());
        self::assertSame('secret', $dsn->getSecretKey());
        self::assertSame('http://public:secret@beacon.internal:9081/2', $dsn->toString());
    }

    public function testRejectsEmptySecret(): void
    {
        $this->expectException(InvalidDsnException::class);
        $this->expectExceptionMessage('DSN secret key is required');
        $this->parser->parse('https://public:@host/9');
    }

    public function testRejectsMissingSecret(): void
    {
        $this->expectException(InvalidDsnException::class);
        $this->expectExceptionMessage('DSN secret key is required');
        $this->parser->parse('https://public@host/9');
    }

    public function testRejectsInvalidDsns(): void
    {
        foreach ($this->invalidDsnProvider() as [$rawDsn, $expectedMessage]) {
            try {
                $this->parser->parse($rawDsn);
                self::fail(sprintf('Expected parsing "%s" to fail.', $rawDsn));
            } catch (InvalidDsnException $exception) {
                self::assertStringContainsString($expectedMessage, $exception->getMessage());
            }
        }
    }

    public function testRejectsInvalidPorts(): void
    {
        $this->expectException(InvalidDsnException::class);
        $this->parser->parse('https://key:secret@host:0/1');
    }

    public function testRejectsPortAboveRange(): void
    {
        $this->expectException(InvalidDsnException::class);
        $this->parser->parse('https://key:secret@host:70000/1');
    }

    public function testToStringRoundTripWithSecret(): void
    {
        $raw = 'https://key:secret@beacon.example.com:9444/7';
        $dsn = $this->parser->parse($raw);

        self::assertSame($raw, $dsn->toString());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function invalidDsnProvider(): array
    {
        return [
            ['   ', 'DSN must not be empty.'],
            ['ftp://key:secret@host/1', 'Unsupported DSN scheme'],
            ['https://:secret@host/1', 'public key'],
            ['https://host/1', 'Invalid DSN'],
            ['https://only-public@host/1', 'DSN secret key is required'],
            ['https://key:secret@host/not-a-number', 'positive numeric project id or a UUID'],
            ['https://key:secret@host/019fea2d-507b-7890-8b33-ca488db6f69', 'positive numeric project id or a UUID'],
            ['https://key:secret@host/0', 'positive integer'],
            ['https://key:secret@host/', 'positive numeric project id or a UUID'],
        ];
    }
}
