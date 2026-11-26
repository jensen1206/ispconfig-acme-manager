<?php

namespace App\Certificate;

use App\Process\ProcessRunnerInterface;

final readonly class LiveCertificateReader
{
    public function __construct(private ProcessRunnerInterface $processRunner, private CertificateReader $certificateReader, private int $timeout = 15)
    {
    }

    public function read(string $hostname, int $port = 443, ?int $timeout = null): CertificateInfo
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Ungültiger TLS-Port.');
        }
        try {
            $result = $this->processRunner->run(['openssl', 's_client', '-connect', \sprintf('%s:%d', $hostname, $port),
                '-servername', $hostname, '-showcerts'], $timeout ?? $this->timeout, false);
        } catch (\Throwable) {
            throw new \RuntimeException('CONNECTION_ERROR: TLS-Verbindung fehlgeschlagen oder Zeitlimit erreicht.');
        }
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $result->output, $matches)) {
            $status = str_contains(strtolower($result->combinedOutput()), 'refused') ? 'HTTPS_UNAVAILABLE' : 'CONNECTION_ERROR';
            throw new \RuntimeException($status.': Kein öffentliches Zertifikat empfangen.');
        }

        return $this->certificateReader->readPem(implode("\n", $matches[0]));
    }
}
