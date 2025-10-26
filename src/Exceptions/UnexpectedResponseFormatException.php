<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;

class UnexpectedResponseFormatException extends Exception
{
    /**
     * @param array<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(
            'Unexpected response structure: ' . implode(', ', $errors)
        );
    }
}
