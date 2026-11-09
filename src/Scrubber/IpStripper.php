<?php

declare(strict_types=1);

namespace Nowo\Beacon\Scrubber;

use function array_is_list;
use function array_values;
use function in_array;
use function is_array;
use function preg_match;
use function preg_replace;
use function preg_split;
use function strtolower;

/**
 * Removes the end user's IP address from an event or transaction payload.
 *
 * Runs as the very LAST step, after `before_send`, whenever `send.client` is false (the default, as in BeaconBundle);
 * beacon-js does the same with no opt-out. Removed:
 *
 * - `user.ip_address`;
 * - `client.ip` in `request`, `contexts.request` and `extra.http`;
 * - `REMOTE_ADDR` (any case) in `request.env`;
 * - request headers that can carry a client address (`X-Forwarded-For`, `X-Real-IP`, `Forwarded`,
 *   `CF-Connecting-IP`, `True-Client-IP`, `X-Client-IP` and any name with the word addr / address / ip / client /
 *   forwarded / via / socket), in `request.headers` and `contexts.request.headers`;
 * - every `{{auto}}` value (Sentry's "fill in the IP" placeholder), up to 20 levels deep.
 *
 * An IP literal the application itself writes into `extra`, tags or breadcrumbs is not detected.
 */
final class IpStripper
{
    public const IP_HEADERS = [
        'x-forwarded-for',
        'x-real-ip',
        'forwarded',
        'cf-connecting-ip',
        'true-client-ip',
        'x-client-ip',
    ];

    private const IP_HEADER_PATTERN = '/forwarded|client-?ip|real-?ip|(?:^|-)ip(?:v[46])?$|^cf-|^fastly-|^x-vercel-|external-address|true-client/i';

    private const JOINED_PATTERN = '/client-?ip|real-?ip|remote-?addr|socket-?ip|ip-?addr/i';

    private const ADDRESS_WORDS = ['addr', 'address', 'ip', 'ipv4', 'ipv6', 'client', 'forwarded', 'via', 'socket'];

    private const AUTO = '{{auto}}';

    private const MAX_DEPTH = 20;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function strip(array $payload): array
    {
        if (isset($payload['user']) && is_array($payload['user'])) {
            unset($payload['user']['ip_address']);
        }

        if (isset($payload['request']) && is_array($payload['request'])) {
            $payload['request'] = $this->stripRequest($payload['request']);
        }

        $contexts = $payload['contexts'] ?? null;
        if (is_array($contexts) && isset($contexts['request']) && is_array($contexts['request'])) {
            $contexts['request'] = $this->stripRequest($contexts['request']);
            $payload['contexts'] = $contexts;
        }

        $extra = $payload['extra'] ?? null;
        if (is_array($extra) && isset($extra['http']) && is_array($extra['http']) && isset($extra['http']['client']) && is_array($extra['http']['client'])) {
            unset($extra['http']['client']['ip']);
            $payload['extra'] = $extra;
        }

        /** @var array<string, mixed> $payload */
        $payload = $this->dropAuto($payload, self::MAX_DEPTH);

        return $payload;
    }

    /**
     * True for a request header name that can carry a client address.
     */
    public static function isClientAddressHeader(string $name): bool
    {
        if (in_array(strtolower($name), self::IP_HEADERS, true)) {
            return true;
        }

        $spaced = (string) preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], ['$1 $2', '$1 $2'], $name);
        $words  = preg_split('/[-_.\s]+/', strtolower($spaced)) ?: [];
        foreach ($words as $word) {
            if (in_array($word, self::ADDRESS_WORDS, true)) {
                return true;
            }
        }

        return preg_match(self::JOINED_PATTERN, $name) === 1 || preg_match(self::IP_HEADER_PATTERN, $name) === 1;
    }

    /**
     * @param array<array-key, mixed> $request
     *
     * @return array<array-key, mixed>
     */
    private function stripRequest(array $request): array
    {
        if (isset($request['client']) && is_array($request['client'])) {
            unset($request['client']['ip']);
        }

        if (isset($request['env']) && is_array($request['env'])) {
            foreach ($request['env'] as $name => $value) {
                if (strtolower((string) $name) === 'remote_addr') {
                    unset($request['env'][$name]);
                }
            }
        }

        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach ($request['headers'] as $name => $value) {
                if (self::isClientAddressHeader((string) $name)) {
                    unset($request['headers'][$name]);
                }
            }
        }

        return $request;
    }

    private function dropAuto(mixed $value, int $depth): mixed
    {
        if (!is_array($value) || $depth <= 0) {
            return $value;
        }

        $isList = array_is_list($value);
        $out    = [];
        foreach ($value as $key => $item) {
            if ($item === self::AUTO) {
                continue;
            }
            $out[$key] = $this->dropAuto($item, $depth - 1);
        }

        return $isList ? array_values($out) : $out;
    }
}
