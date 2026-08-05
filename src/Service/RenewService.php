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

        try {
            $verificationReport = $this->verificationService->verifyWebsite(
                $website,
            );
        } catch (\Throwable $exception) {
            return new RenewCheckResult(
                renewalRequired: true,
                status: 'invalid',
                message: \sprintf(
                    'Zertifikat konnte nicht geprüft werden: %s',
                    $exception->getMessage(),
                ),
            );
        }

        $remainingDays = $verificationReport
            ->certificateInfo
            ->daysUntilExpiration();

        if (!$verificationReport->successful()) {
            return new RenewCheckResult(
                renewalRequired: true,
                status: 'invalid',
                message: 'Mindestens eine Zertifikatsprüfung ist fehlgeschlagen.',
                remainingDays: $remainingDays,
                verificationReport: $verificationReport,
            );
        }

        if ($remainingDays <= $renewBeforeDays) {
            return new RenewCheckResult(
                renewalRequired: true,
                status: 'expiring',
                message: \sprintf(
                    'Nur noch %d Tage gültig. Erneuerung erforderlich.',
                    $remainingDays,
                ),
                remainingDays: $remainingDays,
                verificationReport: $verificationReport,
            );
        }

        return new RenewCheckResult(
            renewalRequired: false,
            status: 'valid',
            message: \sprintf(
                'Keine Erneuerung erforderlich. Noch %d Tage gültig.',
                $remainingDays,
            ),
            remainingDays: $remainingDays,
            verificationReport: $verificationReport,
        );
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

        if (!$checkResult->renewalRequired) {
            return new RenewResult(
                renewed: false,
                message: $checkResult->message,
                verificationReport: $checkResult->verificationReport,
            );
        }

        $issueResult = $this->certificateService->issueWebsite(
            website: $website,
            force: false,
        );

        return new RenewResult(
            renewed: true,
            message: $checkResult->message,
            issueResult: $issueResult,
            verificationReport: $issueResult->verificationReport,
        );
    }

    private function validateRenewBeforeDays(int $renewBeforeDays): void
    {
        if ($renewBeforeDays < 0) {
            throw new \InvalidArgumentException('Die Anzahl der Tage darf nicht negativ sein.');
        }
    }
}
