<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Parity;

use Nowo\BeaconBundle\Context\UserContextProviderInterface;

/**
 * BeaconBundle user provider for the golden / parity cases (loaded only when the bundle classes are mapped).
 */
final readonly class BundleUserProvider implements UserContextProviderInterface
{
    /**
     * @param array<string, string> $user
     */
    public function __construct(private array $user)
    {
    }

    public function getUserContext(): array
    {
        return $this->user;
    }
}
