<?php

declare(strict_types=1);

namespace Nowo\Beacon\Tests\Unit\ErrorHandler;

use ErrorException;
use Nowo\Beacon\ErrorHandler\ErrorHandler;
use Nowo\Beacon\Tests\Fixtures\RecordingClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use WeakReference;

use function error_reporting;
use function gc_collect_cycles;
use function implode;
use function ini_get;
use function ini_set;
use function restore_error_handler;
use function restore_exception_handler;
use function set_error_handler;
use function set_exception_handler;
use function trigger_error;

use const E_ALL;
use const E_COMPILE_ERROR;
use const E_COMPILE_WARNING;
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

final class ErrorHandlerTest extends TestCase
{
    private int $errorReporting = 0;

    // PHPUnit lowers error_reporting() while a test runs (its handler takes everything); applications run with E_ALL.
    protected function setUp(): void
    {
        $this->errorReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
    }

    public function testCapturesWarningsWithBundleShapeAndChainsThePreviousHandler(): void
    {
        $client   = new RecordingClient();
        $previous = [];
        set_error_handler(static function (int $type, string $message) use (&$previous): bool {
            $previous[] = $message;

            return true;
        });
        $handler = new ErrorHandler($client);
        $handler->registerErrorHandler();
        $handler->registerErrorHandler();

        try {
            trigger_error('disk almost full', E_USER_WARNING);
            @trigger_error('silenced', E_USER_WARNING);
            @trigger_error('old api', E_USER_DEPRECATED);
        } finally {
            $handler->unregister();
            restore_error_handler();
        }

        self::assertSame(['disk almost full', 'silenced', 'old api'], $previous);
        self::assertCount(1, $client->events);
        $event = $client->events[0];
        self::assertSame('User Warning: disk almost full', $event['message']);
        self::assertSame('warning', $event['level'] ?? null);
        self::assertSame(E_USER_WARNING, $event['extra']['php_error']['type']);
        self::assertSame('User Warning', $event['extra']['php_error']['name']);
        self::assertSame(__FILE__, $event['extra']['php_error']['file']);
        self::assertSame('disk almost full', $event['extra']['php_error']['message']);
        self::assertSame('php-warning', $event['fingerprint'][0] ?? null);
    }

    public function testWithoutPreviousHandlerPhpHandlesTheErrorUnlessSwallowed(): void
    {
        $client = new RecordingClient();
        self::assertFalse((new ErrorHandler($client))->handleError(E_NOTICE, 'n', 'f.php', 1));
        self::assertTrue((new ErrorHandler($client, swallow: true))->handleError(E_NOTICE, 'n', 'f.php', 1));
        self::assertFalse((new ErrorHandler($client, swallow: true))->handleError(E_DEPRECATED, 'd', 'f.php', 1));
        self::assertSame('info', $client->events[0]['level'] ?? null);
        self::assertCount(2, $client->events);
    }

    public function testSilencedCaptureDisabledClientAndReentrancy(): void
    {
        $client  = new RecordingClient();
        $handler = new ErrorHandler($client, captureSilenced: true);
        $level   = error_reporting(0);
        try {
            $handler->handleError(E_WARNING, 'silenced', 'f.php', 2);
        } finally {
            error_reporting($level);
        }
        self::assertCount(1, $client->events);

        $client->enabled = false;
        $handler->handleError(E_WARNING, 'off', 'f.php', 3);
        self::assertCount(1, $client->events);

        $client->enabled   = true;
        $client->onCapture = static function () use ($handler): void {
            $handler->handleError(E_WARNING, 'nested', 'f.php', 4);
        };
        $handler->handleError(E_WARNING, 'outer', 'f.php', 5);
        self::assertCount(2, $client->events);

        $client->onCapture = static function (): never {
            throw new RuntimeException('reporting failed');
        };
        self::assertFalse($handler->handleError(E_WARNING, 'boom', 'f.php', 6));
    }

    public function testUncaughtExceptionIsCapturedOnceFlushedAndForwarded(): void
    {
        $client    = new RecordingClient();
        $forwarded = [];
        set_exception_handler(static function (Throwable $throwable) use (&$forwarded): void {
            $forwarded[] = $throwable;
        });
        $handler = new ErrorHandler($client, flushTimeout: 0.5);
        $handler->registerExceptionHandler();
        $handler->registerExceptionHandler();

        $exception = new RuntimeException('uncaught');
        try {
            $handler->handleException($exception);
            $handler->handleException($exception);
        } finally {
            $handler->unregister();
            restore_exception_handler();
        }

        self::assertCount(1, $client->events);
        self::assertSame([$exception, $exception], $forwarded);
        self::assertSame([0.5, 0.5], $client->flushes);
    }

