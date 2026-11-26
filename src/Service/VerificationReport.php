<?php

namespace App\Service;

use App\Certificate\CertificateInfo;

final readonly class VerificationReport
{
    /**
     * @param list<VerificationCheck> $checks
     */
    public function __construct(
        public ?CertificateInfo $certificateInfo,
        public array $checks,
        public ?CertificateInfo $liveCertificateInfo = null,
        public ?string $localCertificatePath = null,
        public ?string $localKeyPath = null,
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

    public function status(string $scope): \App\Certificate\CertificateStatus
    {
        $status = \App\Certificate\CertificateStatus::NOT_CHECKED;
        $priority = ['NOT_CHECKED' => 0, 'VALID' => 1, 'EXPIRING' => 2, 'REVOCATION_UNKNOWN' => 3,
            'CERTIFICATE_INVALID' => 4, 'CONNECTION_ERROR' => 5, 'HTTPS_UNAVAILABLE' => 6,
            'CERTIFICATE_MISSING' => 7, 'CERTIFICATE_MISMATCH' => 8, 'HOSTNAME_MISMATCH' => 9,
            'EXPIRED' => 10, 'INSTALLATION_FAILED' => 11, 'REVOKED' => 12, 'EXTERNAL_DNS' => 13];
        foreach ($this->checks as $check) {
            if ($check->scope === $scope && $priority[$check->status->value] > $priority[$status->value]) {
                $status = $check->status;
            }
        }

        return $status;
    }

    public function scopeSuccessful(string $scope): bool
    {
        $found = false;
        foreach ($this->checks as $check) {
            if ($check->scope === $scope) {
                $found = true;
                if (!$check->successful) {
                    return false;
                }
            }
        }

        return $found;
    }

    public function summary(): string
    {
        return \sprintf('Lokal: %s; öffentlich: %s; Installation: %s', $this->status('local')->value, $this->status('public')->value, $this->status('installation')->value);
    }
}
