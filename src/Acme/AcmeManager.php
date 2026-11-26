<?php

namespace App\Acme;

use App\Certificate\Certificate;
use App\Config\Configuration;
use App\Process\ProcessResult;
use App\Process\ProcessRunnerInterface;

final readonly class AcmeManager
{
    public function __construct(
        private Configuration $configuration,
        private ProcessRunnerInterface $processRunner,
    ) {
    }

    public function issue(
        Certificate $certificate,
        bool $force = false,
    ): ProcessResult {
        try {
            $result = $this->processRunner->run(
                command: $this->issueCommand($certificate, $force),
                timeout: 900,
                liveOutput: false,
            );
        } catch (\Throwable) {
            throw new \RuntimeException('ACME-Ausstellung fehlgeschlagen oder Zeitlimit erreicht; keine automatische Wiederholung.');
        }

        if ($result->successful()) {
            return $result;
        }

        if (
            $result->contains('Domains not changed')
            && $this->certificateFilesExist($certificate)
        ) {
            return new ProcessResult(0, 'acme.sh hat die Ausstellung übersprungen; vorhandene Dateien müssen validiert werden.', '', skipped: true);
        }

        throw new \RuntimeException(\sprintf("Die Zertifikatsausstellung ist fehlgeschlagen.\n%s", 'Details aus Sicherheitsgründen unterdrückt; ACME-Fehler/Rate-Limit prüfen.'));
    }

    public function install(Certificate $certificate): ProcessResult
    {
        $result = $this->processRunner->run(
            command: $this->installCommand($certificate),
            timeout: 120,
            liveOutput: false,
        );

        if (!$result->successful()) {
            throw new \RuntimeException(\sprintf("Die Zertifikatsinstallation ist fehlgeschlagen.\n%s", 'Details aus Sicherheitsgründen unterdrückt; ACME-Fehler/Rate-Limit prüfen.'));
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function issueCommand(
        Certificate $certificate,
        bool $force = false,
    ): array {
        $command = [
            $this->configuration->acmeExecutable,
            '--home', $this->configuration->acmeHome,
            '--config-home', $this->configuration->acmeConfigHome,
            '--issue',
            '--server',
            $certificate->certificateAuthority->value,
            '--dns',
            $this->configuration->dnsProvider,
            '--keylength',
            $certificate->keyType->value,
            '--dnssleep',
            (string) $this->configuration->dnsSleep,
        ];

        foreach ($certificate->hostnames as $hostname) {
            $command[] = '-d';
            $command[] = $hostname->value();
        }

        if ($force) {
            $command[] = '--force';
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function installCommand(Certificate $certificate): array
    {
        $command = [
            $this->configuration->acmeExecutable,
            '--install-cert',
            '-d',
            $certificate->domain(),
        ];

        if ($certificate->keyType->usesEcc()) {
            $command[] = '--ecc';
        }

        $command[] = '--key-file';
        $command[] = $certificate->keyPath;
        $command[] = '--fullchain-file';
        $command[] = $certificate->certificatePath;
        $command[] = '--reloadcmd';
        $command[] = 'systemctl reload apache2';

        return $command;
    }

    /**
     * @param list<string> $command
     */
    public function formatCommand(array $command): string
    {
        return implode(
            ' ',
            array_map(
                static fn (string $argument): string => escapeshellarg($argument),
                $command,
            ),
        );
    }

    private function certificateFilesExist(Certificate $certificate): bool
    {
        return is_file($certificate->acmeCertificatePath())
            && is_file($certificate->acmeKeyPath())
            && is_file($certificate->acmeBundlePath())
            && is_file($certificate->acmeFullChainPath());
    }
}
