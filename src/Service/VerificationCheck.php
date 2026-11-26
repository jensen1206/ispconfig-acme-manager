<?php

namespace App\Service;

final readonly class VerificationCheck
{
    public function __construct(
        public string $name,
        public bool $successful,
        public string $message,
        public \App\Certificate\CertificateStatus $status = \App\Certificate\CertificateStatus::VALID,
        public string $scope = 'local',
    ) {
    }
}
