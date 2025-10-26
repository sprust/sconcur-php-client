<?php

declare(strict_types=1);

namespace SConcur\Dto;

readonly class TaskResultDto
{
    public function __construct(
        public string $key,
        public string $result,
    ) {
    }
}
