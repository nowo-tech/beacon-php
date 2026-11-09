<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassConst\RemoveUnusedPrivateClassConstantRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Machine-wide cap: at most 2 worker processes; generous timeout for slow (WSL2 / CI) hosts.
    ->withParallel(300, 2, 10)
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // The reserved block is written, never read, on purpose: it is freed at shutdown for an out-of-memory fatal.
        RemoveUnusedPrivatePropertyRector::class      => [__DIR__ . '/src/ErrorHandler/ErrorHandler.php'],
        RemoveUnusedPrivateClassConstantRector::class => [__DIR__ . '/src/ErrorHandler/ErrorHandler.php'],
    ]);
