<?php

declare(strict_types=1);

namespace Nowo\Beacon\Scrubber;

use Nowo\Beacon\Envelope\Envelope;
use Stringable;

use function array_keys;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function max;
use function ord;
use function preg_last_error;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function urldecode;

use const PREG_NO_ERROR;

/**
 * Redacts secret-like keys and credential-shaped values for safe Beacon extras.
 *
 * Same rules as BeaconBundle's `Support\SensitiveValueRedactor` (the stricter rule kept where the two differed); the
 * broader payload pass lives in {@see PayloadScrubber}.
 *
 * Fail closed: when PCRE fails (backtrack / JIT stack / match limit), the text is replaced by
 * {@see REDACTION_FAILED} and a key counts as sensitive; the original value is never handed back. Every quantifier
 * that could backtrack is possessive or bounded, and free text is cut at {@see MAX_MESSAGE_LENGTH} bytes first.
 *
 * Invalid UTF-8: the patterns are byte-oriented on purpose (no `u` modifier), so invalid UTF-8 never makes PCRE fail
 * and secrets next to invalid bytes are still redacted. The cut at {@see MAX_MESSAGE_LENGTH} does not split a valid
 * multibyte character. The JSON encoder of {@see Envelope} substitutes invalid sequences.
 */
final class SensitiveValueRedactor
{
    public const FILTERED = '[Filtered]';

    /** Returned instead of the original text when a PCRE error makes redaction fail (fail closed). */
    public const REDACTION_FAILED = '[Filtered: redaction failed]';

    /** Longest free text redacted, in bytes; the rest is dropped (never returned unredacted). */
    public const MAX_MESSAGE_LENGTH = 65536;

    /** Appended to a message cut at {@see MAX_MESSAGE_LENGTH}. */
    public const TRUNCATED = ' [truncated]';

    /**
     * Sensitive key names. Long words match anywhere (`db_password`, `X-Api-Key`, `session_id`); short ones (`pass`,
     * `pwd`, `pin`, `otp`, `cvv`, `card`) only as a whole token, delimited by non-alphanumerics or a camelCase hump
     * (`db_pass`, `card_number`, `cardNumber`, `userPin`), so `passenger`, `shipping`, `isPinned` or `PASSENGER` are kept.
     */
    private const SENSITIVE_KEY = '/(?:password|passwd|passphrase|secret|token|api[_-]?key|private|access[_-]?key|auth|credential|bearer|dsn|connection[_-]?string|salt|nonce|cookie|session|signature|iban)|(?:(?<![A-Za-z0-9])|(?-i:(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])))(?:pass|pwd|pin|otp|cvv|cvc|card)(?:(?![A-Za-z0-9])|(?-i:(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])))/i';

    /** Secret-looking names in free text (see {@see SECRET_PATTERNS}). */
    private const SECRET_NAME = '(?:(?:passw(?:or)?d|passphrase|client[_-]?secret|secret|token|api[_-]?key|access[_-]?key|private[_-]?key)[A-Za-z0-9_-]{0,64}+|(?:pass|pwd|pin|otp|cvv|cvc))';

    /** Start of a secret name: a non-alphanumeric (or the start) before it, or a camelCase hump (`dbPassword`, `apiToken`, `myAPIToken`). */
    private const NAME_START = '(?:(?<![A-Za-z0-9])|(?-i:(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])))';

    /** A value that is exactly `[Filtered]` (an earlier rule already filtered all of it) is left alone. */
    private const NOT_FILTERED_ALREADY = '(?!\[Filtered\](?:[\s"\'&,}]|\z))';

    /** End marker of a PEM private key. */
    private const PEM_END = '-----END [A-Z ]{0,32}?PRIVATE KEY-----';