    public function testWithoutPreviousExceptionHandlerTheExceptionIsRethrown(): void
    {
        $client  = new RecordingClient();
        $handler = new ErrorHandler($client);

        // An ErrorException built by another handler from an error already captured is not captured again.
        $handler->handleError(E_WARNING, 'converted', 'f.php', 9);
        try {
            $handler->handleException(new ErrorException('converted', 0, E_WARNING, 'f.php', 9));
            self::fail('Expected rethrow');
        } catch (ErrorException $exception) {
            self::assertSame('converted', $exception->getMessage());
        }
        self::assertCount(1, $client->events);

        // The "Uncaught …" fatal PHP raises after the rethrow is ignored.
        $handler->captureFatalError(['type' => E_ERROR, 'message' => 'Uncaught ErrorException: converted', 'file' => 'f.php', 'line' => 9]);
        self::assertCount(1, $client->events);

        $client->onCapture = static function (): never {
            throw new RuntimeException('reporting failed');
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('original');
        $handler->handleException(new RuntimeException('original'));
    }

    public function testFatalErrorsAreCapturedOnceAsErrorException(): void
    {
        $client  = new RecordingClient();
        $handler = new ErrorHandler($client);

        $handler->captureFatalError(null);
        $handler->captureFatalError(['type' => E_WARNING, 'message' => 'not fatal']);
        $handler->captureFatalError(['message' => 'no type']);
        self::assertSame([], $client->events);

        $fatal = ['type' => E_COMPILE_ERROR, 'message' => 'Cannot redeclare f()', 'file' => '/srv/app/x.php', 'line' => 3];
        $handler->captureFatalError($fatal);
        $handler->captureFatalError($fatal);
        self::assertCount(1, $client->events);
        $event = $client->events[0];
        self::assertInstanceOf(ErrorException::class, $event['throwable'] ?? null);
        self::assertSame(E_COMPILE_ERROR, $event['throwable']->getSeverity());
        self::assertSame(['fatal' => ['type' => E_COMPILE_ERROR, 'file' => '/srv/app/x.php', 'line' => 3]], $event['extra']);

        $handler->captureFatalError(['type' => E_PARSE]);
        self::assertSame('', $client->events[1]['message']);

        $client->enabled = false;
        $handler->captureFatalError(['type' => E_ERROR, 'message' => 'disabled']);
        self::assertCount(2, $client->events);
    }

    public function testOutOfMemoryRaisesTheLimitBeforeCapturing(): void
    {
        $client  = new RecordingClient();
        $handler = new ErrorHandler($client);
        $limit   = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '-1');
            $handler->captureFatalError(['type' => E_ERROR, 'message' => 'Allowed memory size of 1 bytes exhausted', 'file' => 'a', 'line' => 1]);
            self::assertSame('-1', ini_get('memory_limit'));

            ini_set('memory_limit', '1G');
            $handler->captureFatalError(['type' => E_ERROR, 'message' => 'Allowed memory size of 2 bytes exhausted', 'file' => 'a', 'line' => 2]);
            self::assertNotSame('1G', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $limit);
        }

