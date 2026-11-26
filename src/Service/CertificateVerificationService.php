<?php

namespace App\Service;

use App\Certificate\CertificateFactory;
use App\Certificate\CertificateReader;
use App\Certificate\CertificateStatus;
use App\Certificate\CertificateValidator;
use App\Certificate\LiveCertificateReader;
use App\Config\Configuration;
use App\Domain\Hostname;
use App\ISPConfig\Website;
use App\ISPConfig\WebsiteRepository;

final readonly class CertificateVerificationService
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private CertificateFactory $certificateFactory,
        private CertificateReader $certificateReader,
        private LiveCertificateReader $liveCertificateReader,
        private CertificateValidator $validator,
        private Configuration $configuration,
    ) {
    }

    public function assertLocal(Website $website): void
    {
        if ($this->configuration->localServerId !== $website->serverId) {
            throw new \RuntimeException('INSTALLATION_FAILED: local_server_id muss dem zuständigen Webserver entsprechen; kein lokaler Zugriff.');
        }
    }

    public function verify(Hostname $hostname, bool $includeLiveCertificate = true): VerificationReport
    {
        return $this->verifyWebsite($this->websiteRepository->findByDomain($hostname), $includeLiveCertificate);
    }

    public function verifyWebsite(Website $website, bool $includeLiveCertificate = true, bool $acmeSource = false, int $days = 30, ?float $deadline = null): VerificationReport
    {
        $certificate = $this->certificateFactory->fromWebsite($website);
        $checks = [];
        $local = null;
        $live = null;
        $path = null;
        $key = null;
        try {
            $this->assertLocal($website);
            $path = $acmeSource ? $certificate->acmeFullChainPath() : $certificate->certificatePath;
            $key = $acmeSource ? $certificate->acmeKeyPath() : $certificate->keyPath;
            if (!$acmeSource) {
                [$path, $key] = (new \App\Certificate\VhostCertificateLocator())->locate(
                    $this->configuration->vhostDirectory.'/'.$website->domain().'.vhost', $path, $key);
            }            if (!is_file($path)) {
                // Distinguish absent files from an inaccessible ancestor. Never issue
                // a replacement merely because permissions prevent inspection.
                $ancestor = \dirname($path);
                while (!is_dir($ancestor) && \dirname($ancestor) !== $ancestor) {
                    $ancestor = \dirname($ancestor);
                }
                if (!is_readable($ancestor) || (\DIRECTORY_SEPARATOR === '/' && !is_executable($ancestor))) {
                    throw new \RuntimeException('Zertifikatspfad wegen fehlender Verzeichnisrechte nicht prüfbar.');
                }
                $checks[] = new VerificationCheck('Datei', false, 'Zertifikat fehlt: '.$path, CertificateStatus::CERTIFICATE_MISSING);
            } else {
                $local = $this->certificateReader->read($path);
                $checks[] = new VerificationCheck('Datei', true, $path);
                $checks = [...$checks, ...$this->validator->check($local, $certificate->hostnameValues(), 'local', $days, $deadline)];
                $matches = $this->certificateReader->keyMatchesCertificate($path, $key);
                $checks[] = new VerificationCheck('Schlüsselpaar', $matches, $matches ? 'Schlüssel passt.' : 'Schlüssel passt nicht.',
                    $matches ? CertificateStatus::VALID : CertificateStatus::CERTIFICATE_MISMATCH);
            }
        } catch (\Throwable $error) {
            $checks[] = new VerificationCheck('Lokale Prüfung', false, $error->getMessage(), CertificateStatus::CERTIFICATE_INVALID);
        }
        if ($includeLiveCertificate) {
            foreach ($certificate->hostnameValues() as $hostname) {
                if (str_contains($hostname, '*')) {
                    continue;
                }
                try {
                    $remaining = null === $deadline ? $this->configuration->networkTimeout : (int) floor($deadline - microtime(true));
                    if ($remaining < 1) {
                        throw new \RuntimeException('CONNECTION_ERROR: Prüfzeitlimit erreicht.');
                    }
                    $current = $this->liveCertificateReader->read($hostname, timeout: min($remaining, $this->configuration->networkTimeout));
                    $live ??= $current;
                    $checks = [...$checks, ...$this->validator->check($current, [$hostname], 'public', $days, $deadline)];
                    if (null !== $local) {
                        $matches = hash_equals($local->fingerprint, $current->fingerprint) && $local->serialNumber === $current->serialNumber;
                        $checks[] = new VerificationCheck('Fingerprint/Seriennummer', $matches,
                            $hostname.': '.$current->fingerprint.' / '.$current->serialNumber,
                            $matches ? CertificateStatus::VALID : CertificateStatus::CERTIFICATE_MISMATCH, 'public');
                    }
                } catch (\Throwable $error) {
                    $status = str_starts_with($error->getMessage(), 'HTTPS_UNAVAILABLE') ? CertificateStatus::HTTPS_UNAVAILABLE : CertificateStatus::CONNECTION_ERROR;
                    $checks[] = new VerificationCheck('TLS', false, $hostname.': '.$error->getMessage(), $status, 'public');
                }
            }
            if (!$acmeSource && $this->configuration->localServerId === $website->serverId) {
                $vhost = $this->configuration->vhostDirectory.'/'.$website->domain().'.vhost';
                if (is_readable($vhost) && is_file($vhost)) {
                    $content = file_get_contents($vhost);
                    if (\is_string($content)) {
                        $https = 1 === preg_match('/<VirtualHost\s+[^>]*:443(?:\s|>)/i', $content);
                        $checks[] = new VerificationCheck('HTTPS-VHost', $https,
                            $https ? 'HTTPS-VHost-Konfiguration vorhanden (kein Nachweis ihrer Aktivierung).' : 'Kein Port-443-VHost in der generierten Konfiguration.',
                            $https ? CertificateStatus::VALID : CertificateStatus::HTTPS_UNAVAILABLE, 'installation');
                    }
                }
            }
        }

        return new VerificationReport($local, $checks, $live, $path, $key);
    }
}