    /**
     * Credential-shaped substrings replaced inside free text (exception and log messages, context strings), the rest
     * of the text kept: PEM private keys, URL passwords, JWTs, bearer / basic tokens, provider keys (Stripe, AWS,
     * GitHub, Slack, Google, LLM APIs), Beacon worker keys and `password=…` / `"api_key": "…"` / `'pass' => '…'` pairs.
     *
     * @var array<string, string> pattern => replacement
     */
    private const SECRET_PATTERNS = [
        // A PEM private key up to its own END marker, or to the end of the text when there is none.
        '/-----BEGIN [A-Z ]{0,32}?PRIVATE KEY-----(?:[^-]++|(?!' . self::PEM_END . ')-)*+(?:' . self::PEM_END . ')?/' => self::FILTERED,
        // scheme://user:password@host — the password may contain `/` or `@` (up to the last `@` of the word); a port
        // followed by a path (`host:8080/…`) is not a password. The user part may be empty (`redis://:pw@host`).
        '#([a-z][a-z0-9+.-]{0,31}+://[^\s:/@]*+):(?!\d{1,5}/)(?:[^\s@]++@)++#i' => '$1:' . self::FILTERED . '@',
        '/\beyJ[\w-]{8,}+\.eyJ[\w-]{8,}+\.[\w-]{8,}+/'                          => self::FILTERED,
        '/\b(Bearer|Basic)\s++[\w.~+\/=-]{8,}+/i'                               => '$1 ' . self::FILTERED,
        '/\b(?:sk|rk|pk)_(?:live|test)_\w{10,}+/'                               => self::FILTERED,
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/'                                       => self::FILTERED,
        '/\bgh[pousr]_[A-Za-z0-9]{30,}+\b/'                                     => self::FILTERED,
        '/\bgithub_pat_\w{30,}+/'                                               => self::FILTERED,
        '/\bxox[abposr]-[\w-]{10,}+/'                                           => self::FILTERED,
        '/\bAIza[\w-]{35}\b/'                                                   => self::FILTERED,
        '/\bsk-(?:ant-)?[\w-]{20,}+/'                                           => self::FILTERED,
        // Beacon worker keys (remote Messenger commands).
        '/(?<![A-Za-z0-9])bwk_[A-Za-z0-9_-]{20,}+/' => self::FILTERED,
        // name=value / name: value / 'name' => 'value' / "name": "value with spaces". The name may be part of a
        // longer identifier (`DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `beacon_secret`, `smtp_pass`); short names
        // (`pass`, `pwd`, `pin`, `otp`, `cvv`) must end there. Quoted values are filtered whole (quotes kept); a
        // quoted value whose closing quote is missing is filtered to the end of the text. Unquoted values run to
        // whitespace, a quote, `&`, `,` or `}` (a `]` does not end them, so `[token=abc]` loses its final `]`); a
        // `[Filtered]` left inside them by an earlier rule does not end
        // them, so the rest of the value is filtered too.
        '/' . self::NAME_START . '(' . self::SECRET_NAME . '["\']?\s*+(?>=>|[:=])\s*+")(?:[^"\\\\]++|\\\\.?)*+("|\z)/i'                                                 => '$1' . self::FILTERED . '$2',
        '/' . self::NAME_START . '(' . self::SECRET_NAME . '["\']?\s*+(?>=>|[:=])\s*+\')(?:[^\'\\\\]++|\\\\.?)*+(\'|\z)/i'                                              => '$1' . self::FILTERED . '$2',
        '/' . self::NAME_START . '(' . self::SECRET_NAME . '["\']?\s*+(?>=>|[:=])\s*+["\']?+)' . self::NOT_FILTERED_ALREADY . '(?:\[Filtered\]|[^\s"\'&,}\[]++|\[)++/i' => '$1' . self::FILTERED,
    ];

    /** Payment card numbers: Amex (15 digits) and Visa / Mastercard / Discover prefixes (15–19 digits); Luhn checked. */
    private const CARD_PATTERN = '/(?<![\d-])(?:3[47](?:[ -]?\d){13}|(?:4|5[1-5]|2[2-7]|6)(?:[ -]?\d){14,18})(?![\d-])/';

    /** `scheme://userinfo@` at the start of a string; the password may contain `@` (up to the last `@` before `/`). */
    private const URI_USERINFO = '#^([a-z][a-z0-9+.-]*+://)(?:[^/@\s]*+@)++#i';

    /** Longest trailing run of non-whitespace bytes dropped at a cut (see {@see dropStraddlingRun()}). */
    private const STRADDLE_LIMIT = 4096;

    /**
     * Extra query parameter names that carry one-time credentials in URLs only: Symfony login links
     * (`hash`), signed URLs (`_hash`, `signature`, `X-Amz-Signature`, `sig`), OAuth callbacks (`code`), API keys
     * (`key`), reset links (`reset`) and session ids (`sid`). Not part of {@see SENSITIVE_KEY}: a `code`, `key` or
     * `hash` key in Monolog context or console input is usually not a secret.
     */
    private const SENSITIVE_URL_PARAMETER = '/^(?:_?hash|sig|code|key|reset|sid)$|signature|token/i';

