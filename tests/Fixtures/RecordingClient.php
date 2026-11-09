<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Fixtures;

use Closure;
use Nowo\Beacon\ClientInterface;
use Nowo\Beacon\LifecycleInterface;
use Throwable;

final class RecordingClient implements ClientInterface, LifecycleInterface
{
    /** @var list<array{type: string, message: string, level?: string, extra: array<string, mixed>, fingerprint: list<string>|null, throwable?: Throwable}> */
    public array $events = [];

    /** @var list<float|null> */
    public array $flushes = [];

    public int $resets = 0;

    /** @var array<string, string> */
    private array $tags = [];

    /** @var (Closure(): void)|null */
    public ?Closure $onCapture = null;

    public function __construct(public bool $enabled = true)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): string
    {
        $this->events[] = ['type' => 'exception', 'message' => $throwable->getMessage(), 'extra' => $extra, 'fingerprint' => $fingerprint, 'throwable' => $throwable];
        if ($this->onCapture instanceof Closure) {
            ($this->onCapture)();
        }

        return 'id';
    }

    public function captureMessage(string $message, string $level = 'error', array $extra = [], ?array $fingerprint = null): string
    {
        $this->events[] = ['type' => 'message', 'message' => $message, 'level' => $level, 'extra' => $extra, 'fingerprint' => $fingerprint];
        if ($this->onCapture instanceof Closure) {
            ($this->onCapture)();
        }

        return 'id';
    }

    public function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
    }

    public function captureTransaction(string $transactionName, float $startTimestamp, float $endTimestamp, array $spans = [], array $extra = []): ?string
    {
        return null;
    }

    public function setTag(string $key, mixed $value): void
    {
        $this->tags[$key] = (string) $value;
    }

    public function setTags(array $tags): void
    {
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function clearTags(): void
    {
    }

    public function flush(?float $timeout = null): bool
    {
        $this->flushes[] = $timeout;

        return true;
    }

    public function reset(): void
    {
        ++$this->resets;
    }
}
