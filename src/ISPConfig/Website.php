<?php

declare(strict_types=1);

namespace App\ISPConfig;

use App\Domain\Hostname;
use InvalidArgumentException;

final readonly class Website
{
    /**
     * @param list<Hostname> $hostnames
     */
    public function __construct(
        public int $id,
        public int $systemGroupId,
        public Hostname $primaryHostname,
        public array $hostnames,
        public string $documentRoot,
        public string $systemUser,
        public string $systemGroup,
        public int $serverId,
        public bool $sslEnabled,
        public bool $letsEncryptEnabled,
    ) {
        if ($this->hostnames === []) {
            throw new InvalidArgumentException(
                'Eine Website benötigt mindestens einen Hostnamen.',
            );
        }

        if (!$this->containsHostname($this->primaryHostname)) {
            throw new InvalidArgumentException(
                'Der primäre Hostname muss in der Hostnamenliste enthalten sein.',
            );
        }
    }

    public function domain(): string
    {
        return $this->primaryHostname->value();
    }

    public function sslDirectory(): string
    {
        return $this->documentRoot . '/ssl';
    }

    public function certificatePath(): string
    {
        return sprintf(
            '%s/%s.crt',
            $this->sslDirectory(),
            $this->domain(),
        );
    }

    public function keyPath(): string
    {
        return sprintf(
            '%s/%s.key',
            $this->sslDirectory(),
            $this->domain(),
        );
    }

    public function acmeDirectory(): string
    {
        return sprintf(
            '/root/.acme.sh/%s_ecc',
            $this->domain(),
        );
    }

    public function acmeCertificatePath(): string
    {
        return sprintf(
            '%s/%s.cer',
            $this->acmeDirectory(),
            $this->domain(),
        );
    }

    public function acmeKeyPath(): string
    {
        return sprintf(
            '%s/%s.key',
            $this->acmeDirectory(),
            $this->domain(),
        );
    }

    public function acmeBundlePath(): string
    {
        return $this->acmeDirectory() . '/ca.cer';
    }

    public function acmeFullChainPath(): string
    {
        return $this->acmeDirectory() . '/fullchain.cer';
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
