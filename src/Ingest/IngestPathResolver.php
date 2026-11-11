<?php

declare(strict_types=1);

namespace Nowo\Beacon\Ingest;

use Nowo\Beacon\Dsn\Dsn;

use function array_slice;
use function count;
use function rawurlencode;

/**
 * Remembers, per Beacon target (DSN origin + project id), whether that Beacon serves the versioned ingest paths
 * (`ingest_paths: auto`).
 *
 * In `auto` mode a target that is not known to serve the legacy paths only is sent to the versioned path. The answer
 * there decides what happens (audit LO-M1):
 *
 * - 2xx, or a 400 / 401 / 403 / 413 / 415 / 422 that Beacon's ingest controllers emit → the route exists: versioned;
 * - 404 / 405 → the route is missing (Beacon < 1.147): the same request goes once more to the legacy path. When the
 *   target was known as versioned (a Beacon downgrade, a stale entry) the entry is forgotten first (self-heal);
 * - anything else (408, 429, 5xx, other statuses from a proxy or a load balancer) or a network failure → nothing is
 *   learned and nothing is re-sent; the next request probes again.
 *
 * The legacy answer after a 404 / 405: 2xx or one of the Beacon 4xx above → legacy; 404 / 405 on both paths (unknown
 * project, not a path problem) → versioned; anything else → nothing learned. The newest answer always replaces the
 * older one. A 404 / 405 means nothing was processed, so the legacy resend never duplicates an accepted request.
 *
 * The key includes the project id so that a DSN with an unknown project (both paths 404) cannot pin the versioned
 * path for another project on the same older Beacon.
 *
 * Worker-safe (FrankenPHP worker mode without kernel reset): the map holds only a server capability (target → bool),
 * never keys, secrets, request, user or tenant data, and is bounded to {@see MAX_ENTRIES} entries (oldest dropped). The
 * shared instance lives for the process: a long-running worker probes each older Beacon once, with one more 404 only
 * after a transient error or a self-heal. Under PHP-FPM the process state lasts one request, so every request that
 * sends something probes again (one extra 404 per request against Beacon < 1.147; set `ingest_paths: legacy` until
 * Beacon is upgraded). {@see reset()} forgets every target.
 */
final class IngestPathResolver
{
    public const MAX_ENTRIES = 32;

    private static ?self $shared = null;

    /** @var array<string, bool> target => true when the target serves the versioned paths */
    private array $known = [];

    private readonly IngestPathMode $mode;

    /**
     * @param 'auto'|'legacy'|'versioned'|IngestPathMode $mode
     */
    public function __construct(IngestPathMode|string $mode = IngestPathMode::Auto)
    {
        $this->mode = $mode instanceof IngestPathMode ? $mode : IngestPathMode::from($mode);
    }

    /**
     * Process-wide `auto` resolver used by default, so every client and transport in the process (and every request
     * of a worker) learns a target once. Like beacon-js' module-level cache.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Configured mode.
     */
    public function mode(): IngestPathMode
    {
        return $this->mode;
    }

    /**
     * Whether the first request to the DSN's Beacon goes to the versioned path.
     */
    public function useVersioned(Dsn $dsn): bool
    {
        return match ($this->mode) {
            IngestPathMode::Versioned => true,
            IngestPathMode::Legacy    => false,
            IngestPathMode::Auto      => $this->known[$this->key($dsn)] ?? true,
        };
    }

    /**
     * Whether the DSN's target is still unknown (auto mode, no answer that proves a path yet).
     */
    public function isProbing(Dsn $dsn): bool
    {
        return $this->mode === IngestPathMode::Auto && !isset($this->known[$this->key($dsn)]);
    }

    /**
     * Whether a request to the versioned path may have to be re-sent to the legacy path (auto mode): its caller keeps
     * the body until the status is read.
     */
    public function mayFallBack(): bool
    {
        return $this->mode === IngestPathMode::Auto;
    }

    /**
     * Report the HTTP status of a request sent to the versioned path. Returns true when the caller must send the same
     * request once more to the legacy path (auto mode and 404 / 405, including on a target known as versioned).
     */
    public function versionedAnswered(Dsn $dsn, int $status): bool
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return false;
        }

        $key = $this->key($dsn);
        if (IngestPaths::isRouteMissing($status)) {
            // Self-heal: a target known as versioned that now has no such route is probed again.
            unset($this->known[$key]);

            return true;
        }

        if (IngestPaths::provesRoute($status)) {
            $this->learn($key, true);
        }

        return false;
    }

    /**
     * Report the HTTP status of the legacy retry that followed a 404 / 405 on the versioned path.
     */
    public function legacyAnswered(Dsn $dsn, int $status): void
    {
        if ($this->mode !== IngestPathMode::Auto) {
            return;
        }

        if (IngestPaths::isRouteMissing($status)) {
            // Both paths missing: the project is unknown, not the path. Keep the canonical path.
            $this->learn($this->key($dsn), true);
        } elseif (IngestPaths::provesRoute($status)) {
            $this->learn($this->key($dsn), false);
        }
    }

    /**
     * Number of targets remembered (diagnostics / tests).
     */
    public function count(): int
    {
        return count($this->known);
    }

    /**
     * Forget every target (tests, or after a Beacon upgrade without a restart).
     */
    public function reset(): void
    {
        $this->known = [];
    }

    /**
     * Cache key: origin + project id. Never the keys or the secret.
     */
    private function key(Dsn $dsn): string
    {
        return $dsn->getOrigin() . '/' . rawurlencode($dsn->getProjectId());
    }

    /**
     * Remember the newest answer (it replaces an older one and becomes the newest entry).
     */
    private function learn(string $key, bool $versioned): void
    {
        unset($this->known[$key]);
        if (count($this->known) >= self::MAX_ENTRIES) {
            // Oldest first (insertion order); keys are URLs, never integers, so array_slice keeps them.
            $this->known = array_slice($this->known, 1 + count($this->known) - self::MAX_ENTRIES, null, true);
        }

        $this->known[$key] = $versioned;
    }
}
