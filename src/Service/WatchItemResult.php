<?php

namespace App\Service;

final readonly class WatchItemResult
{
    public function __construct(
        public string $domain,
        public string $status,
        public string $message,
        public bool $changed = false,
        public bool $failed = false,
        public ?VerificationReport $verificationReport = null,
    ) {
    }
}
