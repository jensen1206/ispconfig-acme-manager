<?php

declare(strict_types=1);

namespace App\ISPConfig;

final readonly class ApiResponse
{
    public function __construct(
        public bool $successful,
        public mixed $data,
        public ?string $message = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }
}