<?php

declare(strict_types=1);

namespace Nowo\Beacon\Exception;

use RuntimeException;

/**
 * No transport was configured and no supported HTTP client is installed.
 */
final class MissingHttpClientException extends RuntimeException implements BeaconExceptionInterface
{
}
