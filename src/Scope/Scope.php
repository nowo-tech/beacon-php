<?php

declare(strict_types=1);

namespace Nowo\Beacon\Scope;

use Nowo\Beacon\Client;

use function count;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_substr;
use function trim;

/**
 * Request / job scoped context merged into outbound events: tags (same rules as BeaconBundle's `Scope`), plus the
 * user and extra values an application sets explicitly.
 *
 * One instance per {@see Client}; call {@see reset()} between requests or jobs of a long-running worker.
 * Not final so that framework adapters can extend it (BeaconBundle adds Symfony's `ResetInterface`).
 */
class Scope
{
    public const MAX_TAGS = 32;

    public const MAX_KEY_LENGTH = 32;

    public const MAX_VALUE_LENGTH = 200;

    public const MAX_EXTRA = 64;

    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /** @var array<string, mixed> */
    private array $extra = [];

    /**
     * Set a single tag (merged into the scope). Invalid keys/values are ignored.
     */
    public function setTag(string $key, mixed $value): void
    {
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === null) {
            return;
        }

        $normalizedValue = $this->normalizeValue($value);
        if ($normalizedValue === null) {
            return;
        }

        if (!isset($this->tags[$normalizedKey]) && count($this->tags) >= self::MAX_TAGS) {
            return;
        }

        $this->tags[$normalizedKey] = $normalizedValue;
    }

    /**
     * Merge tags into the scope (existing keys are overwritten).
     *
     * @param array<array-key, mixed> $tags
     */
    public function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            $this->setTag((string) $key, $value);
        }
    }

    /**
     * Remove one tag by key.
     */
    public function removeTag(string $key): void
    {
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === null) {
            return;
        }

        unset($this->tags[$normalizedKey]);
    }

    /**
     * Clear all tags.
     */
    public function clearTags(): void
    {
        $this->tags = [];
    }

    /**
     * Current tags (key → string value).
     *
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * User the application identified (`id`, `username`, `email`, …), or null to clear it.
     *
     * Sent as the event `user` field. `ip_address` is removed before sending unless `send.client` is enabled.
     *
     * @param array<string, mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->user = $user === [] ? null : $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /**
     * Set one extra value merged into every event (the capture call's own `$extra` wins on the same key).
     */
    public function setExtra(string $key, mixed $value): void
    {
        $key = trim($key);
        if ($key === '' || (!isset($this->extra[$key]) && count($this->extra) >= self::MAX_EXTRA)) {
            return;
        }

        $this->extra[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * Clear tags, user and extra values.
     */
    public function reset(): void
    {
        $this->clearTags();
        $this->user  = null;
        $this->extra = [];
    }

    private function normalizeKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        return mb_substr($key, 0, self::MAX_KEY_LENGTH);
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return mb_substr((string) $value, 0, self::MAX_VALUE_LENGTH);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return null;
    }
}
