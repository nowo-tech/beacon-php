<?php

declare(strict_types=1);

namespace Nowo\Beacon\Transport;

use Closure;
use DateTimeImmutable;
use Nowo\Beacon\Dsn\Dsn;
use Nowo\Beacon\Scrubber\SensitiveValueRedactor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function ctype_digit;
use function is_string;
use function max;
use function microtime;
use function str_replace;
use function trim;

/**
 * Maps Beacon ingest responses and transport failures to {@see TransportResult} and logs them like BeaconBundle's
 * `EnvelopeTransport` (same messages, same context keys, same loop-guard flag).
 *
 * Error messages pass through {@see sanitize()}: the secret and the auth header value are replaced by
 * `[Filtered]` even if an HTTP client library echoes request headers in an exception.
 *
 * @internal shared by the bundled transports
 */
final readonly class IngestResponseHandler
{
    /**
     * Context key (value `true`) set on every log record a transport writes. The Monolog handler skips flagged
     * records so a failing receiver cannot cause an endless report → fail → log chain. Same value as BeaconBundle.
     */
    public const LOG_CONTEXT_FLAG = 'nowo_beacon_transport';

    private LoggerInterface $logger;

    public function __construct(
        private Dsn $dsn,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param Closure(string): ?string $header first value of a response header (lower-case name)
     */
    public function fromStatus(int $status, Closure $header): TransportResult
    {
        if ($status >= 200 && $status < 300) {
            return new TransportResult(true, $status);
        }

        $context = [
            'status'               => $status,
            'url'                  => $this->dsn->getEnvelopeUrl(),
            self::LOG_CONTEXT_FLAG => true,
        ];

        $retryAfter = null;
        $rateLimit  = null;
        if ($status === 429 || $status === 503) {
            $raw        = $header('retry-after');
            $retryAfter = self::parseRetryAfter($raw);
            $rateLimit  = $header('x-beacon-rate-limit');
            if ($status === 429) {
                if ($raw !== null && $raw !== '') {
                    $context['retry_after'] = $raw;
                }
                $this->logger->warning('Beacon ingest rate limited (HTTP 429). Respect Retry-After before retrying.', $context);

                return new TransportResult(false, $status, null, $retryAfter, $rateLimit);
            }
        }

        if ($status === 401 || $status === 403) {
            $this->logger->warning(
                'Beacon ingest authentication rejected. Confirm BEACON_DSN includes public:secret and matches the project.',
                $context,
            );

            return new TransportResult(false, $status);
        }

        $this->logger->warning('Beacon ingest rejected envelope.', $context);

        return new TransportResult(false, $status, null, $retryAfter, $rateLimit);
    }

    /**
     * Transport-level failure (DNS, refused connection, TLS, timeout).
     */
    public function fromFailure(Throwable $exception): TransportResult
    {
        $message = $this->sanitize($exception->getMessage());
        $this->logger->error('Beacon ingest transport failed.', [
            'exception'            => $message,
            'url'                  => $this->dsn->getEnvelopeUrl(),
            self::LOG_CONTEXT_FLAG => true,
        ]);

        return new TransportResult(false, null, $message);
    }

    /**
     * Remove the secret / auth header value and credential-shaped substrings from a message.
     */
    public function sanitize(string $message): string
    {
        $message = str_replace(
            [$this->dsn->getBeaconAuthHeader(), $this->dsn->getSecretKey()],
            SensitiveValueRedactor::FILTERED,
            $message,
        );

        return SensitiveValueRedactor::redactMessage($message);
    }

    /**
     * `Retry-After` as seconds (delta-seconds or HTTP-date), null when absent or unparsable.
     */
    public static function parseRetryAfter(?string $value, ?float $now = null): ?float
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (ctype_digit($value)) {
            return (float) $value;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }

        return max(0.0, (float) $date->format('U.u') - ($now ?? microtime(true)));
    }
}
