<?php

declare(strict_types=1);

namespace Nowo\Beacon\Envelope;

use JsonException;
use Nowo\Beacon\Exception\EnvelopeEncodingException;

use function is_string;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * One single-item Envelope before encoding: header line, item header line, payload line.
 *
 * {@see encode()} produces the same NDJSON as BeaconBundle (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, three
 * lines, trailing newline). Invalid UTF-8 is replaced by U+FFFD instead of failing (the bundle throws there).
 */
final readonly class Envelope
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $itemHeader
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $header,
        public array $itemHeader,
        public array $payload,
    ) {
    }

    /**
     * 32-hex event id from the header.
     */
    public function getEventId(): string
    {
        $id = $this->header['event_id'] ?? '';

        return is_string($id) ? $id : '';
    }

    /**
     * Item type (`event`, `transaction`, `deploy`, `queue_snapshot`, `schedule_catalog`, `check_in`).
     */
    public function getType(): string
    {
        $type = $this->itemHeader['type'] ?? '';

        return is_string($type) ? $type : '';
    }

    /**
     * Same envelope with another payload.
     *
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        return new self($this->header, $this->itemHeader, $payload);
    }

    /**
     * NDJSON body (`header\nitem\npayload\n`).
     *
     * @throws EnvelopeEncodingException
     */
    public function encode(): string
    {
        return self::encodeLine($this->header) . "\n"
            . self::encodeLine($this->itemHeader) . "\n"
            . self::encodeLine($this->payload) . "\n";
    }

    /**
     * Encode one value as a single JSON line.
     *
     * @throws EnvelopeEncodingException
     */
    public static function encodeLine(mixed $data): string
    {
        try {
            return json_encode($data, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new EnvelopeEncodingException('Failed to encode envelope JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
