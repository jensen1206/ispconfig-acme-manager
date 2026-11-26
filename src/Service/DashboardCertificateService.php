<?php

namespace App\Service;

use App\Acme\AcmeManager;
use App\Certificate\Certificate;
use App\Certificate\CertificateAuthority;
use App\Certificate\CertificateReader;
use App\Certificate\CertificateStatus;
use App\Certificate\CertificateValidator;
use App\Certificate\ChallengeType;
use App\Certificate\KeyType;
use App\Certificate\LiveCertificateReader;
use App\Config\Configuration;
use App\Dns\NameserverChecker;
use App\Domain\Hostname;

final readonly class DashboardCertificateService
{
    public function __construct(
        private Configuration $configuration,
        private AcmeManager $acmeManager,
        private CertificateReader $certificateReader,
        private LiveCertificateReader $liveCertificateReader,
        private CertificateValidator $validator,
        private NameserverChecker $nameserverChecker,
        private OperationGuard $guard,
    ) {
    }

    public function certificate(): Certificate
    {
        $hostname = new Hostname($this->configuration->dashboardHostname());

        return new Certificate($hostname, [$hostname], $this->configuration->dashboardKeyPath,
            $this->configuration->dashboardCertificatePath,
            rtrim($this->configuration->acmeConfigHome, '/').'/'.$hostname->value().'_ecc',
            KeyType::Ec256, CertificateAuthority::LetsEncrypt, ChallengeType::Dns01);
    }

    public function renew(bool $force = false): DashboardCertificateResult
    {
        if ($this->configuration->localServerId <= 0) {
            throw new \RuntimeException('local_server_id muss vor Dashboard-Änderungen konfiguriert sein.');
        }
        $certificate = $this->certificate();
        $owner = 'dashboard-'.hash('sha256', $certificate->domain().':'.$this->configuration->dashboardPort());
        $source = $this->readLocal($certificate, true);
        $status = $source->status('local');
        $issued = false;
        if ($force || \in_array($status, [CertificateStatus::CERTIFICATE_MISSING, CertificateStatus::EXPIRED, CertificateStatus::REVOKED, CertificateStatus::EXPIRING], true)) {
            $this->nameserverChecker->assertManaged($certificate->primaryHostname);
            $issue = $this->guard->runNamed($owner, 'issue', fn () => $this->acmeManager->issue($certificate, $force || CertificateStatus::REVOKED === $status));
            $issued = !$issue->skipped;
            $source = $this->readLocal($certificate, true);
        }
        if (!$source->successful() || null === $source->certificateInfo) {
            throw new \RuntimeException('Dashboard-ACME-Bestand nicht sicher verwendbar: '.$source->summary());
        }
        $expected = $source->certificateInfo;

        return $this->guard->runNamed($owner, 'install-'.$expected->fingerprint, function () use ($certificate, $expected, $issued): DashboardCertificateResult {
            $this->acmeManager->install($certificate, $this->configuration->dashboardReloadCommand);
            $deadline = microtime(true) + $this->configuration->installationTimeout;
            $last = 'Noch keine öffentliche Bestätigung.';
            do {
                $local = $this->readLocal($certificate, false, $deadline);
                if ($local->successful() && null !== $local->certificateInfo && hash_equals($expected->fingerprint, $local->certificateInfo->fingerprint)) {
                    try {
                        $remaining = (int) floor($deadline - microtime(true));
                        if ($remaining < 1) {
                            break;
                        }
                        $live = $this->liveCertificateReader->read($certificate->domain(), $this->configuration->dashboardPort(), min($remaining, $this->configuration->networkTimeout));
                        $checks = $this->validator->check($live, [$certificate->domain()], 'public', deadline: $deadline);
                        $matches = hash_equals($expected->fingerprint, $live->fingerprint) && $expected->serialNumber === $live->serialNumber;
                        $checks[] = new VerificationCheck('Fingerprint/Seriennummer', $matches, 'Vergleich mit erwartetem Dashboard-Zertifikat.', $matches ? CertificateStatus::VALID : CertificateStatus::CERTIFICATE_MISMATCH, 'public');
                        $report = new VerificationReport($local->certificateInfo, [...$local->checks, ...$checks], $live);
                        if ($report->successful()) {
                            return new DashboardCertificateResult($certificate, $local->certificateInfo, $live, $issued);
                        }
                        $last = $report->summary();
                    } catch (\Throwable) {
                        $last = 'Öffentliche TLS-Prüfung nicht erfolgreich.';
                    }
                } else {
                    $last = 'Lokales Ziel nicht bestätigt. '.$local->summary();
                }
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                usleep((int) (min($this->configuration->pollInterval, $remaining) * 1000000));
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('INSTALLATION_FAILED: Dashboard-Installation nicht innerhalb des Zeitlimits bestätigt. '.$last);
        });
    }

    private function readLocal(Certificate $certificate, bool $source, ?float $deadline = null): VerificationReport
    {
        $path = $source ? $certificate->acmeFullChainPath() : $certificate->certificatePath;
        $key = $source ? $certificate->acmeKeyPath() : $certificate->keyPath;
        $info = null;
        try {
            if (!is_file($path)) {
                $directory = \dirname($path);
                while (!is_dir($directory) && \dirname($directory) !== $directory) {
                    $directory = \dirname($directory);
                }
                if (!is_readable($directory) || (\DIRECTORY_SEPARATOR === '/' && !is_executable($directory))) {
                    throw new \RuntimeException('Verzeichnis nicht prüfbar.');
                }

                return new VerificationReport(null, [new VerificationCheck('Datei', false, 'Dashboard-Zertifikat fehlt.', CertificateStatus::CERTIFICATE_MISSING)]);
            }
            $info = $this->certificateReader->read($path);
            $checks = $this->validator->check($info, [$certificate->domain()], 'local', deadline: $deadline);
            $matches = $this->certificateReader->keyMatchesCertificate($path, $key);
            $checks[] = new VerificationCheck('Schlüsselpaar', $matches, 'Dashboard-Schlüsselzuordnung.', $matches ? CertificateStatus::VALID : CertificateStatus::CERTIFICATE_MISMATCH);

            return new VerificationReport($info, $checks);
        } catch (\Throwable) {
            return new VerificationReport($info, [new VerificationCheck('Datei', false, 'Dashboard-Dateien nicht sicher prüfbar.', CertificateStatus::CERTIFICATE_INVALID)]);
        }
    }
}
