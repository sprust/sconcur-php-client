<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;
use SConcur\Contracts\ContextCheckerInterface;
use Throwable;

class ContextCheckerException extends Exception
{
    public function __construct(
        public readonly ContextCheckerInterface $checker,
        public readonly Throwable $exception
    ) {
        parent::__construct(
            sprintf(
                'Cancelled by [%s] with exception [%s: %s]',
                $checker::class,
                $exception::class,
                $exception->getMessage()
            ),
            previous: $this->exception
        );
    }
}
