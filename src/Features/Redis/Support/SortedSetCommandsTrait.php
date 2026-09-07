<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

/**
 * Sorted sets.
 *
 * The methods that can answer WITHSCORES fold the flat reply into member => score, which
 * is the one thing worth having a method for here.
 */
trait SortedSetCommandsTrait
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

    /**
     * @param array<string, float|int> $members member => score
     */
    public function zAdd(string $key, array $members): int
    {
        if ($members === []) {
            return 0;
        }

        $arguments = [$key];

        foreach ($members as $member => $score) {
            $arguments[] = $score;
            $arguments[] = (string) $member;
        }

        return (int) $this->command('ZADD', $arguments);
    }

    /**
     * ZRANGE. With $withScores the reply comes back as member => score; without it, as a
     * list of members in range order.
     *
     * @return list<string>|array<string, float>
     */
    public function zRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        return $this->range(
            name: 'ZRANGE',
            arguments: [$key, $start, $stop],
            withScores: $withScores,
        );
    }

    /**
     * ZRANGEBYSCORE. The bounds are written the way Redis takes them, so "-inf", "+inf"
     * and the exclusive "(5" all work.
     *
     * @return list<string>|array<string, float>
     */
    public function zRangeByScore(
        string $key,
        string $min,
        string $max,
        bool $withScores = false,
    ): array {
        return $this->range(
            name: 'ZRANGEBYSCORE',
            arguments: [$key, $min, $max],
            withScores: $withScores,
        );
    }

    public function zRem(string $key, string ...$members): int
    {
        return $members === []
            ? 0
            : (int) $this->command('ZREM', array_merge([$key], $members));
    }

    /** The member's score, or null when the member is not in the set. */
    public function zScore(string $key, string $member): ?float
    {
        $reply = $this->command('ZSCORE', [$key, $member]);

        return $reply === null ? null : (float) $reply;
    }

    public function zCard(string $key): int
    {
        return (int) $this->command('ZCARD', [$key]);
    }

    public function zIncrBy(string $key, string $member, float $by): float
    {
        return (float) $this->command('ZINCRBY', [$key, $by, $member]);
    }

    /**
     * @param list<mixed> $arguments
     *
     * @return list<string>|array<string, float>
     */
    protected function range(string $name, array $arguments, bool $withScores): array
    {
        if ($withScores) {
            $arguments[] = 'WITHSCORES';
        }

        /** @var array<int|string, mixed> $reply */
        $reply = $this->command($name, $arguments);

        if (!$withScores) {
            return array_map(static fn(mixed $item): string => (string) $item, array_values($reply));
        }

        // RESP3 answers a scored range with pairs already; RESP2 with member and score
        // alternating.
        if (!array_is_list($reply)) {
            $scores = [];

            foreach ($reply as $member => $score) {
                $scores[(string) $member] = (float) $score;
            }

            return $scores;
        }

        $scores = [];
        $count  = count($reply);

        for ($index = 0; $index + 1 < $count; $index += 2) {
            $scores[(string) $reply[$index]] = (float) $reply[$index + 1];
        }

        return $scores;
    }
}
