<?php

namespace App\Service;

final readonly class RenewResult
{
    public function __construct(
        public bool $renewed,
        public string $message,
        public ?CertificateIssueResult $issueResult = null,
        public ?VerificationReport $verificationReport = null,
        public bool $reinstalled = false,
    ) {
    }
}
