<?php

namespace App\Service;

final readonly class VerificationCheck
{
    public function __construct(
        public string $name,
        public bool $successful,
        public string $message,
    ) {
    }
}
