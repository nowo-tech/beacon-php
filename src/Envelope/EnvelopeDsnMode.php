<?php

declare(strict_types=1);

namespace Nowo\Beacon\Envelope;

/**
 * What the envelope header's `dsn` field carries.
 *
 * Beacon authenticates with the `X-Beacon-Auth` header first and only falls back to the envelope `dsn`.
 */
enum EnvelopeDsnMode: string
{
    /** No `dsn` field (default; same as beacon-js `beaconServerAuth()`): the secret only travels in the header. */
    case None = 'none';

    /** `dsn` without the secret (`https://PUBLIC@host/PROJECT`). */
    case Public = 'public';

    /**
     * Full DSN INCLUDING THE SECRET in the request body. BeaconBundle ≤ 1.19 behaviour (kept for its adapter's
     * byte-for-byte parity) and the beacon-js `serverEnvelopeDsnAuth()` opt-in for proxies that strip custom headers.
     * Not recommended.
     */
    case Full = 'full';
}
