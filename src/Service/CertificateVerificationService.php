<?php

namespace App\Service;

use App\Certificate\CertificateFactory;
use App\Certificate\CertificateReader;
use App\Domain\Hostname;
use App\ISPConfig\WebsiteRepository;

final readonly class CertificateVerificationService
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private CertificateFactory $certificateFactory,
        private CertificateReader $certificateReader,
    ) {
    }

    public function verify(Hostname $hostname): VerificationReport
    {
        $website = $this->websiteRepository->findByDomain($hostname);
        $certificate = $this->certificateFactory->fromWebsite($website);

        $certificatePath = $certificate->certificatePath;
        $keyPath = $certificate->keyPath;

        if (!is_file($certificatePath)) {
            $legacyCertificatePath = \sprintf(
                '%s/ssl/%s-le.crt',
                rtrim($website->documentRoot, '/'),
                $website->domain(),
            );

            if (is_file($legacyCertificatePath)) {
                $certificatePath = $legacyCertificatePath;
            }
        }

        if (!is_file($keyPath)) {
            $legacyKeyPath = \sprintf(
                '%s/ssl/%s-le.key',
                rtrim($website->documentRoot, '/'),
                $website->domain(),
            );

            if (is_file($legacyKeyPath)) {
                $keyPath = $legacyKeyPath;
            }
        }

        $info = $this->certificateReader->read($certificatePath);

        $checks = [];

        $checks[] = new VerificationCheck(
            name: 'Zertifikatsdatei',
            successful: is_file($certificatePath),
            message: $certificatePath,
        );

        $checks[] = new VerificationCheck(
            name: 'Privater Schlüssel',
            successful: is_file($keyPath),
            message: $keyPath,
        );

        $checks[] = new VerificationCheck(
            name: 'Gültigkeitszeitraum',
            successful: $info->isCurrentlyValid(),
            message: \sprintf(
                '%s bis %s (%d Tage verbleibend)',
                $info->validFrom->format('d.m.Y H:i:s'),
                $info->validUntil->format('d.m.Y H:i:s'),
                $info->daysUntilExpiration(),
            ),
        );

        $missingHostnames = array_values(array_diff(
            $certificate->hostnameValues(),
            $info->subjectAlternativeNames,
        ));

        $checks[] = new VerificationCheck(
            name: 'Zertifikatsnamen',
            successful: [] === $missingHostnames,
            message: [] === $missingHostnames
                ? implode(', ', $info->subjectAlternativeNames)
                : \sprintf(
                    'Fehlend: %s',
                    implode(', ', $missingHostnames),
                ),
        );

        $keyMatches = $this->certificateReader->keyMatchesCertificate(
            certificatePath: $certificatePath,
            keyPath: $keyPath,
        );

        $checks[] = new VerificationCheck(
            name: 'Schlüsselpaar',
            successful: $keyMatches,
            message: $keyMatches
                ? 'Privater Schlüssel passt zum Zertifikat.'
                : 'Privater Schlüssel passt nicht zum Zertifikat.',
        );

        return new VerificationReport(
            certificateInfo: $info,
            checks: $checks,
        );
    }
}
