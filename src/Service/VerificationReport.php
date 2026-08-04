<?php

namespace App\Service;

use App\Certificate\CertificateInfo;

final readonly class VerificationReport
{
    /**
     * @param list<VerificationCheck> $checks
     */
    public function __construct(
        public CertificateInfo $certificateInfo,
        public array $checks,
    ) {
    }

    public function successful(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->successful) {
                return false;
            }
        }

        return true;
    }
}
