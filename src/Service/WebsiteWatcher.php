<?php

namespace App\Service;

use App\Domain\Hostname;
use App\ISPConfig\Website;
use App\ISPConfig\WebsiteRepository;

final readonly class WebsiteWatcher
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private RenewService $renewService,
    ) {
    }

    public function watchAll(
        int $renewBeforeDays = 30,
        bool $dryRun = false,
    ): WatchReport {
        $startedAt = microtime(true);
        $items = [];

        foreach ($this->websiteRepository->findAll() as $website) {
            $items[] = $this->processWebsite(
                website: $website,
                renewBeforeDays: $renewBeforeDays,
                dryRun: $dryRun,
            );
        }

        return new WatchReport(
            items: $items,
            duration: microtime(true) - $startedAt,
        );
    }

    public function watchDomain(
        Hostname $hostname,
        int $renewBeforeDays = 30,
        bool $dryRun = false,
    ): WatchReport {
        $startedAt = microtime(true);

        $website = $this->websiteRepository->findByDomain(
            $hostname,
        );

        return new WatchReport(
            items: [
                $this->processWebsite(
                    website: $website,
                    renewBeforeDays: $renewBeforeDays,
                    dryRun: $dryRun,
                ),
            ],
            duration: microtime(true) - $startedAt,
        );
    }

    private function processWebsite(
        Website $website,
        int $renewBeforeDays,
        bool $dryRun,
    ): WatchItemResult {
        try {
            $check = $this->renewService->checkWebsite(
                website: $website,
                renewBeforeDays: $renewBeforeDays,
            );

            if ('ssl-disabled' === $check->status) {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: 'ssl-disabled',
                    message: $check->message,
                );
            }

            if ('external-dns' === $check->status) {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: 'external-dns',
                    message: $check->message,
                );
            }

            if (!$check->renewalRequired) {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: false === $check->verificationReport?->successful() ? 'error' : 'valid',
                    message: $check->message,
                    failed: false === $check->verificationReport?->successful(),
                    verificationReport: $check->verificationReport,
                );
            }

            $missingCertificate = $this->isMissingCertificate(
                $check,
            );

            if ($dryRun) {
                return new WatchItemResult(
                    verificationReport: $check->verificationReport,
                    domain: $website->domain(),
                    status: 'reinstall' === $check->status ? 'reinstalled' : ($missingCertificate
                        ? 'issued'
                        : 'renewed'),
                    message: 'reinstall' === $check->status ? 'Vorhandenes Zertifikat würde installiert. '.$check->message : ($missingCertificate
                        ? \sprintf(
                            'Für "%s" würde ein neues Zertifikat ausgestellt.',
                            $website->domain(),
                        )
                        : \sprintf(
                            'Das Zertifikat würde erneuert: %s',
                            $check->message,
                        )),
                );
            }

            $result = $this->renewService->renewWebsite(
                website: $website,
                renewBeforeDays: $renewBeforeDays,
            );

            if (!$result->renewed) {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: 'valid',
                    message: $result->message,
                );
            }

            if (
                null !== $result->verificationReport
                && !$result->verificationReport->successful()
            ) {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: 'error',
                    message: 'Das Zertifikat wurde verarbeitet, aber die abschließende lokale Prüfung ist fehlgeschlagen.',
                    changed: true,
                    failed: true,
                    verificationReport: $result->verificationReport,
                );
            }

            return new WatchItemResult(
                domain: $website->domain(),
                status: $result->reinstalled ? 'reinstalled' : ($missingCertificate
                    ? 'issued'
                    : 'renewed'),
                message: $result->reinstalled ? $result->message : ($missingCertificate
                    ? 'Ein neues Zertifikat wurde erfolgreich ausgestellt, installiert und geprüft.'
                    : 'Das Zertifikat wurde erfolgreich erneuert, installiert und geprüft.'),
                changed: true,
                verificationReport: $result->verificationReport,
            );
        } catch (\Throwable $exception) {
            $report = $check->verificationReport ?? null;
            if ($report !== null && str_starts_with($exception->getMessage(), 'INSTALLATION_FAILED')) {
                $report = new VerificationReport($report->certificateInfo, [...$report->checks,
                    new VerificationCheck('Installation', false, $exception->getMessage(), \App\Certificate\CertificateStatus::INSTALLATION_FAILED, 'installation')], $report->liveCertificateInfo);
            }
            return new WatchItemResult(
                domain: $website->domain(),
                status: 'error',
                message: $exception->getMessage(),
                failed: true,
                verificationReport: $report,
            );
        }
    }

    private function isMissingCertificate(
        RenewCheckResult $check,
    ): bool {
        return 'CERTIFICATE_MISSING' === $check->status;
    }
}
