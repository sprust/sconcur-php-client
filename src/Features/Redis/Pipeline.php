<?php

declare(strict_types=1);

namespace SConcur\Features\Redis;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;
use SConcur\Features\Redis\Support\Arguments;
use SConcur\Features\Redis\Support\ReplyDecoder;

/**
 * A batch of commands sent in one go: every command is written before any answer is read,
 * so N commands cost one round trip instead of N.
 *
 * Commands are written raw here, with command() alone — the typed methods on Connection
 * reshape the reply of the command they name, and a pipeline hands back a plain list of
 * replies whose order is the order they were added in. Reading a reply out of that list
 * and shaping it is the caller's, where it is visible.
 *
 * A failed command does not fail the batch: the server ran the others, and its failure
 * takes its own place in the list as a Dto\ErrorReply.
 */
class Pipeline
{
    /**
     * @var list<array{n: string, a: list<string>}>
     */
    protected array $commands = [];

    public function __construct(
        protected readonly Connection $connection,
    ) {
    }

    /**
     * Adds one command to the batch.
     *
     * @param list<mixed> $arguments
     */
    public function command(string $name, array $arguments = []): static
    {
        $this->commands[] = [
            'n' => $name,
            'a' => Arguments::encode($arguments),
        ];

        return $this;
    }

    /** How many commands the batch holds. */
    public function count(): int
    {
        return count($this->commands);
    }

    /**
     * Sends the batch and returns one reply per command, in the order they were added.
     *
     * With $atomic the batch runs inside MULTI/EXEC — the server runs it as one unit. The
     * pipeline is emptied, so the object can be filled again.
     *
     * @return list<mixed>
     */
    public function execute(bool $atomic = false): array
    {
        if ($this->commands === []) {
            throw new InvalidRedisArgumentException(
                message: 'A pipeline needs at least one command.',
            );
        }

        $commands = $this->commands;

        $this->commands = [];

        $result = $this->connection->execute(
            command: RedisCommandEnum::Pipeline,
            data: [
                'c'  => $commands,
                'at' => $atomic,
            ],
        );

        return ReplyDecoder::decodeList($result);
    }
}
