<?php

namespace App\Service;

use App\Process\ProcessResult;

final readonly class CertificateIssueResult
{
    public function __construct(
        public CertificatePlan $plan,
        public ProcessResult $issueResult,
        public ProcessResult $installResult,
        public mixed $updateResult,
        public VerificationReport $verificationReport,
        public bool $issued = true,
    ) {
    }
}
