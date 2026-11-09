<?php

declare(strict_types=1);

namespace Nowo\Beacon;

/**
 * SDK identity sent in the `User-Agent` header.
 */
final class Version
{
    public const SDK_NAME = 'beacon-php';

    public const VERSION = '0.1.0';

    /**
     * `beacon-php/0.1.0`.
     */
    public static function userAgent(): string
    {
        return self::SDK_NAME . '/' . self::VERSION;
    }
}
