<?php

declare(strict_types=1);

namespace SConcur\Contracts;

use Throwable;

interface ContextCheckerInterface
{
    /**
     * @throws Throwable
     */
    public function check(): void;
}
