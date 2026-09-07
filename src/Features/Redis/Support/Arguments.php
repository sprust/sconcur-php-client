<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;

/**
 * Command arguments on their way to the wire.
 *
 * Redis takes byte strings and nothing else, so the conversion happens here, once, and
 * only for the three types where it is unambiguous. A boolean or a null is refused rather
 * than converted: phpredis writes false as an empty string, and a value the application
 * never asked for only ever shows up later, in the data.
 */
readonly class Arguments
{
    /**
     * @param list<mixed> $arguments
     *
     * @return list<string>
     */
    public static function encode(array $arguments): array
    {
        $encoded = [];

        foreach ($arguments as $position => $argument) {
            $encoded[] = static::encodeOne(argument: $argument, position: $position);
        }

        return $encoded;
    }

    protected static function encodeOne(mixed $argument, int|string $position): string
    {
        if (is_string($argument)) {
            return $argument;
        }

        if (is_int($argument)) {
            return (string) $argument;
        }

        if (is_float($argument)) {
            if (is_nan($argument) || is_infinite($argument)) {
                throw new InvalidRedisArgumentException(
                    message: "Argument #$position is not a finite number.",
                );
            }

            // var_export, not a string cast: the cast rounds to the `precision` ini
            // setting (14 digits by default), which quietly changes a sorted-set score
            // on its way to the server. This writes the shortest form that reads back
            // as the same double.
            return var_export($argument, true);
        }

        $type = get_debug_type($argument);

        throw new InvalidRedisArgumentException(
            message: "Argument #$position is a $type; a Redis argument must be a string, an int or a float. "
                . 'Serialize the value yourself — this feature stores bytes and converts nothing.',
        );
    }
}
