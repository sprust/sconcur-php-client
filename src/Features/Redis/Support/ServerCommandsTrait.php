<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

/** The few server commands worth a method. */
trait ServerCommandsTrait
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

    public function ping(): bool
    {
        return (string) $this->command('PING') === 'PONG';
    }

    public function dbSize(): int
    {
        return (int) $this->command('DBSIZE');
    }

    /**
     * Empties the current database. The confirmation argument is not decoration: this
     * takes a whole database away, and a method that does it on a bare call is a method
     * somebody eventually calls by mistake.
     */
    public function flushDb(bool $confirm): bool
    {
        if (!$confirm) {
            return false;
        }

        return $this->command('FLUSHDB') !== null;
    }

    /** The INFO text, as the server formats it. */
    public function info(string $section = ''): string
    {
        return (string) $this->command('INFO', $section === '' ? [] : [$section]);
    }
}
