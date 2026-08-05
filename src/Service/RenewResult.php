<?php

declare(strict_types=1);

namespace App\Service;

final readonly class RenewResult
{
    public function __construct(
        public bool $renewed,
        public string $message,
        public ?CertificateIssueResult $issueResult = null,
        public ?VerificationReport $verificationReport = null,
    ) {
    }
}
