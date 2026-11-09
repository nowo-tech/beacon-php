<?php

declare(strict_types=1);

namespace Nowo\Beacon\Dsn;

use SensitiveParameter;

use function is_int;

/**
 * Parsed Beacon DSN (authority + project id).
 *
 * Format: `{scheme}://{public_key}:{secret}@{host}[:{port}]/{project_id}`
 *
 * `project_id` is a positive numeric legacy id or a canonical project UUID.
 *
 * The secret is sensitive: it only leaves the process in the `X-Beacon-Auth` header
 * ({@see getBeaconAuthHeader()}). {@see toString()} keeps it for compatibility with the Symfony bundle's
 * `BeaconDsn`; never log or print that value. `var_dump()` / `print_r()` show it masked.
 *
 * Not final so that framework adapters (BeaconBundle's `BeaconDsn`) can extend it and keep their own type name.
 */
class Dsn
{
    private readonly string $projectId;

    /**
     * @param int|string $projectId Positive numeric legacy id or canonical project UUID
     */
    public function __construct(
        private readonly string $scheme,
        private readonly string $publicKey,
        #[SensitiveParameter]
        private readonly string $secretKey,
        private readonly string $host,
        private readonly ?int $port,
        string|int $projectId,
    ) {
        $this->projectId = is_int($projectId) ? (string) $projectId : $projectId;
    }

    /**
     * URL scheme (`http` or `https`).
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Public key (DSN userinfo username).
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Secret key (DSN userinfo password). Required by Beacon ingest.
     */
    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    /**
     * Hostname from the DSN authority.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Explicit port when present in the DSN.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Beacon project id (positive numeric legacy string or canonical UUID).
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Base origin including scheme, host, and optional port (no path).
     */
    public function getOrigin(): string
    {
        $authority = $this->host;
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $this->scheme . '://' . $authority;
    }

    /**
     * Envelope ingest URL: `{origin}/api/{projectId}/envelope/` (never carries credentials).
     */
    public function getEnvelopeUrl(): string
    {
        return $this->getOrigin() . '/api/' . $this->projectId . '/envelope/';
    }

    /**
     * Wire value for the `X-Beacon-Auth` HTTP header (public + secret).
     */
    public function getBeaconAuthHeader(): string
    {
        return 'Beacon beacon_key=' . $this->publicKey . ', beacon_secret=' . $this->secretKey;
    }

    /**
     * DSN without the secret: `{scheme}://{public_key}@{host}[:{port}]/{project_id}`.
     */
    public function toPublicString(): string
    {
        return $this->scheme . '://' . $this->publicKey . '@' . $this->authority() . '/' . $this->projectId;
    }

    /**
     * Rebuild a canonical DSN string. It INCLUDES THE SECRET (BeaconBundle compatibility): do not log it.
     */
    public function toString(): string
    {
        return $this->scheme . '://' . $this->publicKey . ':' . $this->secretKey . '@' . $this->authority()
            . '/' . $this->projectId;
    }

    /**
     * Debug output with the secret masked.
     *
     * @return array<string, int|string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'scheme'    => $this->scheme,
            'publicKey' => $this->publicKey,
            'secretKey' => '[Filtered]',
            'host'      => $this->host,
            'port'      => $this->port,
            'projectId' => $this->projectId,
        ];
    }

    private function authority(): string
    {
        return $this->host . ($this->port !== null ? ':' . $this->port : '');
    }
}
