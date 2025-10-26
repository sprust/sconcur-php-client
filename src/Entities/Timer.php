<?php

declare(strict_types=1);

namespace SConcur\Entities;

use SConcur\Contracts\ContextCheckerInterface;
use SConcur\Exceptions\InvalidValueException;
use SConcur\Exceptions\TimeoutException;

readonly class Timer implements ContextCheckerInterface
{
    public float $timeout;
    public float $startTime;

    public function __construct(int $timeoutSeconds)
    {
        if ($timeoutSeconds < 1) {
            throw new InvalidValueException(
                'Timeout seconds must be greater than 0'
            );
        }

        $this->timeout   = (float) $timeoutSeconds;
        $this->startTime = microtime(true);
    }

    public function check(): void
    {
        if ($this->isTimeout()) {
            throw new TimeoutException();
        }
    }

    protected function isTimeout(): bool
    {
        return (microtime(true) - $this->startTime) > $this->timeout;
    }
}
