<?php

declare(strict_types=1);

namespace Nowo\Beacon\Context;

/**
 * Supplies a minimal authenticated-user snapshot for Beacon events (used only when `send.user` is enabled).
 */
interface UserContextProviderInterface
{
    /**
     * Build a minimal user snapshot for the `user` envelope field.
     *
     * @return array{id?: string, username?: string, email?: string}|null
     */
    public function getUserContext(): ?array;
}
