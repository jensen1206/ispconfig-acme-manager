<?php

namespace App\Certificate;

final readonly class CertificateInfo
{
    /**
     * @param list<string> $subjectAlternativeNames
     */
    public function __construct(
        public string $commonName,
        public string $issuer,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $validUntil,
        public array $subjectAlternativeNames,
        public string $fingerprint,
    ) {
    }

    public function isCurrentlyValid(
        ?\DateTimeImmutable $now = null,
    ): bool {
        $now ??= new \DateTimeImmutable();

        return $now >= $this->validFrom
            && $now <= $this->validUntil;
    }

    public function daysUntilExpiration(
        ?\DateTimeImmutable $now = null,
    ): int {
        $now ??= new \DateTimeImmutable();

        if ($now > $this->validUntil) {
            return 0;
        }

        return (int) $now
            ->setTime(0, 0)
            ->diff($this->validUntil->setTime(0, 0))
            ->format('%a');
    }
}
