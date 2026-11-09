<?php

declare(strict_types=1);

namespace Nowo\Beacon\Scrubber;

use BackedEnum;
use DateTimeInterface;
use ReflectionFunction;
use ReflectionMethod;
use Stringable;
use Throwable;
use UnitEnum;

use function array_is_list;
use function array_slice;
use function array_values;
use function count;
use function get_resource_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * Builds Sentry-compatible frame {@code vars} from PHP backtrace {@code args}.
 *
 * PHP exposes call arguments on stack frames, not true locals. Values are
 * JSON-safe summaries with depth/size caps, then passed through
 * {@see SensitiveValueRedactor}.
 */
final class FrameVarsSerializer
{
    private const MAX_DEPTH = 3;

    private const MAX_ITEMS = 25;

    private const MAX_STRING = 1024;

    /**
     * @param array<string, mixed> $frame PHP backtrace / Exception::getTrace() frame
     *
     * @return array<string, mixed>
     */
    public static function fromPhpFrame(array $frame): array
    {
        if (!isset($frame['args']) || !is_array($frame['args']) || $frame['args'] === []) {
            return [];
        }

        /** @var list<mixed> $args */
        $args  = array_values($frame['args']);
        $names = self::parameterNames($frame, count($args));
        $vars  = [];

        foreach ($args as $i => $arg) {
            $key        = $names[$i] ?? ('arg' . $i);
            $vars[$key] = self::serialize($arg, 0);
        }

        return SensitiveValueRedactor::redactMap($vars);
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @return list<string>
     */
    private static function parameterNames(array $frame, int $argc): array
    {
        if ($argc === 0) {
            return [];
        }

        $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';
        if ($function === '' || $function === '{closure}' || str_starts_with($function, '{closure')) {
            return [];
        }

        try {
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null;
            $ref   = $class !== null && $class !== ''
                ? new ReflectionMethod($class, $function)
                : new ReflectionFunction($function);

            $names = [];
            foreach ($ref->getParameters() as $parameter) {
                $names[] = $parameter->getName();
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    private static function serialize(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::truncateString($value);
        }

        if ($value instanceof UnitEnum) {
            return $value instanceof BackedEnum ? $value->value : $value->name;
        }

        if (is_resource($value)) {
            return sprintf('[Resource %s]', get_resource_type($value) ?: 'unknown');
        }

        if (is_object($value)) {
            if ($value instanceof Stringable) {
                return self::truncateString((string) $value);
            }

            if ($value instanceof DateTimeInterface) {
                return $value->format(DateTimeInterface::ATOM);
            }

            return $value::class;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return sprintf('[Array(%d)]', count($value));
            }

            return self::serializeArray($value, $depth + 1);
        }

        return SensitiveValueRedactor::FILTERED;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<int|string, mixed>
     */
    private static function serializeArray(array $value, int $depth): array
    {
        $isList = array_is_list($value);
        $slice  = array_slice($value, 0, self::MAX_ITEMS, true);
        $out    = [];

        foreach ($slice as $key => $item) {
            $out[$key] = self::serialize($item, $depth);
        }

        if (count($value) > self::MAX_ITEMS) {
            $marker       = $isList ? count($out) : '__truncated';
            $out[$marker] = sprintf('…(%d more)', count($value) - self::MAX_ITEMS);
        }

        return $out;
    }

    private static function truncateString(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING) . '…';
    }
}
