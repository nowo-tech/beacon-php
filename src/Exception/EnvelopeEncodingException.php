<?php

declare(strict_types=1);

namespace Nowo\Beacon\Exception;

use RuntimeException;

/**
 * A payload could not be encoded as JSON (the client catches it and drops the event; it never reaches the app).
 */
final class EnvelopeEncodingException extends RuntimeException implements BeaconExceptionInterface
{
}
