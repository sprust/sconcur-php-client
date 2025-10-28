<?php

declare(strict_types=1);

namespace SConcur\Logging;

use SConcur\SConcur;

class LoggerFormatter
{
    public static function make(string $message, ?string $taskKey = null): string
    {
        return "[flow: " . SConcur::getFlowUuid() . ($taskKey ? ", task: $taskKey" : '') . ']: ' . $message;
    }
}
