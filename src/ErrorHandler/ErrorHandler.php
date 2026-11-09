<?php

declare(strict_types=1);

namespace Nowo\Beacon\ErrorHandler;

use Closure;
use ErrorException;
use Nowo\Beacon\ClientInterface;
use Nowo\Beacon\LifecycleInterface;
use Nowo\Beacon\Scrubber\SensitiveValueRedactor;
use ReflectionFunction;
use Throwable;
use WeakMap;
use WeakReference;

use function count;
use function error_get_last;
use function error_reporting;
use function in_array;
use function ini_get;
use function ini_set;
use function is_int;
use function is_string;
use function memory_get_usage;
use function register_shutdown_function;
use function restore_error_handler;
use function restore_exception_handler;
use function set_error_handler;
use function set_exception_handler;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strtolower;

use const E_COMPILE_ERROR;
use const E_COMPILE_WARNING;
use const E_CORE_ERROR;
use const E_CORE_WARNING;
use const E_DEPRECATED;
use const E_ERROR;
use const E_NOTICE;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_USER_DEPRECATED;
use const E_USER_ERROR;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;

/**
 * PHP error, uncaught-exception and fatal-shutdown reporting for one client.
 *
 * - Errors ({@see handleError()}): the levels in `$levels` (default: warnings and notices, as BeaconBundle) are
 *   captured as messages with the bundle's shape (`"Warning: …"`, `extra.php_error`, `php-warning` fingerprint).
 *   `@`-silenced errors and levels excluded by `error_reporting()` are skipped unless `$captureSilenced`. The previous
 *   handler is always called next (its return value is returned); without one, PHP's standard handler runs unless
 *   `$swallow` is true.
 * - Uncaught exceptions ({@see handleException()}): captured once, then passed to the previous exception handler, or
 *   rethrown so that PHP prints / logs it and exits with code 255 as without a handler.
 * - Shutdown ({@see handleShutdown()}): a fatal error from `error_get_last()` (E_ERROR, E_PARSE, E_CORE_ERROR,
 *   E_COMPILE_ERROR, E_USER_ERROR) is captured as an `ErrorException` with `extra.fatal`, then the client is flushed
 *   with `$flushTimeout`. Worst case at shutdown: one synchronous send of the fatal / uncaught event (bounded by the
 *   transport's own request timeout, `timeout` option for the bundled clients) plus the flush (`$flushTimeout`, plus
 *   at most one in-progress request timeout). A PSR-18 client without a timeout of its own is not bounded at all.
 *   32 KiB are reserved up front and released at shutdown so an out-of-memory fatal can still be reported.
 *
 * No double capture: an exception already captured (or rethrown by a chained handler) is not captured again, an
 * `ErrorException` that a previous error handler built from an error this handler already captured is skipped, and the
 * "Uncaught …" fatal PHP raises after a rethrow is ignored.
 *
 * State lives in the instance; {@see unregister()} restores the previous handlers when ours are still on top and turns
 * the shutdown function into a no-op (PHP cannot remove shutdown functions; it only holds a weak reference).
 */
final class ErrorHandler
{
    /** Warnings + notices (not deprecations), BeaconBundle's `BeaconPhpWarningHandler::DEFAULT_LEVELS`. */
    public const DEFAULT_LEVELS = E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_CORE_WARNING | E_COMPILE_WARNING;

    public const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private const RESERVED_MEMORY_BYTES = 32768;

    private const OOM_EXTRA_BYTES = 5 * 1024 * 1024;

    /** @var array<int, self> handlers with a shutdown function, until unregister() */
    private static array $registered = [];

    private ?ClientInterface $client;

    /** @var (callable(int, string, string, int): bool)|null */
    private $previousErrorHandler;

    /** @var (callable(Throwable): void)|null */
    private $previousExceptionHandler;

    private bool $errorHandlerInstalled = false;

    private bool $exceptionHandlerInstalled = false;

    private bool $shutdownRegistered = false;

    private bool $handlingError = false;

    private bool $uncaughtSeen = false;

    private ?string $lastErrorKey = null;

    /** @phpstan-ignore property.onlyWritten (memory reserved for an out-of-memory fatal, released at shutdown) */
    private ?string $reservedMemory = null;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $captured;

    public function __construct(
        ClientInterface $client,
        private readonly int $levels = self::DEFAULT_LEVELS,
        private readonly bool $captureSilenced = false,
        private readonly bool $swallow = false,
        private readonly bool $captureFatal = true,
        private readonly float $flushTimeout = 2.0,
    ) {
        $this->client   = $client;
        $this->captured = new WeakMap();
    }

