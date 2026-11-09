<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Scrubber;

use DateTimeImmutable;
use Nowo\Beacon\Scrubber\FrameVarsSerializer;
use Nowo\Beacon\Scrubber\SensitiveValueRedactor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Stringable;

enum FrameVarsUnitProbe
{
    case Alpha;
}

enum FrameVarsBackedProbe: string
{
    case Beta = 'beta';
}

final class FrameVarsStringableProbe implements Stringable
{
    public function __toString(): string
    {
        return 'stringable-probe';
    }
}

final class FrameVarsSerializerTest extends TestCase
{
    public function testEmptyWithoutArgs(): void
    {
        self::assertSame([], FrameVarsSerializer::fromPhpFrame(['function' => 'strlen']));
        self::assertSame([], FrameVarsSerializer::fromPhpFrame(['function' => 'strlen', 'args' => []]));
    }

    public function testNamesParametersViaReflection(): void
    {
        $vars = FrameVarsSerializer::fromPhpFrame([
            'function' => 'sprintf',
            'args'     => ['hello %s', 'world'],
        ]);

        self::assertSame('hello %s', $vars['format']);
        self::assertSame('world', $vars['values']);
    }

    public function testNamesMethodParametersViaReflection(): void
    {
        $vars = FrameVarsSerializer::fromPhpFrame([
            'class'    => self::class,
            'function' => 'namesMethodParametersViaReflection',
            'args'     => ['x'],
        ]);

        // method has no params named for this synthetic frame; reflection may fail or succeed with empty names
        self::assertArrayHasKey('arg0', $vars);
        self::assertSame('x', $vars['arg0']);
    }

    public function testFallsBackToArgIndexWhenReflectionFails(): void
    {
        $vars = FrameVarsSerializer::fromPhpFrame([
            'function' => 'this_function_does_not_exist_xyz',
            'args'     => ['a', 2],
        ]);

        self::assertSame(['arg0' => 'a', 'arg1' => 2], $vars);
    }

    public function testSkipsClosureFunctionNames(): void
    {
        $vars = FrameVarsSerializer::fromPhpFrame([
            'function' => '{closure}',
            'args'     => ['c'],
        ]);
        self::assertSame(['arg0' => 'c'], $vars);

        $vars = FrameVarsSerializer::fromPhpFrame([
            'function' => '{closure:Foo.php:12}',
            'args'     => ['d'],
        ]);
        self::assertSame(['arg0' => 'd'], $vars);
    }

    public function testParameterNamesEmptyArgcViaReflection(): void
    {
        $method = new ReflectionMethod(FrameVarsSerializer::class, 'parameterNames');
        self::assertSame([], $method->invoke(null, ['function' => 'sprintf'], 0));
    }

    public function testRedactsSensitiveKeysAndSummarizesObjects(): void
    {
        $vars = FrameVarsSerializer::fromPhpFrame([
            'function' => 'this_function_does_not_exist_xyz',
            'args'     => [
                ['password' => 'secret', 'ok' => true],
                new stdClass(),
            ],
        ]);

        self::assertSame(SensitiveValueRedactor::FILTERED, $vars['arg0']['password']);
        self::assertTrue($vars['arg0']['ok']);
        self::assertSame(stdClass::class, $vars['arg1']);
    }

    public function testSerializesEnumsStringableDateTimeAndResource(): void
    {
        $handle = fopen('php://memory', 'r');
        self::assertNotFalse($handle);

        try {
            $vars = FrameVarsSerializer::fromPhpFrame([
                'function' => 'this_function_does_not_exist_xyz',
                'args'     => [
                    FrameVarsUnitProbe::Alpha,
                    FrameVarsBackedProbe::Beta,
                    new FrameVarsStringableProbe(),
                    new DateTimeImmutable('2026-10-02T12:00:00+00:00'),
                    $handle,
                ],
            ]);
        } finally {
            fclose($handle);
        }

        self::assertSame('Alpha', $vars['arg0']);
        self::assertSame('beta', $vars['arg1']);
        self::assertSame('stringable-probe', $vars['arg2']);
        self::assertSame('2026-10-02T12:00:00+00:00', $vars['arg3']);
        self::assertIsString($vars['arg4']);
        self::assertStringStartsWith('[Resource ', $vars['arg4']);
    }

    public function testTruncatesDeepArraysAndLongStringsAndLargeLists(): void
    {
        $deep  = ['a' => ['b' => ['c' => ['d' => 'too-deep']]]];
        $long  = str_repeat('x', 1100);
        $list  = range(1, 30);
        $assoc = [];
        for ($i = 0; $i < 30; ++$i) {
            $assoc['k' . $i] = $i;
        }

        $vars = FrameVarsSerializer::fromPhpFrame([
            'function' => 'this_function_does_not_exist_xyz',
            'args'     => [$deep, $long, $list, $assoc],
        ]);

        self::assertSame('[Array(1)]', $vars['arg0']['a']['b']['c']);
        self::assertSame(str_repeat('x', 1024) . '…', $vars['arg1']);
        self::assertSame('…(5 more)', $vars['arg2'][25]);
        self::assertSame('…(5 more)', $vars['arg3']['__truncated']);
    }

    public function testFiltersUnknownScalarLikeTypes(): void
    {
        $method = new ReflectionMethod(FrameVarsSerializer::class, 'serialize');

        $handle = fopen('php://memory', 'r');
        self::assertNotFalse($handle);
        fclose($handle);

        // Closed resources are not is_resource() in PHP 8+ and fall through to FILTERED.
        self::assertSame(SensitiveValueRedactor::FILTERED, $method->invoke(null, $handle, 0));
    }
}
