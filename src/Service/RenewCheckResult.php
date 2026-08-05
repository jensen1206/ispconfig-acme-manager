<?php

declare(strict_types=1);

namespace App\Service;

final readonly class RenewCheckResult
{
    public function __construct(
        public bool $renewalRequired,
        public string $status,
        public string $message,
        public ?int $remainingDays = null,
        public ?VerificationReport $verificationReport = null,
    ) {
    }
}
