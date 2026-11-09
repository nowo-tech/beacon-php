<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\Scope;

use Nowo\Beacon\Scope\Scope;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public function testSetTagsMergesAndOverwrites(): void
    {
        $scope = new Scope();
        $scope->setTag('env', 'prod');
        $scope->setTags(['region' => 'eu', 'env' => 'staging']);

        self::assertSame([
            'env'    => 'staging',
            'region' => 'eu',
        ], $scope->getTags());
    }

    public function testIgnoresInvalidValuesAndCapsCount(): void
    {
        $scope = new Scope();
        $scope->setTag('ok', 'yes');
        $scope->setTag('bad', ['nested' => true]);
        $scope->setTag('', 'x');

        for ($i = 0; $i < Scope::MAX_TAGS + 5; ++$i) {
            $scope->setTag('k' . $i, (string) $i);
        }

        self::assertCount(Scope::MAX_TAGS, $scope->getTags());
        self::assertSame('yes', $scope->getTags()['ok']);
        self::assertArrayNotHasKey('bad', $scope->getTags());
    }

    public function testClearAndRemove(): void
    {
        $scope = new Scope();
        $scope->setTags(['a' => '1', 'b' => '2']);
        $scope->removeTag('a');
        self::assertSame(['b' => '2'], $scope->getTags());
        $scope->removeTag('');
        $scope->clearTags();
        self::assertSame([], $scope->getTags());
        $scope->reset();
        self::assertSame([], $scope->getTags());
    }

    public function testNormalizesBooleanValues(): void
    {
        $scope = new Scope();
        $scope->setTag('on', true);
        $scope->setTag('off', false);

        self::assertSame(['on' => 'true', 'off' => 'false'], $scope->getTags());
    }

    public function testUserAndExtraAreScopedAndReset(): void
    {
        $scope = new Scope();
        $scope->setUser(['id' => '1']);
        $scope->setExtra('job', 'import');
        $scope->setExtra(' ', 'ignored');
        self::assertSame(['id' => '1'], $scope->getUser());
        self::assertSame(['job' => 'import'], $scope->getExtra());

        $scope->setUser([]);
        self::assertNull($scope->getUser());

        for ($i = 0; $i < Scope::MAX_EXTRA + 3; ++$i) {
            $scope->setExtra('k' . $i, $i);
        }
        self::assertCount(Scope::MAX_EXTRA, $scope->getExtra());
        $scope->setExtra('job', 'overwritten');
        self::assertSame('overwritten', $scope->getExtra()['job']);

        $scope->setUser(['id' => '2']);
        $scope->setTag('a', 1);
        $scope->reset();
        self::assertNull($scope->getUser());
        self::assertSame([], $scope->getExtra());
        self::assertSame([], $scope->getTags());
    }
}