    /**
     * Create and install the handlers selected by an `error_handler` option array (null: shutdown flush only).
     *
     * @param array{errors: int, capture_silenced: bool, exceptions: bool, fatal: bool, swallow: bool}|null $config
     */
    public static function register(ClientInterface $client, ?array $config, float $flushTimeout = 2.0): self
    {
        $handler = new self(
            $client,
            $config['errors'] ?? 0,
            $config['capture_silenced'] ?? false,
            $config['swallow'] ?? false,
            $config['fatal'] ?? false,
            $flushTimeout,
        );

        if ($handler->levels !== 0) {
            $handler->registerErrorHandler();
        }
        if ($config !== null && $config['exceptions']) {
            $handler->registerExceptionHandler();
        }
        $handler->registerShutdownFunction();

        return $handler;
    }

    public function registerErrorHandler(): void
    {
        if ($this->errorHandlerInstalled) {
            return;
        }

        $this->errorHandlerInstalled = true;
        $this->previousErrorHandler  = set_error_handler($this->handleError(...));
    }

    public function registerExceptionHandler(): void
    {
        if ($this->exceptionHandlerInstalled) {
            return;
        }

        $this->exceptionHandlerInstalled = true;
        $this->previousExceptionHandler  = set_exception_handler($this->handleException(...));
    }

    public function registerShutdownFunction(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        $this->reservedMemory     = str_repeat('x', self::RESERVED_MEMORY_BYTES);
        // Kept alive until unregister(), even when the caller drops the returned handler (a fatal-only handler has no
        // set_*_handler closure holding it). The shutdown function itself only holds a weak reference.
        self::$registered[spl_object_id($this)] = $this;
        register_shutdown_function(self::dispatchShutdown(...), WeakReference::create($this));
    }

    /**
     * The registered shutdown function.
     *
     * @param WeakReference<self> $handler
     *
     * @internal
     */
    public static function dispatchShutdown(WeakReference $handler): void
    {
        $handler->get()?->handleShutdown();
    }

    /**
     * Restore the previous handlers (when ours are still the current ones) and detach the client.
     */
    public function unregister(): void
    {
        if ($this->errorHandlerInstalled) {
            $this->errorHandlerInstalled = false;
            if ($this->isCurrentErrorHandler()) {
                restore_error_handler();
            }
        }

        if ($this->exceptionHandlerInstalled) {
            $this->exceptionHandlerInstalled = false;
            if ($this->isCurrentExceptionHandler()) {
                restore_exception_handler();
            }
        }

        $this->previousErrorHandler     = null;
        $this->previousExceptionHandler = null;
        $this->reservedMemory           = null;
        $this->client                   = null;
        unset(self::$registered[spl_object_id($this)]);
    }

    /**
     * Number of handlers kept alive by {@see registerShutdownFunction()} (diagnostics, tests).
     */
    public static function countRegistered(): int
    {
        return count(self::$registered);
    }

    /**
     * `set_error_handler()` callback.
     */
    public function handleError(int $type, string $message, string $file = '', int $line = 0): bool
    {
        $client   = $this->client;
        $reported = (error_reporting() & $type) !== 0;
        $handled  = ($type & $this->levels) !== 0 && ($reported || $this->captureSilenced);

        if ($handled && $client instanceof ClientInterface && $client->isEnabled() && !$this->handlingError) {
            $this->handlingError = true;
            try {
                $this->lastErrorKey = $this->errorKey($type, $message, $file, $line);
                $this->captureError($client, $type, $message, $file, $line);
            } catch (Throwable) {
                // Never let reporting break the application flow.
            } finally {
                $this->handlingError = false;
            }
        }

        if ($this->previousErrorHandler !== null) {
            return (bool) ($this->previousErrorHandler)($type, $message, $file, $line);
        }

        return $handled && $this->swallow;
    }

    /**
     * `set_exception_handler()` callback.
     *
     * @throws Throwable the exception itself when there is no previous handler (PHP then reports it as usual)
     */
    public function handleException(Throwable $throwable): void
    {
        $this->uncaughtSeen = true;
        $this->captureOnce($throwable);

        if ($this->client instanceof LifecycleInterface) {
            $this->client->flush($this->flushTimeout);
        }

        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($throwable);

            return;
        }

