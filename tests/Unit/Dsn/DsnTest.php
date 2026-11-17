<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Dsn;

use LogicException;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Dsn\DsnParser;
use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\Exception\BeaconExceptionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;

use function json_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function strlen;
use function unserialize;
use function var_dump;
use function var_export;

final class DsnTest extends TestCase
{
    private const SECRET = 's3cr3tValue';

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

    public function testSecretIsKeptAndUsedOnTheWire(): void
    {
        $dsn = $this->dsn();

        self::assertSame(self::SECRET, $dsn->getSecretKey());
        self::assertSame('Beacon beacon_key=pk, beacon_secret=' . self::SECRET, $dsn->getBeaconAuthHeader());
        self::assertSame('https://pk:' . self::SECRET . '@beacon.example:9447/42', $dsn->toString());
    }

    public function testVarExportNeverShowsTheSecret(): void
    {
        self::assertStringNotContainsString(self::SECRET, var_export($this->dsn(), true));
    }

    public function testPrintRNeverShowsTheSecret(): void
    {
        self::assertStringNotContainsString(self::SECRET, print_r($this->dsn(), true));
    }

    public function testVarDumpNeverShowsTheSecret(): void
    {
        ob_start();
        var_dump($this->dsn());
        $output = (string) ob_get_clean();

        self::assertStringContainsString('[Filtered]', $output);
        self::assertStringNotContainsString(self::SECRET, $output);
    }

    public function testArrayCastNeverShowsTheSecret(): void
    {
        self::assertStringNotContainsString(self::SECRET, var_export((array) $this->dsn(), true));
    }

    public function testJsonEncodeNeverShowsTheSecret(): void
    {
        $json = json_encode(['dsn' => $this->dsn()]);

        self::assertIsString($json);
        self::assertStringNotContainsString(self::SECRET, $json);
    }

    public function testSerializeIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be serialized');

        serialize($this->dsn());
    }

    public function testSerializingAnObjectThatHoldsADsnIsRefused(): void
    {
        $this->expectException(LogicException::class);

        serialize(['nested' => $this->dsn()]);
    }

    public function testUnserializeIsRefused(): void
    {
        $payload = 'O:' . strlen(Dsn::class) . ':"' . Dsn::class . '":1:{s:9:"secretKey";s:2:"sk";}';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be unserialized');

        unserialize($payload, ['allowed_classes' => [Dsn::class]]);
    }

    public function testConstructorSecretParameterIsSensitive(): void
    {
        $constructor = (new ReflectionClass(Dsn::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === 'secretKey') {
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));

                return;
            }
        }

        self::fail('Dsn::__construct() has no $secretKey parameter.');
    }

    public function testParserDsnParameterIsSensitive(): void
    {
        $parameter = (new ReflectionMethod(DsnParser::class, 'parse'))->getParameters()[0];

        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    public function testExceptionTracesNeverShowTheSecret(): void
    {
        $exception = $this->traceOf($this->dsn(), 'https://pk:' . self::SECRET . '@beacon.example/42');

        self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
        self::assertStringNotContainsString(self::SECRET, print_r($exception->getTrace(), true));
        self::assertStringNotContainsString(self::SECRET, var_export($exception->getTrace(), true));
    }

    public function testConstructorTraceNeverShowsTheSecret(): void
    {
        try {
            (new DsnParser())->parse('https://pk:' . self::SECRET . '@beacon.example/0');
        } catch (InvalidDsnException $exception) {
            self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
            self::assertStringNotContainsString(self::SECRET, print_r($exception->getTrace(), true));

            return;
        }

        self::fail('Expected InvalidDsnException.');
    }

    private function dsn(): Dsn
    {
        return new Dsn('https', 'pk', self::SECRET, 'beacon.example', 9447, 42);
    }

    private function traceOf(Dsn $dsn, #[SensitiveParameter] string $raw): RuntimeException
    {
        try {
            $this->throwWith($dsn, $raw);
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    private function throwWith(Dsn $dsn, #[SensitiveParameter] string $raw): never
    {
        throw new RuntimeException('boom ' . $dsn->getHost() . ' ' . strlen($raw));
    }
}
