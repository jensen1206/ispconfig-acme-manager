<?php

namespace App\Service;

use App\Dns\NameserverChecker;
use App\Domain\Hostname;
use App\ISPConfig\Website;
use App\ISPConfig\WebsiteRepository;

final readonly class RenewService
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private CertificateService $certificateService,
        private CertificateVerificationService $verificationService,
        private NameserverChecker $nameserverChecker,
        private \App\Config\Configuration $configuration,
    ) {
    }

    public function check(
        Hostname $hostname,
        int $renewBeforeDays = 30,
    ): RenewCheckResult {
        return $this->checkWebsite(
            website: $this->websiteRepository->findByDomain($hostname),
            renewBeforeDays: $renewBeforeDays,
        );
    }

    public function checkWebsite(
        Website $website,
        int $renewBeforeDays = 30,
    ): RenewCheckResult {
        $this->validateRenewBeforeDays($renewBeforeDays);

        if (!$website->sslEnabled) {
            return new RenewCheckResult(
                renewalRequired: false,
                status: 'ssl-disabled',
                message: 'SSL ist in ISPConfig deaktiviert.',
            );
        }

        $nameserverCheck = $this->nameserverChecker->check(
            $website->primaryHostname,
        );

        if (!$nameserverCheck->managed) {
            return new RenewCheckResult(
                renewalRequired: false,
                status: 'external-dns',
                message: $nameserverCheck->message(),
            );
        }

        $report = $this->verificationService->verifyWebsite($website, true, false, $renewBeforeDays);
        $source = $this->verificationService->verifyWebsite($website, false, true, $renewBeforeDays);

        return RenewalPolicy::decide($report, $source, $renewBeforeDays, $this->configuration->automaticRepair);
    }

    public function renew(
        Hostname $hostname,
        int $renewBeforeDays = 30,
        bool $force = false,
    ): RenewResult {
        return $this->renewWebsite(
            website: $this->websiteRepository->findByDomain($hostname),
            renewBeforeDays: $renewBeforeDays,
            force: $force,
        );
    }

    public function renewWebsite(
        Website $website,
        int $renewBeforeDays = 30,
        bool $force = false,
    ): RenewResult {
        $this->validateRenewBeforeDays($renewBeforeDays);

        if ($force) {
            $issueResult = $this->certificateService->issueWebsite(
                website: $website,
                force: true,
            );

            return new RenewResult(
                renewed: true,
                message: 'Die Zertifikatserneuerung wurde erzwungen.',
                issueResult: $issueResult,
                verificationReport: $issueResult->verificationReport,
            );
        }

        $checkResult = $this->checkWebsite(
            website: $website,
            renewBeforeDays: $renewBeforeDays,
        );

        if ('reinstall' === $checkResult->status) {
            return new RenewResult(true, 'Vorhandenes Zertifikat erneut installiert. '.$checkResult->message,
                verificationReport: $this->certificateService->reinstall($website), reinstalled: true);
        }

        if (!$checkResult->renewalRequired) {
            if (null !== $checkResult->verificationReport && !$checkResult->verificationReport->successful()) {
                throw new \RuntimeException($checkResult->message);
            }

            return new RenewResult(
                renewed: false,
                message: $checkResult->message,
                verificationReport: $checkResult->verificationReport,
            );
        }

        /*
         * Ist das lokale Zertifikat defekt oder fehlt vollständig, darf
         * acme.sh nicht einfach das vorhandene Zertifikat überspringen.
         */
        $source = $this->verificationService->verifyWebsite($website, false, true);
        $forceIssue = \App\Certificate\CertificateStatus::REVOKED === $source->status('local');

        $issueResult = $this->certificateService->issueWebsite(
            website: $website,
            force: $forceIssue,
            renewBeforeDays: $renewBeforeDays,
        );

        return new RenewResult(
            renewed: true,
            message: ($issueResult->issued ? 'Neues Zertifikat ausgestellt. ' : 'Vorhandenes Zertifikat installiert; keine Neuausstellung. ').$checkResult->message,
            issueResult: $issueResult,
            verificationReport: $issueResult->verificationReport,
            reinstalled: !$issueResult->issued,
        );
    }

    private function validateRenewBeforeDays(int $renewBeforeDays): void
    {
        if ($renewBeforeDays < 0) {
            throw new \InvalidArgumentException('Die Anzahl der Tage darf nicht negativ sein.');
        }
    }
}
