<?php

declare(strict_types=1);

namespace Nowo\Beacon\Scrubber;

use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function strlen;
use function strtolower;

/**
 * Default payload scrubber (option `scrub`, on by default), run on event and transaction payloads BEFORE
 * `before_send`, so the hook never sees what it removes.
 *
 * It applies the beacon-js contract §5 rules on top of the redaction EnvelopeBuilder already does (messages,
 * exception values, request URL / query string / referer):
 *
 * - keys that look sensitive ({@see SensitiveValueRedactor::isSensitiveKey()} plus session / cookie / csrf / card /
 *   iban / ssn / cvv names) become `[Filtered]` in `extra`, `contexts`, `tags`, breadcrumb `data` and `request.data`;
 * - string values go through {@see SensitiveValueRedactor::redactValue()} (URL userinfo, JWTs, bearer tokens,
 *   provider keys, `password=…` pairs) and Luhn-valid card numbers are masked in text and in integers;
 * - request headers that carry credentials (`Authorization`, `Cookie`, `X-Beacon-Auth`, …) and `request.cookies`
 *   become `[Filtered]`;
 * - structures nested deeper than {@see MAX_DEPTH} are replaced by `[Filtered]` instead of being sent unchecked.
 *
 * Objects are summarised (Stringable → string, other objects → class name). `user` is left as the application set it
 * ({@see IpStripper} removes its IP).
 */
final class PayloadScrubber
{
    public const MAX_DEPTH = 16;

    /** Header names always redacted (Beacon's EventPayloadScrubber list plus the CSRF headers). */
    public const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-beacon-auth',
        'x-api-key',
        'x-auth-token',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    /** Additional sensitive key names (beacon-js `SENSITIVE_KEY` categories not covered by the bundle rules). */
    private const EXTRA_SENSITIVE_KEY = '/(?:session|cookie|csrf|xsrf|credit[_-]?card|card[_-]?(?:number|num|no)(?![a-z])|(?<![a-z])(?:cvv|cvc|iban|ssn|pass|pwd)(?![a-z]))/i';

    private const CARD_PATTERN = '/(?<!\d)\d(?:[ -]?\d){12,18}(?!\d)/';

    private const URL_KEYS = ['url', 'from', 'to'];

    /**
     * Scrub an event or transaction payload (returns a new array).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function scrub(array $payload): array
    {
        if (isset($payload['message']) && is_string($payload['message'])) {
            $payload['message'] = self::scrubText($payload['message']);
        }

        $exception = $payload['exception'] ?? null;
        if (is_array($exception) && isset($exception['values']) && is_array($exception['values'])) {
            foreach ($exception['values'] as $index => $value) {
                if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
                    $value['value']              = self::scrubText($value['value']);
                    $exception['values'][$index] = $value;
                }
            }
            $payload['exception'] = $exception;
        }

        foreach (['extra', 'contexts', 'tags'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = $this->scrubMap($payload[$key], 0);
            }
        }

        if (isset($payload['request']) && is_array($payload['request'])) {
            $payload['request'] = $this->scrubRequest($payload['request']);
        }

        $breadcrumbs = $payload['breadcrumbs'] ?? null;
        if (is_array($breadcrumbs) && isset($breadcrumbs['values']) && is_array($breadcrumbs['values'])) {
            foreach ($breadcrumbs['values'] as $index => $crumb) {
                if (is_array($crumb)) {
                    $breadcrumbs['values'][$index] = $this->scrubBreadcrumb($crumb);
                }
            }
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        return $payload;
    }

    /**
     * True for a key the scrubber replaces with `[Filtered]`.
     */
    public static function isSensitiveKey(string $key): bool
    {
        return SensitiveValueRedactor::isSensitiveKey($key) || preg_match(self::EXTRA_SENSITIVE_KEY, $key) === 1;
    }

    /**
     * Credential patterns of {@see SensitiveValueRedactor::redactMessage()} plus Luhn-valid card numbers.
     */
    public static function scrubText(string $text): string
    {
        return self::maskCards(SensitiveValueRedactor::redactMessage($text));
    }

    /**
     * Replace 13–19 digit runs (optionally grouped by spaces or dashes) that pass the Luhn check.
     */
    public static function maskCards(string $text): string
    {
        if (strlen($text) < 13) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::CARD_PATTERN,
            static fn (array $match): string => self::luhn($match[0]) ? SensitiveValueRedactor::FILTERED : $match[0],
            $text,
        );
    }

    /**
     * @param array<array-key, mixed> $map
     *
     * @return array<array-key, mixed>
     */
    private function scrubMap(array $map, int $depth): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[$key] = is_string($key) && self::isSensitiveKey($key)
                ? SensitiveValueRedactor::FILTERED
                : $this->scrubValue($value, $depth + 1);
        }

        return $out;
    }

    private function scrubValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH ? SensitiveValueRedactor::FILTERED : $this->scrubMap($value, $depth);
        }

        if (is_int($value)) {
            $digits = (string) $value;

            return preg_match('/^\d{13,19}$/', $digits) === 1 && self::luhn($digits) ? SensitiveValueRedactor::FILTERED : $value;
        }

        if (is_string($value) || is_object($value)) {
            $redacted = SensitiveValueRedactor::redactValue($value);

            return is_string($redacted) ? self::maskCards($redacted) : $redacted;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $request
     *
     * @return array<array-key, mixed>
     */
    private function scrubRequest(array $request): array
    {
        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = SensitiveValueRedactor::redactUrl($request['url']);
        }
        if (isset($request['query_string']) && is_string($request['query_string'])) {
            $request['query_string'] = SensitiveValueRedactor::redactQueryString($request['query_string']);
        }
        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach ($request['headers'] as $name => $value) {
                $lower                     = strtolower((string) $name);
                $request['headers'][$name] = in_array($lower, self::SENSITIVE_HEADERS, true) || self::isSensitiveKey($lower)
                    ? SensitiveValueRedactor::FILTERED
                    : $this->scrubValue($value, 1);
            }
        }
        if (isset($request['cookies'])) {
            $request['cookies'] = SensitiveValueRedactor::FILTERED;
        }
        if (isset($request['data'])) {
            $request['data'] = $this->scrubValue($request['data'], 1);
        }

        return $request;
    }

    /**
     * @param array<array-key, mixed> $crumb
     *
     * @return array<array-key, mixed>
     */
    private function scrubBreadcrumb(array $crumb): array
    {
        if (isset($crumb['message']) && is_string($crumb['message'])) {
            $crumb['message'] = self::scrubText($crumb['message']);
        }
        if (isset($crumb['data']) && is_array($crumb['data'])) {
            $data = $this->scrubMap($crumb['data'], 1);
            foreach (self::URL_KEYS as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $data[$key] = SensitiveValueRedactor::redactUrl($data[$key]);
                }
            }
            $crumb['data'] = $data;
        }

        return $crumb;
    }

    private static function luhn(string $text): bool
    {
        $digits = (string) preg_replace('/\D/', '', $text);
        $sum    = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; ++$i) {
            $digit = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
