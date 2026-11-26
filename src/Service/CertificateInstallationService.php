<?php

namespace App\Service;

use App\Certificate\Certificate;
use App\Config\Configuration;
use App\ISPConfig\Website;
use App\ISPConfig\WebsiteUpdater;

final readonly class CertificateInstallationService
{
    public function __construct(private WebsiteUpdater $updater, private CertificateVerificationService $verification, private Configuration $configuration, private OperationGuard $guard)
    {
    }

    public function install(Website $website, Certificate $certificate, bool $acmeSource = true): VerificationReport
    {
        $this->verification->assertLocal($website);
        $source = $this->verification->verifyWebsite($website, false, $acmeSource);
        if (!$acmeSource && ($source->localCertificatePath !== $certificate->certificatePath || $source->localKeyPath !== $certificate->keyPath)) {
            throw new \RuntimeException('INSTALLATION_FAILED: Installationsquelle entspricht nicht dem geprüften VHost-Dateipaar.');
        }
        if (!$source->scopeSuccessful('local') || null === $source->certificateInfo) {
            throw new \RuntimeException('INSTALLATION_FAILED: ACME-Zertifikat nicht vollständig validiert. '.$source->summary());
        }

        $expectedFingerprint = $source->certificateInfo->fingerprint;

        return $this->guard->run($website, 'install-'.$expectedFingerprint, function () use ($website, $certificate, $acmeSource, $expectedFingerprint): VerificationReport {
            $started = time();
            $baseline = $this->errorArtifacts($website);
            $this->updater->saveCertificate($website, $certificate, $acmeSource);
            $deadline = microtime(true) + $this->configuration->installationTimeout;
            do {
                $report = $this->verification->verifyWebsite($website, true, $acmeSource, deadline: $deadline);
                $errors = [];
                foreach ($this->errorArtifacts($website) as $path => $metadata) {
                    if ($metadata['mtime'] >= $started && ($baseline[$path] ?? null) !== $metadata) {
                        $errors[] = basename($path);
                    }
                }
                if ($report->successful() && [] === $errors && $report->certificateInfo?->fingerprint === $expectedFingerprint) {
                    return $report;
                }
                if ([] !== $errors) {
                    throw new \RuntimeException('INSTALLATION_FAILED: Neue ISPConfig-Fehlerartefakte: '.implode(', ', $errors));
                }
                if (microtime(true) >= $deadline) {
                    break;
                }
                usleep((int) (min($this->configuration->pollInterval, max(0, $deadline - microtime(true))) * 1000000));
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('INSTALLATION_FAILED: Installation innerhalb des Zeitlimits nicht bestätigt. '.$report->summary());
        });
    }

    /** @return array<string, array{mtime: int, size: int, digest: string}> */
    private function errorArtifacts(Website $website): array
    {
        $paths = [$this->configuration->vhostDirectory.'/'.$website->domain().'.vhost.err',
            $website->certificatePath().'.err', $website->keyPath().'.err'];
        $errors = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            if (is_readable($path) && is_file($path)) {
                $mtime = filemtime($path);
                $size = filesize($path);
                $digest = hash_file('sha256', $path);
                if (false !== $mtime && false !== $size && false !== $digest) {
                    $errors[$path] = ['mtime' => $mtime, 'size' => $size, 'digest' => $digest];
                }
            }
        }

        return $errors;
    }
}
