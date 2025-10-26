<?php

declare(strict_types=1);

namespace SConcur\Dto;

readonly class FeatureResultDto
{
    public function __construct(
        public int|string $key,
        public mixed $result,
    ) {
    }
}
