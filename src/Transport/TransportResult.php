<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

/**
 * Outcome of sending (or queueing) one Envelope. Never carries the DSN or the secret.
 */
final readonly class TransportResult
{
    /**
     * @param float|null $retryAfter seconds to wait before the next send (429 / 503 `Retry-After`)
     * @param string|null $rateLimit `X-Beacon-Rate-Limit` value (`ip`, `key`, `project`, `quota`)
     */
    public function __construct(
        public bool $accepted,
        public ?int $statusCode = null,
        public ?string $errorMessage = null,
        public ?float $retryAfter = null,
        public ?string $rateLimit = null,
        public bool $queued = false,
    ) {
    }

    public static function queued(): self
    {
        return new self(true, queued: true);
    }

    /**
     * Whether Beacon accepted the envelope (HTTP 2xx), or it was queued for a later flush.
     */
    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Short error description for transport failures (secret-free).
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * A later attempt may succeed (rate limit, 5xx, network error).
     */
    public function isRetryable(): bool
    {
        if ($this->accepted) {
            return false;
        }

        return $this->statusCode === null || $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
