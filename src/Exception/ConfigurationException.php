<?php

declare(strict_types=1);

namespace Nowo\Beacon\Exception;

use InvalidArgumentException;

/**
 * Invalid client options. The message never contains the DSN or a secret.
 */
final class ConfigurationException extends InvalidArgumentException implements BeaconExceptionInterface
{
}
