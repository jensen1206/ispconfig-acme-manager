<?php

namespace App\Service;

use App\Config\Configuration;
use App\ISPConfig\ApiClient;
use App\Process\ProcessRunnerInterface;

final readonly class DoctorService
{
    public function __construct(
        private Configuration $configuration,
        private ApiClient $apiClient,
        private ProcessRunnerInterface $processRunner,
    ) {
    }

    /**
     * @return list<DoctorCheck>
     */
    public function run(): array
    {
        return [
            $this->checkPhpVersion(),
            $this->checkAcmeExecutable(),
            $this->checkAcmeVersion(),
            $this->checkConfigurationFile(),
            $this->checkApiConnection(),
            $this->checkDnsProvider(),
            $this->checkApache(),
            $this->checkApacheConfiguration(),
            $this->checkSystemctl(),
            $this->checkOpenSsl(),
            $this->checkCrlSupport(),
            new DoctorCheck('Serverzuordnung', $this->configuration->localServerId > 0, 'local_server_id='.$this->configuration->localServerId),
            new DoctorCheck('ISPConfig-Cleanup', false, 'Nicht über eine verifizierte Leseschnittstelle verfügbar. Je Webserver prüfen: le_auto_cleanup=n, le_revoke_before_delete=n; le_delete_on_site_remove separat bewerten. Keine Einstellungen geändert.', true),
        ];
    }

    private function checkPhpVersion(): DoctorCheck
    {
        $successful = version_compare(\PHP_VERSION, '8.2.0', '>=');

        return new DoctorCheck(
            name: 'PHP-Version',
            successful: $successful,
            message: $successful
                ? \sprintf('PHP %s', \PHP_VERSION)
                : \sprintf(
                    'PHP %s gefunden, mindestens PHP 8.2 erforderlich.',
                    \PHP_VERSION,
                ),
        );
    }

    private function checkAcmeExecutable(): DoctorCheck
    {
        $path = $this->configuration->acmeExecutable;

        if (!is_file($path)) {
            return new DoctorCheck(
                name: 'acme.sh',
                successful: false,
                message: \sprintf('Datei nicht gefunden: %s', $path),
            );
        }

        if (!is_executable($path)) {
            return new DoctorCheck(
                name: 'acme.sh',
                successful: false,
                message: \sprintf('Datei ist nicht ausführbar: %s', $path),
            );
        }

        return new DoctorCheck(
            name: 'acme.sh',
            successful: true,
            message: $path,
        );
    }

    private function checkAcmeVersion(): DoctorCheck
    {
        if (!is_executable($this->configuration->acmeExecutable)) {
            return new DoctorCheck(
                name: 'acme.sh-Version',
                successful: false,
                message: 'acme.sh kann nicht ausgeführt werden.',
            );
        }

        $result = $this->processRunner->run(
            command: [
                $this->configuration->acmeExecutable,
                '--version',
            ],
            timeout: 30,
            liveOutput: false,
        );

        return new DoctorCheck(
            name: 'acme.sh-Version',
            successful: $result->successful(),
            message: $result->successful()
                ? $result->combinedOutput()
                : 'Version konnte nicht ermittelt werden.',
        );
    }

    private function checkConfigurationFile(): DoctorCheck
    {
        $path = '/etc/ispconfig-dns-cert.php';

        if (!is_file($path)) {
            return new DoctorCheck(
                name: 'Konfigurationsdatei',
                successful: false,
                message: \sprintf('Datei nicht gefunden: %s', $path),
            );
        }

        $permissions = fileperms($path);

        if (false === $permissions) {
            return new DoctorCheck(
                name: 'Konfigurationsdatei',
                successful: false,
                message: 'Dateiberechtigungen konnten nicht gelesen werden.',
            );
        }

        $mode = substr(\sprintf('%o', $permissions), -4);
        $secure = \in_array($mode, ['0600', '0640'], true);

        return new DoctorCheck(
            name: 'Konfigurationsdatei',
            successful: $secure,
            message: \sprintf('%s, Rechte %s', $path, $mode),
        );
    }

    private function checkApiConnection(): DoctorCheck
    {
        try {
            $session = $this->apiClient->login();

            return new DoctorCheck(
                name: 'ISPConfig-API',
                successful: true,
                message: 'Login erfolgreich.',
            );
        } catch (\Throwable $exception) {
            return new DoctorCheck(
                name: 'ISPConfig-API',
                successful: false,
                message: $exception->getMessage(),
            );
        } finally {
            $this->apiClient->logoutSilently();
        }
    }

    private function checkDnsProvider(): DoctorCheck
    {
        $provider = $this->configuration->dnsProvider;
        $path = \sprintf(
            rtrim($this->configuration->acmeHome, '/').'/dnsapi/%s.sh',
            $provider,
        );

        if (!is_file($path)) {
            return new DoctorCheck(
                name: 'DNS-Provider',
                successful: false,
                message: \sprintf('DNS-API-Script fehlt: %s', $path),
            );
        }

        return new DoctorCheck(
            name: 'DNS-Provider',
            successful: true,
            message: \sprintf('%s (%s)', $provider, $path),
        );
    }

    private function checkApache(): DoctorCheck
    {
        $result = $this->processRunner->run(
            command: ['apache2ctl', '-v'],
            timeout: 30,
            liveOutput: false,
        );

        return new DoctorCheck(
            name: 'Apache',
            successful: $result->successful(),
            message: $result->successful()
                ? $result->combinedOutput()
                : 'Apache konnte nicht geprüft werden.',
        );
    }

    private function checkApacheConfiguration(): DoctorCheck
    {
        $result = $this->processRunner->run(
            command: ['apache2ctl', 'configtest'],
            timeout: 60,
            liveOutput: false,
        );

        return new DoctorCheck(
            name: 'Apache-Konfiguration',
            successful: $result->successful(),
            message: $result->successful()
                ? 'Syntax OK'
                : $result->combinedOutput(),
        );
    }

    private function checkSystemctl(): DoctorCheck
    {
        $result = $this->processRunner->run(
            command: ['systemctl', 'is-active', 'apache2'],
            timeout: 30,
            liveOutput: false,
        );

        return new DoctorCheck(
            name: 'Apache-Dienst',
            successful: $result->successful(),
            message: trim($result->combinedOutput()),
        );
    }

    private function checkOpenSsl(): DoctorCheck
    {
        $result = $this->processRunner->run(
            command: ['openssl', 'version'],
            timeout: 30,
            liveOutput: false,
        );

        return new DoctorCheck(
            name: 'OpenSSL',
            successful: $result->successful(),
            message: trim($result->combinedOutput()),
        );
    }

    private function checkCrlSupport(): DoctorCheck
    {
        try {
            $result = $this->processRunner->run(['openssl', 'verify', '-help'], 15, false);
            $supported = $result->contains('-crl_check') && $result->contains('-CRLfile') && \extension_loaded('openssl');

            return new DoctorCheck('OpenSSL CRL', $supported, $supported ? 'CRL-Prüfung verfügbar.' : 'CRL-Unterstützung fehlt.');
        } catch (\Throwable) {
            return new DoctorCheck('OpenSSL CRL', false, 'OpenSSL nicht ausführbar.');
        }
    }
}
