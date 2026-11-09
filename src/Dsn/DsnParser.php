<?php

declare(strict_types=1);

namespace Nowo\Beacon\Dsn;

use SensitiveParameter;

use function ctype_digit;
use function in_array;
use function is_string;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Parses a Beacon DSN into {@see Dsn}.
 *
 * Rules match BeaconBundle's `BeaconDsnParser` byte for byte (same accepted inputs, same messages), plus one
 * hardening rule: control characters (CR, LF, NUL, …) are refused, because the public key and the secret end up in an
 * HTTP header. Exception messages never contain the DSN or the secret.
 */
final class DsnParser
{
    /**
     * Canonical UUID (with hyphens), including UUIDv7 used by Beacon.
     */
    private const PROJECT_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const INVALID_PATH = 'DSN path must be a positive numeric project id or a UUID (e.g. https://KEY:SECRET@host:9444/1 or .../019fea2d-507b-7890-8b33-ca488db6f696).';

    /**
     * Parse and validate a Beacon DSN string.
     *
     * @throws InvalidDsnException
     */
    public function parse(#[SensitiveParameter] string $dsn): Dsn
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            throw new InvalidDsnException('DSN must not be empty.');
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            throw new InvalidDsnException('Invalid DSN. Expected format: https://PUBLIC_KEY:SECRET_KEY@host:port/PROJECT_ID');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidDsnException(sprintf('Unsupported DSN scheme "%s". Use http or https.', $scheme));
        }

        $publicKey = rawurldecode($parts['user']);
        if ($publicKey === '') {
            throw new InvalidDsnException('DSN public key (userinfo username) must not be empty.');
        }

        if (!isset($parts['pass']) || !is_string($parts['pass']) || $parts['pass'] === '') {
            throw new InvalidDsnException('DSN secret key is required. Use https://PUBLIC_KEY:SECRET_KEY@host/PROJECT_ID (Beacon rejects public-key-only auth when the API key has a secret).');
        }

        $secretKey = rawurldecode($parts['pass']);
        if (preg_match('/[\x00-\x1f\x7f]/', $publicKey . $secretKey) === 1) {
            throw new InvalidDsnException('DSN keys must not contain control characters.');
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidDsnException(sprintf('Invalid DSN port "%d".', $port));
        }

        $projectId = $this->parseProjectId(trim($parts['path'], '/'));

        return new Dsn($scheme, $publicKey, $secretKey, $host, $port, $projectId);
    }

    /**
     * @throws InvalidDsnException
     */
    private function parseProjectId(string $path): string
    {
        if (ctype_digit($path)) {
            if ((int) $path < 1) {
                throw new InvalidDsnException('DSN project id must be a positive integer.');
            }

            return $path;
        }

        if (preg_match(self::PROJECT_UUID_PATTERN, $path) === 1) {
            return strtolower($path);
        }

        throw new InvalidDsnException(self::INVALID_PATH);
    }
}
