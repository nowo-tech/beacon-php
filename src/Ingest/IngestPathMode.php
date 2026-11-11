<?php

declare(strict_types=1);

namespace Nowo\Beacon\Ingest;

/**
 * Which Beacon ingest paths the client calls (`ingest_paths` option).
 *
 * - `auto` (default): the versioned path first; on HTTP 404 / 405 the same request once more on the legacy path, and
 *   the answer is remembered per Beacon target ({@see IngestPathResolver}).
 * - `versioned`: always `/api/ingest/v1/{projectId}/…` (Beacon ≥ 1.147).
 * - `legacy`: always the unversioned `/api/{projectId}/…` paths (permanent aliases on every Beacon).
 *
 * Same values as beacon-js `ingestPaths` and BeaconBundle `nowo_beacon.ingest_paths`.
 */
enum IngestPathMode: string
{
    case Auto      = 'auto';
    case Versioned = 'versioned';
    case Legacy    = 'legacy';
}
