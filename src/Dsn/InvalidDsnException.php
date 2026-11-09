<?php

declare(strict_types=1);

namespace Nowo\Beacon\Dsn;

use InvalidArgumentException;
use Nowo\Beacon\Exception\BeaconExceptionInterface;

/**
 * Thrown when a Beacon DSN string cannot be parsed. The message never contains the DSN or the secret.
 *
 * Not final so that BeaconBundle's `InvalidBeaconDsnException` can extend it.
 */
class InvalidDsnException extends InvalidArgumentException implements BeaconExceptionInterface
{
}
