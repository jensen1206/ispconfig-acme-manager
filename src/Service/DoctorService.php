<?php

namespace App\Service;

use App\Config\Configuration;
use App\ISPConfig\ApiClient;
use App\Process\ProcessRunner;

final readonly class DoctorService
{
    public function __construct(
        private Configuration $configuration,
        private ApiClient $apiClient,
        private ProcessRunner $processRunner,
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
                message: \sprintf(
                    'Login erfolgreich, Session %s…',
                    substr($session->id(), 0, 8),
                ),
            );
        } catch (\Throwable $exception) {
            return new DoctorCheck(
                name: 'ISPConfig-API',
                successful: false,
                message: $exception->getMessage(),
            );
        } finally {
            $this->apiClient->logout();
        }
    }

    private function checkDnsProvider(): DoctorCheck
    {
        $provider = $this->configuration->dnsProvider;
        $path = \sprintf(
            '/root/.acme.sh/dnsapi/%s.sh',
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
}
