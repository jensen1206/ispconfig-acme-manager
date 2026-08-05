<?php

namespace App\Service;

use App\Certificate\CertificateFactory;
use App\Certificate\CertificateReader;
use App\Certificate\LiveCertificateReader;
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
    ) {
    }

    public function verify(Hostname $hostname): VerificationReport
    {
        return $this->verifyWebsite(
            $this->websiteRepository->findByDomain($hostname),
        );
    }

    public function verifyWebsite(Website $website): VerificationReport
    {
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
        $liveInfo = $this->liveCertificateReader->read(
            $website->domain(),
        );

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

        $checks[] = new VerificationCheck(
            name: 'Live-Zertifikat erreichbar',
            successful: $liveInfo->isCurrentlyValid(),
            message: \sprintf(
                '%s bis %s',
                $liveInfo->validFrom->format('d.m.Y H:i:s'),
                $liveInfo->validUntil->format('d.m.Y H:i:s'),
            ),
        );

        $fingerprintsMatch = hash_equals(
            $info->fingerprint,
            $liveInfo->fingerprint,
        );

        $checks[] = new VerificationCheck(
            name: 'Live-Fingerprint',
            successful: $fingerprintsMatch,
            message: $fingerprintsMatch
                ? 'Lokales und ausgeliefertes Zertifikat sind identisch.'
                : \sprintf(
                    'Abweichung: lokal %s, live %s',
                    $info->fingerprint,
                    $liveInfo->fingerprint,
                ),
        );

        $checks[] = new VerificationCheck(
            name: 'Live-Zertifikatsnamen',
            successful: $this->sameStringSet(
                $info->subjectAlternativeNames,
                $liveInfo->subjectAlternativeNames,
            ),
            message: implode(
                ', ',
                $liveInfo->subjectAlternativeNames,
            ),
        );

        $checks[] = new VerificationCheck(
            name: 'Live-Aussteller',
            successful: $info->issuer === $liveInfo->issuer,
            message: $liveInfo->issuer,
        );

        $checks[] = new VerificationCheck(
            name: 'Live-Ablaufdatum',
            successful: $info->validUntil->getTimestamp()
            === $liveInfo->validUntil->getTimestamp(),
            message: $liveInfo->validUntil->format('d.m.Y H:i:s'),
        );

        return new VerificationReport(
            certificateInfo: $info,
            checks: $checks,
            liveCertificateInfo: $liveInfo,
        );
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sameStringSet(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }
}
