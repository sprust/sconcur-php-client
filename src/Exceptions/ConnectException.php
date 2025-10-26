<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;

class ConnectException extends Exception
{
    public function __construct(
        public readonly string $socketAddress,
        public readonly string $error
    ) {
        parent::__construct(
            message: "Connect to [$socketAddress] failed with error [$error]",
        );
    }
}
