<?php

declare(strict_types=1);

namespace Nowo\Beacon\Ingest;

use InvalidArgumentException;
use Nowo\Beacon\Dsn\Dsn;

use function in_array;
use function rawurlencode;

/**
 * Beacon ingest URLs (D-20261110-04): the versioned canonical paths and the unversioned legacy paths, which Beacon
 * keeps as permanent aliases.
 *
 * | Endpoint | Versioned (canonical, Beacon ≥ 1.147) | Legacy (every Beacon) |
 * | --- | --- | --- |
 * | Envelope | `/api/ingest/v1/{projectId}/envelope/` | `/api/{projectId}/envelope/` |
 * | OTLP/HTTP | `/api/ingest/v1/{projectId}/otlp/v1/{traces\|logs\|metrics}` | `/api/{projectId}/otlp/v1/{signal}` |
 * | Remote commands | `/api/ingest/v1/{projectId}/messenger/commands/claim`, `…/{id}/result` | `/api/{projectId}/messenger/commands/…` |
 *
 * The URLs never carry credentials. `Dsn::getEnvelopeUrl()` keeps returning the legacy envelope URL (BeaconBundle
 * compatibility).
 */
final class IngestPaths
{
    public const VERSIONED_PREFIX = '/api/ingest/v1/';

    public const LEGACY_PREFIX = '/api/';

    public const OTLP_SIGNALS = ['traces', 'logs', 'metrics'];

    /** 4xx statuses that only a Beacon ingest controller emits, so the route exists ({@see provesRoute()}). */
    public const ROUTE_PROOF_STATUSES = [400, 401, 403, 413, 415, 422];

    /**
     * Envelope ingest URL.
     */
    public static function envelopeUrl(Dsn $dsn, bool $versioned): string
    {
        return self::projectUrl($dsn, $versioned) . '/envelope/';
    }

    /**
     * OTLP/HTTP URL of one signal (`traces`, `logs` or `metrics`).
     *
     * @throws InvalidArgumentException for another signal
     */
    public static function otlpUrl(Dsn $dsn, string $signal, bool $versioned): string
    {
        if (!in_array($signal, self::OTLP_SIGNALS, true)) {
            throw new InvalidArgumentException('The OTLP signal must be "traces", "logs" or "metrics".');
        }

        return self::otlpEndpoint($dsn, $versioned) . '/v1/' . $signal;
    }

    /**
     * OTLP/HTTP exporter base endpoint (the exporter appends `/v1/{signal}`), like beacon-js `otlpEndpoint()`.
     */
    public static function otlpEndpoint(Dsn $dsn, bool $versioned): string
    {
        return self::projectUrl($dsn, $versioned) . '/otlp';
    }

    /**
     * Remote Messenger command claim URL (`POST …/messenger/commands/claim`).
     */
    public static function messengerClaimUrl(Dsn $dsn, bool $versioned): string
    {
        return self::projectPathUrl($dsn, '/messenger/commands/claim', $versioned);
    }

    /**
     * Remote Messenger command result URL (`POST …/messenger/commands/{id}/result`).
     */
    public static function messengerResultUrl(Dsn $dsn, string $commandId, bool $versioned): string
    {
        return self::projectPathUrl($dsn, '/messenger/commands/' . rawurlencode($commandId) . '/result', $versioned);
    }

    /**
     * Any project-scoped ingest URL; `$path` starts with `/` (same helper as BeaconBundle's `IngestPaths`).
     */
    public static function projectPathUrl(Dsn $dsn, string $path, bool $versioned): string
    {
        return self::projectUrl($dsn, $versioned) . $path;
    }

    /**
     * Whether an answer on the versioned path means "this Beacon has no such route" (older than 1.147): 404, or
     * 405 when another route matched the path.
     */
    public static function isRouteMissing(int $status): bool
    {
        return $status === 404 || $status === 405;
    }

    /**
     * Whether an answer proves that the route exists: 2xx, or a 4xx that Beacon's ingest controllers emit (400 bad
     * envelope, 401 / 403 auth, 413 too large, 415 media type, 422 invalid payload). 408, 429, 5xx and other statuses can
     * come from a proxy, a load balancer or an outage in front of any Beacon version, so they prove nothing.
     */
    public static function provesRoute(int $status): bool
    {
        return ($status >= 200 && $status < 300) || in_array($status, self::ROUTE_PROOF_STATUSES, true);
    }

    private static function projectUrl(Dsn $dsn, bool $versioned): string
    {
        return $dsn->getOrigin() . ($versioned ? self::VERSIONED_PREFIX : self::LEGACY_PREFIX) . rawurlencode($dsn->getProjectId());
    }
}