        throw $throwable;
    }

    /**
     * Shutdown function: capture a fatal error, then flush (bounded by the flush timeout).
     */
    public function handleShutdown(): void
    {
        $this->reservedMemory = null;
        $client               = $this->client;
        if (!$client instanceof ClientInterface) {
            return;
        }

        if ($this->captureFatal) {
            $this->captureFatalError(error_get_last());
        }

        if ($client instanceof LifecycleInterface) {
            $client->flush($this->flushTimeout);
        }
    }

    /**
     * Capture one `error_get_last()` entry when it is a fatal error.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int}|null $error
     *
     * @internal public for tests (fatal errors cannot be raised inside a test process)
     */
    public function captureFatalError(?array $error): void
    {
        $client = $this->client;
        if ($error === null || !$client instanceof ClientInterface || !$client->isEnabled()) {
            return;
        }

        $type = $error['type'] ?? null;
        if (!is_int($type) || !in_array($type, self::FATAL_TYPES, true)) {
            return;
        }

        $message = $error['message'] ?? '';
        $file    = $error['file'] ?? '';
        $line    = $error['line'] ?? 0;

        // The fatal PHP raises after an exception handler rethrew (already captured).
        if ($this->uncaughtSeen && $type === E_ERROR && str_starts_with($message, 'Uncaught ')) {
            return;
        }

        $key = $this->errorKey($type, $message, $file, $line);
        if ($key === $this->lastErrorKey) {
            return;
        }
        $this->lastErrorKey = $key;

        if (str_starts_with($message, 'Allowed memory size of')) {
            $this->raiseMemoryLimit();
        }

        $client->captureException(new ErrorException($message, 0, $type, $file, $line), [
            'fatal' => [
                'type' => $type,
                'file' => $file,
                'line' => $line,
            ],
        ]);
    }

    /**
     * Map a PHP error type to a label (`Warning`, `User Notice`, …).
     */
    public static function levelName(int $type): string
    {
        return match (true) {
            0 !== ($type & E_USER_ERROR)        => 'User Error',
            0 !== ($type & E_RECOVERABLE_ERROR) => 'Recoverable Error',
            0 !== ($type & E_USER_WARNING)      => 'User Warning',
            0 !== ($type & E_WARNING)           => 'Warning',
            0 !== ($type & E_USER_NOTICE)       => 'User Notice',
            0 !== ($type & E_NOTICE)            => 'Notice',
            0 !== ($type & E_USER_DEPRECATED)   => 'User Deprecated',
            0 !== ($type & E_DEPRECATED)        => 'Deprecated',
            0 !== ($type & E_CORE_WARNING)      => 'Core Warning',
            0 !== ($type & E_COMPILE_WARNING)   => 'Compile Warning',
            default                             => 'Warning',
        };
    }

    /**
     * Resolve named PHP error levels (`warning`, `user_notice`, `deprecated`, …) into a bit field.
     *
     * @param list<string> $names
     */
    public static function levelsFromNames(array $names): int
    {
        $map = [
            'warning'           => E_WARNING,
            'user_warning'      => E_USER_WARNING,
            'notice'            => E_NOTICE,
            'user_notice'       => E_USER_NOTICE,
            'deprecated'        => E_DEPRECATED,
            'user_deprecated'   => E_USER_DEPRECATED,
            'core_warning'      => E_CORE_WARNING,
            'compile_warning'   => E_COMPILE_WARNING,
            'user_error'        => E_USER_ERROR,
            'recoverable_error' => E_RECOVERABLE_ERROR,
        ];

        $bits = 0;
        foreach ($names as $name) {
            $key = strtolower(str_replace(['-', ' '], '_', $name));
            $bits |= $map[$key] ?? 0;
        }

        return $bits;
    }

    private function captureOnce(Throwable $throwable): void
    {
        $client = $this->client;
        if (!$client instanceof ClientInterface || isset($this->captured[$throwable])) {
            return;
        }
        $this->captured[$throwable] = true;

        if ($throwable instanceof ErrorException
            && $this->errorKey($throwable->getSeverity(), $throwable->getMessage(), $throwable->getFile(), $throwable->getLine()) === $this->lastErrorKey) {
            return;
        }

        try {
            $client->captureException($throwable);
        } catch (Throwable) {
            // Never let reporting replace the original exception.
        }
    }

    private function captureError(ClientInterface $client, int $type, string $message, string $file, int $line): void
    {
        $label = self::levelName($type);
        $level = match (true) {
            0 !== ($type & (E_USER_ERROR | E_RECOVERABLE_ERROR))                              => 'error',
            0 !== ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'warning',
            default                                                                           => 'info',
        };

        $client->captureMessage(
            $label . ': ' . $message,
            $level,
            [
                'php_error' => [
                    'type'    => $type,
                    'name'    => $label,
                    'file'    => $file,
                    'line'    => $line,
                    'message' => $message,
                ],
            ],
            [
                'php-warning',
                $label,
                // The fingerprint is stored as is by Beacon: no secret may survive in it.
                SensitiveValueRedactor::redactMessage($message),
                $file . ':' . $line,
            ],
        );
    }

    private function isCurrentErrorHandler(): bool
    {
        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $current instanceof Closure && $this->isOwnHandler($current, 'handleError');
    }

    private function isCurrentExceptionHandler(): bool
    {
        $current = set_exception_handler(null);
        restore_exception_handler();

        return $current instanceof Closure && $this->isOwnHandler($current, 'handleException');
    }

    private function isOwnHandler(Closure $closure, string $method): bool
    {
        $reflection = new ReflectionFunction($closure);

        return $reflection->getClosureThis() === $this && $reflection->getName() === $method;
    }

    private function errorKey(int $type, string $message, string $file, int $line): string
    {
        return $type . "\0" . $message . "\0" . $file . "\0" . $line;
    }

    private function raiseMemoryLimit(): void
    {
        $limit = ini_get('memory_limit');
        if (!is_string($limit) || $limit === '-1') {
            return;
        }

        @ini_set('memory_limit', (string) (memory_get_usage(true) + self::OOM_EXTRA_BYTES));
    }
}
