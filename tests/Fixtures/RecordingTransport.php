<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures;

use Nowo\Beacon\Transport\TransportInterface;
use Nowo\Beacon\Transport\TransportResult;

use function array_shift;
use function explode;
use function json_decode;
use function rtrim;

use const JSON_THROW_ON_ERROR;

final class RecordingTransport implements TransportInterface
{
    /** @var list<string> */
    public array $bodies = [];

    /** @var list<float> */
    public array $flushes = [];

    /** @var list<TransportResult> */
    public array $results = [];

    public bool $flushResult = true;

    public function send(string $envelopeBody): TransportResult
    {
        $this->bodies[] = $envelopeBody;

        return array_shift($this->results) ?? new TransportResult(true, 200);
    }

    public function flush(float $timeout): bool
    {
        $this->flushes[] = $timeout;

        return $this->flushResult;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    public function lastEnvelope(): array
    {
        $body  = $this->bodies[array_key_last($this->bodies) ?? 0] ?? '';
        $lines = explode("\n", rtrim($body, "\n"));

        /* @var array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} */
        return [
            (array) json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR),
            (array) json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR),
            (array) json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        return $this->lastEnvelope()[2];
    }
}
