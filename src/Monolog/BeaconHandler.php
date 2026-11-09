<?php

declare(strict_types=1);

namespace Nowo\Beacon\Monolog;

use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Nowo\Beacon\ClientInterface;
use Nowo\Beacon\Scrubber\SensitiveValueRedactor;
use Nowo\Beacon\Transport\IngestResponseHandler;
use Throwable;

use function is_array;
use function is_int;
use function is_scalar;
use function is_string;

/**
 * Monolog handler (Monolog 2 and 3; requires monolog/monolog) forwarding records to Beacon.
 *
 * Same event shape as BeaconBundle's `BeaconMonologHandler`: `extra.monolog` (`channel`, `level`), the record
 * context redacted with {@see SensitiveValueRedactor::redactMap()} under `extra.context`, an `exception` context
 * entry captured as an exception, levels mapped to `info` / `warning` / `error`.
 *
 * Records written by beacon-php itself (context flag {@see IngestResponseHandler::LOG_CONTEXT_FLAG}) are never
 * forwarded: reporting "Beacon ingest transport failed." through the failing transport would loop. They still bubble
 * to the other handlers.
 */
final readonly class BeaconHandler implements HandlerInterface
{
    private int $level;

    /**
     * @param int|Level|string $level minimum level (Monolog 2 int / name, Monolog 3 Level)
     */
    public function __construct(
        private ClientInterface $client,
        int|string|Level $level = 400,
        private bool $bubble = true,
    ) {
        $this->level = $this->toInt($level);
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function isHandling(LogRecord|array $record): bool
    {
        return $this->recordLevel($record) >= $this->level;
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function handle(LogRecord|array $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $context = $record instanceof LogRecord ? $record->context : (is_array($record['context'] ?? null) ? $record['context'] : []);
        if (($context[IngestResponseHandler::LOG_CONTEXT_FLAG] ?? false) !== true && $this->client->isEnabled()) {
            try {
                $this->write($record, $context);
            } catch (Throwable) {
                // Logging must never fail because Beacon did.
            }
        }

        return !$this->bubble;
    }

    /**
     * @param array<array<string, mixed>|LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     * @param array<array-key, mixed> $context
     */
    private function write(LogRecord|array $record, array $context): void
    {
        $level = $this->recordLevel($record);
        $extra = [
            'monolog' => [
                'channel' => $record instanceof LogRecord ? $record->channel : $this->string($record['channel'] ?? null),
                'level'   => $record instanceof LogRecord ? $record->level->getName() : $this->string($record['level_name'] ?? null),
            ],
        ];

        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        if ($context !== []) {
            $named = [];
            foreach ($context as $key => $value) {
                $named[(string) $key] = $value;
            }
            $extra['context'] = SensitiveValueRedactor::redactMap($named);
        }

        if ($exception instanceof Throwable) {
            $this->client->captureException($exception, $extra);

            return;
        }

        $message = $record instanceof LogRecord ? $record->message : $this->string($record['message'] ?? null);
        $this->client->captureMessage($message, $this->mapLevel($level), $extra);
    }

    /**
     * Monolog levels → Beacon levels (`info` / `warning` / `error`).
     */
    private function mapLevel(int $level): string
    {
        return match (true) {
            $level < 300 => 'info',
            $level < 400 => 'warning',
            default      => 'error',
        };
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     */
    private function recordLevel(LogRecord|array $record): int
    {
        if ($record instanceof LogRecord) {
            return $record->level->value;
        }

        $level = $record['level'] ?? 0;

        return is_int($level) ? $level : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toInt(int|string|Level $level): int
    {
        if ($level instanceof Level) {
            return $level->value;
        }
        if (is_string($level)) {
            $resolved = Logger::toMonologLevel($level); // @phpstan-ignore argument.type (validated by Monolog)

            // Monolog 3 returns a Level, Monolog 2 an int.
            return $resolved instanceof Level ? $resolved->value : (int) $resolved; // @phpstan-ignore instanceof.alwaysTrue
        }

        return $level;
    }
}
