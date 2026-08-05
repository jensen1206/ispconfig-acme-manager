<?php

declare(strict_types=1);

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
        public ?CertificateInfo $liveCertificateInfo = null,
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