        self::assertCount(2, $client->events);
    }

    public function testShutdownCapturesAndFlushesThenUnregisterDetaches(): void
    {
        $client  = new RecordingClient();
        $handler = new ErrorHandler($client, flushTimeout: 1.5);
        $handler->registerShutdownFunction();
        $handler->registerShutdownFunction();

        ErrorHandler::dispatchShutdown(WeakReference::create($handler));
        self::assertSame([1.5], $client->flushes);

        $quiet = new ErrorHandler($client, captureFatal: false);
        $quiet->handleShutdown();
        self::assertSame([1.5, 2.0], $client->flushes);

        $handler->unregister();
        $handler->handleShutdown();
        $handler->captureFatalError(['type' => E_ERROR, 'message' => 'after unregister']);
        self::assertCount(2, $client->flushes);
        self::assertSame([], $client->events);

        // Unregistered handlers rethrow without capturing.
        $this->expectException(RuntimeException::class);
        $handler->handleException(new RuntimeException('late'));
    }

    public function testRegisterFromOptionsAndUnregisterRestoresHandlers(): void
    {
        $client = new RecordingClient();

        $handler      = ErrorHandler::register($client, ['errors' => ErrorHandler::DEFAULT_LEVELS, 'capture_silenced' => false, 'exceptions' => true, 'fatal' => true, 'swallow' => false], 0.25);
        $currentError = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $currentException = set_exception_handler(null);
        restore_exception_handler();
        $handler->unregister();

        self::assertNotNull($currentError);
        self::assertNotNull($currentException);
        $afterError = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        self::assertNotSame($currentError, $afterError);

        $flushOnly = ErrorHandler::register($client, null);
        $before    = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        self::assertSame($afterError, $before);
        $flushOnly->unregister();
    }

    public function testUnregisterLeavesHandlersPushedLaterInPlace(): void
    {
        $handler = new ErrorHandler(new RecordingClient());
        $handler->registerErrorHandler();
        $handler->registerExceptionHandler();
        $later = static fn (): bool => true;
        set_error_handler($later);
        set_exception_handler(static function (): void {
        });

        $handler->unregister();

        $top = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        self::assertSame($later, $top);

        // Pop the later handlers and ours that stayed below them.
        restore_error_handler();
        restore_error_handler();
        restore_exception_handler();
        restore_exception_handler();
    }

    public function testLevelNamesAndNamedLevels(): void
    {
        self::assertSame('User Warning', ErrorHandler::levelName(E_USER_WARNING));
        self::assertSame('Warning', ErrorHandler::levelName(E_WARNING));
        self::assertSame('User Notice', ErrorHandler::levelName(E_USER_NOTICE));
        self::assertSame('Notice', ErrorHandler::levelName(E_NOTICE));
        self::assertSame('User Deprecated', ErrorHandler::levelName(E_USER_DEPRECATED));
        self::assertSame('Deprecated', ErrorHandler::levelName(E_DEPRECATED));
        self::assertSame('Core Warning', ErrorHandler::levelName(E_CORE_WARNING));
        self::assertSame('Compile Warning', ErrorHandler::levelName(E_COMPILE_WARNING));
        self::assertSame('Warning', ErrorHandler::levelName(E_ERROR));
        self::assertSame('User Error', ErrorHandler::levelName(E_USER_ERROR));
        self::assertSame('Recoverable Error', ErrorHandler::levelName(E_RECOVERABLE_ERROR));
        self::assertSame(E_USER_ERROR | E_RECOVERABLE_ERROR, ErrorHandler::levelsFromNames(['user_error', 'recoverable-error']));

        self::assertSame(E_WARNING | E_USER_DEPRECATED, ErrorHandler::levelsFromNames(['Warning', 'user-deprecated', 'unknown']));
        self::assertSame(E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING, ErrorHandler::levelsFromNames(['notice', 'user notice', 'deprecated', 'user_warning', 'core_warning', 'compile_warning']));
    }

    public function testErrorLevelsAreReportedAsErrorsAndFingerprintsAreRedacted(): void
    {
        $client  = new RecordingClient();
        $handler = new ErrorHandler($client, E_USER_ERROR | E_RECOVERABLE_ERROR | E_WARNING);

        $handler->handleError(E_USER_ERROR, 'stop', 'f.php', 1);
        $handler->handleError(E_RECOVERABLE_ERROR, 'bad arg', 'f.php', 2);
        $handler->handleError(E_WARNING, 'file_get_contents(https://user:t0ken@api.example/x): failed', 'f.php', 3);

        self::assertSame('error', $client->events[0]['level'] ?? null);
        self::assertSame('User Error: stop', $client->events[0]['message']);
        self::assertSame('error', $client->events[1]['level'] ?? null);
        $fingerprint = $client->events[2]['fingerprint'] ?? [];
        self::assertStringNotContainsString('t0ken', implode('|', $fingerprint));
        self::assertSame('file_get_contents(https://user:[Filtered]@api.example/x): failed', $fingerprint[2] ?? null);
    }

    public function testRegisteredHandlerStaysAliveUntilUnregistered(): void
    {
        $before    = ErrorHandler::countRegistered();
        $client    = new RecordingClient();
        $handler   = ErrorHandler::register($client, ['errors' => 0, 'capture_silenced' => false, 'exceptions' => false, 'fatal' => true, 'swallow' => false]);
        $reference = WeakReference::create($handler);
        unset($handler);
        gc_collect_cycles();

        self::assertSame($before + 1, ErrorHandler::countRegistered());
        $alive = $reference->get();
        self::assertInstanceOf(ErrorHandler::class, $alive);

        $alive->unregister();
        self::assertSame($before, ErrorHandler::countRegistered());
    }
}
