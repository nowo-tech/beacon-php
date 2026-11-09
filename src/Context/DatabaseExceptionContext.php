<?php

declare(strict_types=1);

namespace Nowo\Beacon\Context;

use PDOException;
use Throwable;

use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function mb_substr;
use function method_exists;
use function preg_match;
use function preg_replace;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Best-effort SQL/SQLSTATE facts from a throwable chain for Envelope {@code contexts.db}.
 *
 * Does not require doctrine/dbal; uses {@see PDOException} and duck-typed DBAL APIs.
 */
final class DatabaseExceptionContext
{
    public const MAX_SQL_LENGTH = 8192;

    /**
     * @return array<string, mixed>|null
     */
    public static function fromThrowable(Throwable $throwable): ?array
    {
        $merged  = [];
        $current = $throwable;
        while ($current instanceof Throwable) {
            $merged  = self::merge($merged, self::fromOne($current));
            $current = $current->getPrevious();
        }

        if ($merged === []) {
            return null;
        }

        $out = ['type' => 'sql'];
        foreach (['sqlstate', 'code', 'driver', 'sql', 'sql_mode'] as $key) {
            if (isset($merged[$key]) && is_string($merged[$key]) && $merged[$key] !== '') {
                $out[$key] = $merged[$key];
            }
        }

        if (!isset($out['sqlstate']) && !isset($out['code']) && !isset($out['sql'])) {
            return null;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromOne(Throwable $throwable): array
    {
        $parsed = self::parseMessage($throwable->getMessage());

        if ($throwable instanceof PDOException) {
            $info = $throwable->errorInfo;
            if (is_array($info)) {
                if (isset($info[0]) && is_string($info[0]) && $info[0] !== '' && $info[0] !== '00000') {
                    $parsed['sqlstate'] = strtoupper($info[0]);
                }
                if (isset($info[1]) && (is_int($info[1]) || is_string($info[1]))) {
                    $code = trim((string) $info[1]);
                    if ($code !== '') {
                        $parsed['code'] = $code;
                    }
                }
            }
            $parsed['driver'] ??= 'pdo';
        }

        if (method_exists($throwable, 'getSQLState')) {
            $state = $throwable->getSQLState();
            if (is_string($state) && $state !== '') {
                $parsed['sqlstate'] = strtoupper($state);
            }
        }

        if (method_exists($throwable, 'getQuery')) {
            $query = $throwable->getQuery();
            if (is_string($query) && $query !== '') {
                $parsed['sql'] = self::scrubSql($query);
            } elseif (is_object($query) && method_exists($query, 'getSQL')) {
                $sql = $query->getSQL();
                if (is_string($sql) && $sql !== '') {
                    $parsed['sql'] = self::scrubSql($sql);
                }
            }
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseMessage(string $message): array
    {
        $out = [];
        if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/i', $message, $m) === 1) {
            $out['sqlstate'] = strtoupper($m[1]);
        }
        if (preg_match('/SQLSTATE\[[A-Z0-9]{5}\]\s*:[^:]*:\s*(\d+)/i', $message, $m) === 1) {
            $out['code'] = $m[1];
        } elseif (preg_match('/\((\d{3,5}),\s*[\'"]([^\'"]+)[\'"]\)/', $message, $m) === 1) {
            $out['code'] = $m[1];
        }
        if (preg_match('/sql_mode\s*=\s*[\'"]?([^\s,;\'")]+)/i', $message, $m) === 1) {
            $out['sql_mode'] = $m[1];
        }
        if (preg_match('/Connection:\s*([a-z0-9_]+)/i', $message, $m) === 1) {
            $out['driver'] = strtolower($m[1]);
        }
        if (preg_match('/\(SQL:\s*(.+)\)\s*$/s', $message, $m) === 1 || preg_match('/,\s*SQL:\s*(.+)\)\s*$/s', $message, $m) === 1) {
            $out['sql'] = self::scrubSql(trim($m[1]));
        }

        return $out;
    }

    public static function scrubSql(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($sql));
        $sql       = is_string($collapsed) ? $collapsed : trim($sql);
        $scrubbed  = preg_replace("/'([^'\\\\]|\\\\.)*'/", "'?'", $sql);
        $sql       = is_string($scrubbed) ? $scrubbed : $sql;

        return mb_substr($sql, 0, self::MAX_SQL_LENGTH);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     *
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
