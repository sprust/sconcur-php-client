<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;

class InvalidResponseLengthException extends Exception
{
    public function __construct(
        public readonly int $expectedLength,
        public readonly int $actualLength,
    ) {
        parent::__construct(
            sprintf(
                "Invalid response length. Expected [%d] bytes, but got [%d].",
                $this->expectedLength,
                $this->actualLength
            )
        );
    }
}