    /**
     * Replace credential-shaped substrings in free text (an exception or log message), keeping the rest.
     *
     * Text longer than {@see MAX_MESSAGE_LENGTH} bytes is cut first ({@see TRUNCATED} appended); on a PCRE error the
     * whole text becomes {@see REDACTION_FAILED}.
     */
    public static function redactMessage(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        $redacted = preg_replace(array_keys(self::SECRET_PATTERNS), array_values(self::SECRET_PATTERNS), self::truncate($message));
        if ($redacted === null || preg_last_error() !== PREG_NO_ERROR) {
            return self::REDACTION_FAILED;
        }

        $masked = preg_replace_callback(
            self::CARD_PATTERN,
            static fn (array $match): string => self::passesLuhn($match[0]) ? self::FILTERED : $match[0],
            $redacted,
        );

        return $masked === null || preg_last_error() !== PREG_NO_ERROR ? self::REDACTION_FAILED : $masked;
    }

    /**
     * Whether `$key` looks sensitive (password, token, DSN, …). A PCRE error counts as sensitive (fail closed).
     */
    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) !== 0;
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
     * URL credential parameters (`hash`, `_hash`, `signature`, `sig`, `code`, `key`, `reset`, `sid`, `token`) are
     * filtered; every other decoded key/value goes through the same rules as {@see redactMap()}; pairs that do not
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
     * Whether a query parameter name (array syntax `name[...]` reduced to `name`) carries a URL credential. A PCRE
     * error counts as a credential (fail closed).
     */
    private static function isSensitiveUrlParameter(string $key): bool
    {
        $name = preg_replace('/\[.*+$/s', '', trim($key));

        return $name === null || preg_match(self::SENSITIVE_URL_PARAMETER, $name) !== 0;
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
     * Hide credential-shaped strings: a PEM private key hides the whole value; URI userinfo
     * (`scheme://user:pass@host` → `scheme://[Filtered]@host`), then the free-text rules of {@see redactMessage()}.
     */
    private static function redactString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $lower = strtolower($value);
        if (str_contains($lower, '-----begin') && str_contains($lower, 'private key')) {
            return self::FILTERED;
        }

        $value = preg_replace(self::URI_USERINFO, '$1' . self::FILTERED . '@', $value);
        if ($value === null || preg_last_error() !== PREG_NO_ERROR) {
            return self::REDACTION_FAILED;
        }

        return self::redactMessage($value);
    }

    /**
     * Cut free text at {@see MAX_MESSAGE_LENGTH} bytes, dropping the token that straddles the cut
     * ({@see dropStraddlingRun()}): a secret cut in half no longer matches its pattern, so its head must not be kept.
     */
    private static function truncate(string $message): string
    {
        if (strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        return self::dropStraddlingRun(substr($message, 0, self::MAX_MESSAGE_LENGTH), $message[self::MAX_MESSAGE_LENGTH]) . self::TRUNCATED;
    }

    /**
     * Prepare the head of a cut text for redaction. When the cut falls inside a run of non-whitespace bytes (`$next`,
     * the first byte cut off, is not whitespace), that run is dropped from the head: a token, URL, JWT or key cut in
     * half no longer matches its pattern. At most {@see STRADDLE_LIMIT} bytes are scanned; a run longer than that is
     * dropped over that length only. The end never splits a valid UTF-8 character (invalid UTF-8 is cut as is).
     */
    private static function dropStraddlingRun(string $head, string $next): string
    {
        if ($next === '' || self::isWhitespace($next[0])) {
            return $head;
        }

        $length = strlen($head);
        $stop   = max(0, $length - self::STRADDLE_LIMIT);
        $end    = $stop;
        for ($i = $length - 1; $i >= $stop; --$i) {
            if (self::isWhitespace($head[$i])) {
                $end = $i + 1;

                break;
            }
        }

        for ($step = 0; $step < 3 && $end > 0 && $end < $length && (ord($head[$end]) & 0xC0) === 0x80; ++$step) {
            --$end;
        }

        return substr($head, 0, $end);
    }

    private static function isWhitespace(string $byte): bool
    {
        return in_array($byte, [' ', "\n", "\t", "\r", "\f", "\v"], true);
    }

    private static function passesLuhn(string $match): bool
    {
        $sum    = 0;
        $double = false;
        for ($i = strlen($match) - 1; $i >= 0; --$i) {
            $char = $match[$i];
            if ($char === ' ' || $char === '-') {
                continue;
            }
            $digit = (int) $char;
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
