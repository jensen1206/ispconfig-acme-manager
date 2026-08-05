<?php

namespace App\Certificate;

use App\Process\ProcessRunner;

final readonly class LiveCertificateReader
{
    public function __construct(
        private ProcessRunner $processRunner,
        private CertificateReader $certificateReader,
    ) {
    }

    public function read(
        string $hostname,
        int $port = 443,
    ): CertificateInfo {
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Der TLS-Port muss zwischen 1 und 65535 liegen.');
        }

        $result = $this->processRunner->run(
            command: [
                'openssl',
                's_client',
                '-connect',
                \sprintf('%s:%d', $hostname, $port),
                '-servername',
                $hostname,
                '-showcerts',
            ],
            timeout: 30,
            liveOutput: false,
        );

        if (!$result->successful()) {
            throw new \RuntimeException(\sprintf('Das Live-Zertifikat von "%s:%d" konnte nicht geladen werden: %s', $hostname, $port, trim($result->combinedOutput())));
        }

        $pem = $this->extractFirstCertificate(
            $result->combinedOutput(),
        );

        $temporaryFile = tempnam(
            sys_get_temp_dir(),
            'ispconfig-acme-live-',
        );

        if (false === $temporaryFile) {
            throw new \RuntimeException('Temporäre Zertifikatsdatei konnte nicht erstellt werden.');
        }

        try {
            if (false === file_put_contents($temporaryFile, $pem)) {
                throw new \RuntimeException('Live-Zertifikat konnte nicht temporär gespeichert werden.');
            }

            return $this->certificateReader->read($temporaryFile);
        } finally {
            @unlink($temporaryFile);
        }
    }

    private function extractFirstCertificate(string $output): string
    {
        if (!preg_match(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $output,
            $matches,
        )) {
            throw new \RuntimeException('In der TLS-Antwort wurde kein Zertifikat gefunden.');
        }

        return $matches[0].\PHP_EOL;
    }
}
