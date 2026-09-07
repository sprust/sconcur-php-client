<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

/** Hashes. */
trait HashCommandsTrait
{
    /**
     * @param list<mixed> $arguments
     */
    abstract public function command(
        string $name,
        array $arguments = [],
        ?bool $blocking = null,
        ?int $timeoutMs = null,
    ): mixed;

    public function hGet(string $key, string $field): ?string
    {
        $reply = $this->command('HGET', [$key, $field]);

        return $reply === null ? null : (string) $reply;
    }

    /**
     * HSET with one field or many. Returns how many fields were new.
     *
     * @param array<string, string|int|float> $fields
     */
    public function hSet(string $key, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $arguments = [$key];

        foreach ($fields as $field => $value) {
            $arguments[] = (string) $field;
            $arguments[] = $value;
        }

        return (int) $this->command('HSET', $arguments);
    }

    /**
     * HMGET keyed by field, like mGet: a missing field is null.
     *
     * @param list<string> $fields
     *
     * @return array<string, string|null>
     */
    public function hMGet(string $key, array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        /** @var list<string|null> $reply */
        $reply = $this->command('HMGET', array_merge([$key], $fields));

        $values = [];

        foreach ($fields as $position => $field) {
            $value          = $reply[$position] ?? null;
            $values[$field] = $value === null ? null : (string) $value;
        }

        return $values;
    }

    /**
     * The whole hash as field => value.
     *
     * The shape is built here: on RESP2 the server answers with field and value
     * alternating in one flat list, and on RESP3 with a map. Both end up the same way
     * round, which is the point of the method existing.
     *
     * @return array<string, string>
     */
    public function hGetAll(string $key): array
    {
        /** @var array<int|string, string> $reply */
        $reply = $this->command('HGETALL', [$key]);

        return static::pairsToMap($reply);
    }

    public function hDel(string $key, string ...$fields): int
    {
        return $fields === []
            ? 0
            : (int) $this->command('HDEL', array_merge([$key], $fields));
    }

    public function hExists(string $key, string $field): bool
    {
        return (int) $this->command('HEXISTS', [$key, $field]) === 1;
    }

    public function hIncrBy(string $key, string $field, int $by = 1): int
    {
        return (int) $this->command('HINCRBY', [$key, $field, $by]);
    }

    public function hLen(string $key): int
    {
        return (int) $this->command('HLEN', [$key]);
    }

    /**
     * @return list<string>
     */
    public function hKeys(string $key): array
    {
        /** @var array<int, mixed> $reply */
        $reply = $this->command('HKEYS', [$key]);

        return array_map(static fn(mixed $item): string => (string) $item, array_values($reply));
    }

    /**
     * @return list<string>
     */
    public function hVals(string $key): array
    {
        /** @var array<int, mixed> $reply */
        $reply = $this->command('HVALS', [$key]);

        return array_map(static fn(mixed $item): string => (string) $item, array_values($reply));
    }

    /**
     * A flat field/value list, or an already-keyed map, into a map. On RESP3 the server
     * sends the map itself and there is nothing to fold.
     *
     * @param array<int|string, mixed> $reply
     *
     * @return array<string, string>
     */
    protected static function pairsToMap(array $reply): array
    {
        if (!array_is_list($reply)) {
            $map = [];

            foreach ($reply as $field => $value) {
                $map[(string) $field] = (string) $value;
            }

            return $map;
        }

        $map   = [];
        $count = count($reply);

        for ($index = 0; $index + 1 < $count; $index += 2) {
            $map[(string) $reply[$index]] = (string) $reply[$index + 1];
        }

        return $map;
    }
}
