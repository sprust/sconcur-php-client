<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;

class FiberNotFoundByTaskKeyException extends Exception
{
    public function __construct(
        public readonly string $taskKey,
    ) {
        parent::__construct(
            message: "Fiber not found by task key [$taskKey]",
        );
    }
}
