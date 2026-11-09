<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit;

use Nowo\Beacon\Scope\Scope;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function function_exists;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * mb_* functions are used by the scope, the frame-vars serializer and the SQL scrubber. symfony/polyfill-mbstring
 * is a hard requirement so that hosts without ext-mbstring (minimal containers, Debian php-cli) still send events;
 * the CI job "no-mbstring" runs the suite with the extension disabled.
 */
final class RequirementsTest extends TestCase
{
    public function testMbstringIsAlwaysAvailable(): void
    {
        /** @var array{require: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('symfony/polyfill-mbstring', $composer['require']);
        self::assertTrue(function_exists('mb_substr'));

        $scope = new Scope();
        $scope->setTag('ünïcödé', str_repeat('é', Scope::MAX_VALUE_LENGTH + 5));
        self::assertSame(Scope::MAX_VALUE_LENGTH, mb_strlen($scope->getTags()['ünïcödé']));
    }
}
