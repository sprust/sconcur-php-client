<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;

class FeatureResultNotFoundException extends Exception
{
    public function __construct(
        public readonly string $taskKey,
    ) {
        parent::__construct(
            message: "Feature result not found for task key [$taskKey]",
        );
    }
}
