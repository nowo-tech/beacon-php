<?php

declare(strict_types=1);

namespace Nowo\Beacon;

use Nowo\Beacon\Dsn\InvalidDsnException;
use Nowo\Beacon\ErrorHandler\ErrorHandler;
use Nowo\Beacon\Exception\ConfigurationException;
use Nowo\Beacon\Exception\MissingHttpClientException;
use SensitiveParameter;
use Throwable;

use function is_string;

/**
 * Static facade over one current client, for plain PHP scripts and frameworks without a container.
 *
 * ```php
 * Beacon::init($_ENV['BEACON_DSN']);                    // or Beacon::init(['dsn' => …, 'release' => '1.4.2'])
 * Beacon::captureException($e);
 * ```
 *
 * {@see init()} creates a {@see Client} and, unless `error_handler: false`, installs the error / exception / fatal
 * handlers ({@see ErrorHandler}) and a shutdown flush bounded by `shutdown_timeout`. Calling it again closes the
 * previous client and restores the previous handlers first. This class is the package's only global state; code that
 * needs several clients (multi-tenant, tests) uses `new Client(...)` instances instead.
 */
final class Beacon
{
    private static ?ClientInterface $client = null;

    private static ?ErrorHandler $errorHandler = null;

    /**
     * @param array<string, mixed>|Options|string $dsnOrOptions DSN string, options array or Options
     *
     * @throws ConfigurationException
     * @throws InvalidDsnException
     * @throws MissingHttpClientException
     */
    public static function init(#[SensitiveParameter] string|array|Options $dsnOrOptions = []): Client
    {
        $options = match (true) {
            $dsnOrOptions instanceof Options => $dsnOrOptions,
            is_string($dsnOrOptions)         => Options::fromDsn($dsnOrOptions),
            default                          => Options::fromArray($dsnOrOptions),
        };

        $client = new Client($options);
        self::close();
        self::$client       = $client;
        self::$errorHandler = ErrorHandler::register($client, $options->errorHandler, $options->shutdownTimeout);

        return $client;
    }

    /**
     * Use a client built elsewhere (no handler is installed).
     */
    public static function setClient(?ClientInterface $client): void
    {
        self::close();
        self::$client = $client;
    }

    /**
     * The current client ({@see NullClient} before {@see init()}).
     */
    public static function getClient(): ClientInterface
    {
        return self::$client ?? new NullClient();
    }

    public static function getErrorHandler(): ?ErrorHandler
    {
        return self::$errorHandler;
    }

    public static function isEnabled(): bool
    {
        return self::getClient()->isEnabled();
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     */
    public static function captureException(Throwable $throwable, array $extra = [], ?array $fingerprint = null): ?string
    {
        return self::getClient()->captureException($throwable, $extra, $fingerprint);
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string>|null $fingerprint
     */
    public static function captureMessage(string $message, string $level = 'error', array $extra = [], ?array $fingerprint = null): ?string
    {
        return self::getClient()->captureMessage($message, $level, $extra, $fingerprint);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function addBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        self::getClient()->addBreadcrumb($message, $category, $level, $data);
    }

    /**
     * @param list<array{op?: string, description?: string, span_id?: string, start_timestamp?: float, timestamp?: float}> $spans
     * @param array<string, mixed> $extra
     */
    public static function captureTransaction(string $name, float $startTimestamp, float $endTimestamp, array $spans = [], array $extra = []): ?string
    {
        return self::getClient()->captureTransaction($name, $startTimestamp, $endTimestamp, $spans, $extra);
    }

    /**
     * @param array{release: string, environment?: string|null, commit?: string|null, url?: string|null, name?: string|null, deployed_at?: float|null} $deploy
     */
    public static function captureDeploy(array $deploy): ?string
    {
        $client = self::getClient();

        return $client instanceof DeployCapableInterface ? $client->captureDeploy($deploy) : null;
    }

    /**
     * @param array<string, mixed> $checkIn
     */
    public static function captureCheckIn(array $checkIn): ?string
    {
        $client = self::getClient();

        return $client instanceof CheckInCapableInterface ? $client->captureCheckIn($checkIn) : null;
    }

    /**
     * @param list<array{name: string, entries: list<array<string, mixed>>}> $schedules
     */
    public static function captureScheduleCatalog(array $schedules): ?string
    {
        $client = self::getClient();

        return $client instanceof CheckInCapableInterface ? $client->captureScheduleCatalog($schedules) : null;
    }

    /**
     * @param list<array{name: string, count: int|null, failure: bool}> $transports
     */
    public static function captureQueueSnapshot(array $transports): ?string
    {
        $client = self::getClient();

        return $client instanceof QueueSnapshotCapableInterface ? $client->captureQueueSnapshot($transports) : null;
    }

    public static function setTag(string $key, mixed $value): void
    {
        self::getClient()->setTag($key, $value);
    }

    /**
     * @param array<string, mixed> $tags
     */
    public static function setTags(array $tags): void
    {
        self::getClient()->setTags($tags);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public static function setUser(?array $user): void
    {
        $client = self::getClient();
        if ($client instanceof Client) {
            $client->setUser($user);
        }
    }

    public static function setExtra(string $key, mixed $value): void
    {
        $client = self::getClient();
        if ($client instanceof Client) {
            $client->setExtra($key, $value);
        }
    }

    public static function flush(?float $timeout = null): bool
    {
        $client = self::getClient();

        return !$client instanceof LifecycleInterface || $client->flush($timeout);
    }

    /**
     * Per-request / per-job reset for long-running workers (see {@see LifecycleInterface::reset()}).
     */
    public static function reset(): void
    {
        $client = self::getClient();
        if ($client instanceof LifecycleInterface) {
            $client->reset();
        }
    }

    /**
     * Flush the current client, restore the previous PHP handlers and forget the client.
     */
    public static function close(?float $timeout = null): bool
    {
        $client  = self::$client;
        $flushed = true;
        if ($client instanceof Client) {
            $flushed = $client->close($timeout);
        } elseif ($client instanceof LifecycleInterface) {
            $flushed = $client->flush($timeout);
        }

        self::$errorHandler?->unregister();
        self::$errorHandler = null;
        self::$client       = null;

        return $flushed;
    }
}
