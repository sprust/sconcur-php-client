<?php

declare(strict_types=1);

namespace SConcur\Dto;

readonly class RunningTaskDto
{
    public function __construct(
        public string $key,
    ) {
    }
}
