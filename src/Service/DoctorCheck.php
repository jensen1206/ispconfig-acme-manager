<?php

declare(strict_types=1);

namespace App\Service;

final readonly class DoctorCheck
{
    public function __construct(
        public string $name,
        public bool $successful,
        public string $message,
    ) {
    }
}