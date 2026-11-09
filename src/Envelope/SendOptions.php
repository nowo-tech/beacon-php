<?php

declare(strict_types=1);

namespace Nowo\Beacon\Envelope;

use Nowo\Beacon\Exception\ConfigurationException;

use function array_key_exists;
use function implode;
use function in_array;
use function is_bool;
use function sprintf;

/**
 * Per-category switches for outbound event context (same keys and defaults as BeaconBundle's `nowo_beacon.send`).
 *
 * Defaults favour diagnostics; {@see $user}, {@see $client} (client IP + User-Agent) and {@see $stackVars} stay
 * opt-in (PII).
 */
final readonly class SendOptions
{
    public const KEYS = [
        'environment',
        'release',
        'server_name',
        'stacktrace',
        'request',
        'user',
        'runtime',
        'framework',
        'os',
        'client',
        'stack_vars',
    ];

    public function __construct(
        public bool $environment = true,
        public bool $release = true,
        public bool $serverName = true,
        public bool $stacktrace = true,
        public bool $request = true,
        public bool $user = false,
        public bool $runtime = true,
        public bool $framework = true,
        public bool $os = true,
        public bool $client = false,
        public bool $stackVars = false,
    ) {
    }

    /**
     * Build SendOptions from a `send` array (unknown keys and non-boolean values are rejected).
     *
     * @param array<array-key, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        foreach ($config as $key => $value) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ConfigurationException(sprintf('Unknown "send" option "%s". Known options: %s.', $key, implode(', ', self::KEYS)));
            }
            if (!is_bool($value)) {
                throw new ConfigurationException(sprintf('The "send.%s" option must be a boolean.', $key));
            }
        }

        $get = static fn (string $key, bool $default): bool => array_key_exists($key, $config) ? (bool) $config[$key] : $default;

        return new self(
            environment: $get('environment', true),
            release: $get('release', true),
            serverName: $get('server_name', true),
            stacktrace: $get('stacktrace', true),
            request: $get('request', true),
            user: $get('user', false),
            runtime: $get('runtime', true),
            framework: $get('framework', true),
            os: $get('os', true),
            client: $get('client', false),
            stackVars: $get('stack_vars', false),
        );
    }
}
