<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;

class NotConnectedException extends Exception
{
    public function __construct()
    {
        parent::__construct(
            message: 'Not connected',
        );
    }
}
