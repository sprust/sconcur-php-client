<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Exceptions\Redis\InvalidRedisDsnException;
use SConcur\Exceptions\Redis\RedisCommandException;
use SConcur\Exceptions\Redis\RedisConnectionException;
use SConcur\Exceptions\Redis\RedisException;
use SConcur\Exceptions\Redis\RedisTimeoutException;
use SConcur\Exceptions\Redis\UnsupportedRedisCommandException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use Throwable;

/**
 * Turns a failed task into the exception the caller expects.
 *
 * The core prefixes its errors with the feature name and puts the Redis error code first
 * in the text, so the split here is one prefix and one first word — not a set of patterns
 * matched against whole messages, which would rot the moment the core reworded one.
 */
readonly class RedisFailure
{
    protected const string PREFIX = 'redis: ';

    /** What the core says when a flow is stopped under a running command. */
    protected const string STOPPED = 'closed by task stop';

    public static function from(
        TaskErrorException|TaskExecutionException|Throwable $exception,
    ): RedisException|UnsupportedRedisCommandException|InvalidRedisDsnException {
        $message = static::strip($exception->getMessage());

        if (str_contains($message, 'would change the state of a shared connection')) {
            // A LogicException, so it does not descend from RedisException and cannot be
            // swallowed by a catch meant for a server failure.
            return new UnsupportedRedisCommandException(
                message: $message,
                previous: $exception,
            );
        }

        if (static::isDsnFailure($message)) {
            return new InvalidRedisDsnException(
                message: $message,
                previous: $exception,
            );
        }

        [$code] = static::split($message);

        if ($code === 'TIMEOUT') {
            return new RedisTimeoutException(
                message: $message,
                previous: $exception,
            );
        }

        if (static::isConnectionFailure($code, $message)) {
            return new RedisConnectionException(
                message: $message,
                previous: $exception,
            );
        }

        if ($code !== '') {
            // The code stays in the message as well as in errorCode: it is the most
            // greppable part of the text, and a log line that lost it says much less.
            return new RedisCommandException(
                errorCode: $code,
                message: $message,
                previous: $exception,
            );
        }

        return new RedisException(
            message: $message,
            previous: $exception,
        );
    }

    protected static function strip(string $message): string
    {
        if (str_starts_with($message, static::PREFIX)) {
            return substr($message, strlen(static::PREFIX));
        }

        return $message;
    }

    /**
     * The leading Redis error code, and what follows it. A code is a bare word in
     * capitals, which is what the protocol puts first in an error reply.
     *
     * @return array{0: string, 1: string}
     */
    protected static function split(string $message): array
    {
        $parts = explode(' ', $message, 2);

        if (preg_match('/^[A-Z]{3,}$/', $parts[0]) !== 1) {
            return ['', $message];
        }

        return [$parts[0], $parts[1] ?? ''];
    }

    protected static function isDsnFailure(string $message): bool
    {
        return str_starts_with($message, 'unsupported dsn scheme')
            || str_starts_with($message, 'unknown dsn parameter')
            || str_starts_with($message, 'empty dsn')
            || str_starts_with($message, 'open client:');
    }

    protected static function isConnectionFailure(string $code, string $message): bool
    {
        if ($code === 'IOERR' || $code === 'NOAUTH' || $code === 'WRONGPASS') {
            return true;
        }

        return str_starts_with($message, 'connect:')
            || str_contains($message, static::STOPPED);
    }
}
