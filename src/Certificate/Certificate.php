<?php

declare(strict_types=1);

namespace App\Certificate;

use App\Domain\Hostname;
use InvalidArgumentException;

final readonly class Certificate
{
    /** @param list<Hostname> $hostnames */
    public function __construct(
        public Hostname $primaryHostname,
        public array $hostnames,
        public string $keyPath,
        public string $certificatePath,
        public string $acmeDirectory,
        public KeyType $keyType,
        public CertificateAuthority $certificateAuthority,
        public ChallengeType $challengeType,
    ) {
        if ($this->hostnames === []) {
            throw new InvalidArgumentException(
                'Ein Zertifikat benötigt mindestens einen Hostnamen.',
            );
        }

        if (!$this->containsHostname($this->primaryHostname)) {
            throw new InvalidArgumentException(
                'Der primäre Hostname muss in der Hostnamenliste enthalten sein.',
            );
        }

        if (trim($this->keyPath) === '') {
            throw new InvalidArgumentException(
                'Der Zielpfad für den privaten Schlüssel darf nicht leer sein.',
            );
        }

        if (trim($this->certificatePath) === '') {
            throw new InvalidArgumentException(
                'Der Zielpfad für das Zertifikat darf nicht leer sein.',
            );
        }

        if (trim($this->acmeDirectory) === '') {
            throw new InvalidArgumentException(
                'Das ACME-Verzeichnis darf nicht leer sein.',
            );
        }

        if (
            $this->containsWildcard()
            && $this->challengeType !== ChallengeType::Dns01
        ) {
            throw new InvalidArgumentException(
                'Wildcard-Zertifikate benötigen die DNS-01-Challenge.',
            );
        }
    }

    public function domain(): string
    {
        return $this->primaryHostname->value();
    }

    public function containsWildcard(): bool
    {
        foreach ($this->hostnames as $hostname) {
            if ($hostname->isWildcard()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function hostnameValues(): array
    {
        return array_map(
            static fn (Hostname $hostname): string => $hostname->value(),
            $this->hostnames,
        );
    }

    public function acmeCertificatePath(): string
    {
        return sprintf(
            '%s/%s.cer',
            rtrim($this->acmeDirectory, '/'),
            $this->domain(),
        );
    }

    public function acmeKeyPath(): string
    {
        return sprintf(
            '%s/%s.key',
            rtrim($this->acmeDirectory, '/'),
            $this->domain(),
        );
    }

    public function acmeBundlePath(): string
    {
        return rtrim($this->acmeDirectory, '/') . '/ca.cer';
    }

    public function acmeFullChainPath(): string
    {
        return rtrim($this->acmeDirectory, '/') . '/fullchain.cer';
    }

    private function containsHostname(Hostname $expected): bool
    {
        foreach ($this->hostnames as $hostname) {
            if ($hostname->equals($expected)) {
                return true;
            }
        }

        return false;
    }
}