<?php

declare(strict_types=1);

namespace Nowo\Beacon\Scrubber;

use Stringable;

use function array_keys;
use function array_values;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function str_contains;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function urldecode;

/**
 * Redacts secret-like keys and credential-shaped values for safe Beacon extras.
 *
 * Identical rules to BeaconBundle's `Support\SensitiveValueRedactor` (envelope byte parity); the broader
 * payload pass lives in {@see PayloadScrubber}.
 */
final class SensitiveValueRedactor
{
    public const FILTERED = '[Filtered]';

    private const SENSITIVE_KEY = '/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|access[_-]?key|auth|credential|bearer|dsn|connection[_-]?string|salt|nonce)/i';

    /**
     * Credential-shaped substrings replaced inside free text (exception and log messages, context strings), the rest
     * of the text kept: PEM private keys, URL passwords, JWTs, bearer / basic tokens, provider keys (Stripe, AWS,
     * GitHub, Slack, Google, LLM APIs) and `password=…` / `"api_key": "…"` pairs.
     *
     * @var array<string, string> pattern => replacement
     */
    private const SECRET_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s'                                            => self::FILTERED,
        '#\b([a-z][a-z0-9+.-]*://[^\s:/@]+):[^\s@/]+@#i'                                                                            => '$1:' . self::FILTERED . '@',
        '/\beyJ[\w-]{8,}\.eyJ[\w-]{8,}\.[\w-]{8,}/'                                                                                 => self::FILTERED,
        '/\b(Bearer|Basic)\s+[\w.~+\/=-]{8,}/i'                                                                                     => '$1 ' . self::FILTERED,
        '/\b(?:sk|rk|pk)_(?:live|test)_\w{10,}/'                                                                                    => self::FILTERED,
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/'                                                                                           => self::FILTERED,
        '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/'                                                                                          => self::FILTERED,
        '/\bgithub_pat_\w{30,}/'                                                                                                    => self::FILTERED,
        '/\bxox[abposr]-[\w-]{10,}/'                                                                                                => self::FILTERED,
        '/\bAIza[\w-]{35}\b/'                                                                                                       => self::FILTERED,
        '/\bsk-(?:ant-)?[\w-]{20,}/'                                                                                                => self::FILTERED,
        '/\b((?:passw(?:or)?d|pwd|secret|token|api[_-]?key|access[_-]?key|client[_-]?secret)["\']?\s*[:=]\s*["\']?)[^\s"\'&,;}]+/i' => '$1' . self::FILTERED,
    ];

    /**
     * Extra query parameter names that carry one-time credentials in URLs only: Symfony login links
     * (`hash`), signed URLs (`_hash`, `signature`, `X-Amz-Signature`, `sig`) and OAuth callbacks (`code`).
     * Not part of {@see SENSITIVE_KEY}: a `code` or `hash` key in Monolog context or console input is
     * usually not a secret.
     */
    private const SENSITIVE_URL_PARAMETER = '/^(?:_?hash|sig|code)$|signature|token/i';

    /**
     * Replace credential-shaped substrings in free text (an exception or log message), keeping the rest.
     */
    public static function redactMessage(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        return preg_replace(array_keys(self::SECRET_PATTERNS), array_values(self::SECRET_PATTERNS), $message) ?? $message;
    }

    /**
     * Whether `$key` looks sensitive (password, token, DSN, …).
     */
    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1;
    }

    /**
     * Redact a map of named values (console args/options, Monolog context, …).
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function redactMap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $name = (string) $key;
            if (self::isSensitiveKey($name)) {
                $out[$name] = self::FILTERED;

                continue;
            }
            $out[$name] = self::redactValue($value);
        }

        return $out;
    }

    /**
     * Redact a raw query string pair by pair (`token=abc&page=2` → `token=[Filtered]&page=2`).
     *
     * URL credential parameters (`hash`, `_hash`, `signature`, `sig`, `code`, `token`) are filtered; every
     * other decoded key/value goes through the same rules as {@see redactMap()}; pairs that do not
     * need redaction are kept byte for byte (order, encoding and array syntax preserved).
     */
    public static function redactQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return $queryString;
        }

        $pairs = explode('&', $queryString);
        foreach ($pairs as $index => $pair) {
            $separator = strpos($pair, '=');
            if ($separator === false) {
                continue;
            }

            $key   = urldecode(substr($pair, 0, $separator));
            $value = urldecode(substr($pair, $separator + 1));

            if ($value !== '' && self::isSensitiveUrlParameter($key)) {
                $pairs[$index] = substr($pair, 0, $separator + 1) . self::FILTERED;

                continue;
            }

            $redacted = self::redactMap([$key => $value])[$key];
            if ($redacted !== $value) {
                $pairs[$index] = substr($pair, 0, $separator + 1) . (is_string($redacted) && $redacted !== self::FILTERED ? rawurlencode($redacted) : self::FILTERED);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Whether a query parameter name (array syntax `name[...]` reduced to `name`) carries a URL credential.
     */
    private static function isSensitiveUrlParameter(string $key): bool
    {
        return preg_match(self::SENSITIVE_URL_PARAMETER, (string) preg_replace('/\[.*$/s', '', trim($key))) === 1;
    }

    /**
     * Redact credentials in a URL: userinfo (`user:pass@`) and secret-like query parameters.
     */
    public static function redactUrl(string $url): string
    {
        $url = self::redactString($url);

        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragment      = '';
        $fragmentStart = strpos($url, '#', $queryStart);
        if ($fragmentStart !== false) {
            $fragment = substr($url, $fragmentStart);
            $url      = substr($url, 0, $fragmentStart);
        }

        return substr($url, 0, $queryStart + 1) . self::redactQueryString(substr($url, $queryStart + 1)) . $fragment;
    }

    /**
     * Redact a single value (scalars kept; nested arrays walked; objects summarized).
     */
    public static function redactValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        if (is_array($value)) {
            $named = [];
            foreach ($value as $key => $item) {
                $named[(string) $key] = $item;
            }

            return self::redactMap($named);
        }

        if ($value instanceof Stringable) {
            return self::redactString((string) $value);
        }

        if (is_object($value)) {
            return $value::class;
        }

        return self::FILTERED;
    }

    /**
     * Hide credential-shaped strings (URI userinfo, long tokens).
     */
    private static function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // scheme://user:pass@host → scheme://[Filtered]@host
        if (preg_match('#^([a-z][a-z0-9+.-]*://)([^/@]+)@#i', $value) === 1) {
            return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)([^/@]+)@#i', '$1' . self::FILTERED . '@', $value);
        }

        $lower = strtolower($value);
        if (str_contains($lower, '-----begin') && str_contains($lower, 'private key')) {
            return self::FILTERED;
        }

        return self::redactMessage($value);
    }
}
