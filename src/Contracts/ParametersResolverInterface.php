<?php

declare(strict_types=1);

namespace SConcur\Contracts;

use Closure;
use SConcur\Entities\Context;

interface ParametersResolverInterface
{
    /**
     * @return array<string, object>
     */
    public function make(Context $context, Closure $callback): array;
}
