<?php

namespace App\Service;

use App\ISPConfig\Website;
use App\ISPConfig\WebsiteRepository;

final readonly class WebsiteWatcher
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private RenewService $renewService,
    ) {
    }

    public function watch(
        int $renewBeforeDays = 30,
        bool $dryRun = false,
    ): WatchReport {
        $startedAt = microtime(true);
        $items = [];

        foreach ($this->websiteRepository->findAll() as $website) {
            $items[] = $this->watchWebsite(
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

    private function watchWebsite(
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
                    status: 'valid',
                    message: $check->message,
                );
            }

            $isMissingCertificate = $this->isMissingCertificate($check);

            if ($dryRun) {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: $isMissingCertificate
                        ? 'issued'
                        : 'renewed',
                    message: $isMissingCertificate
                        ? \sprintf(
                            'Zertifikat fehlt. Für "%s" würde ein neues Zertifikat ausgestellt.',
                            $website->domain(),
                        )
                        : \sprintf(
                            'Das Zertifikat von "%s" würde erneuert: %s',
                            $website->domain(),
                            $check->message,
                        ),
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
                );
            }

            return new WatchItemResult(
                domain: $website->domain(),
                status: $isMissingCertificate
                    ? 'issued'
                    : 'renewed',
                message: $isMissingCertificate
                    ? 'Ein neues Zertifikat wurde erfolgreich ausgestellt, installiert und geprüft.'
                    : 'Das Zertifikat wurde erfolgreich erneuert, installiert und geprüft.',
                changed: true,
            );
        } catch (\Throwable $exception) {
            return new WatchItemResult(
                domain: $website->domain(),
                status: 'error',
                message: $exception->getMessage(),
                failed: true,
            );
        }
    }

    private function isMissingCertificate(
        RenewCheckResult $check,
    ): bool {
        if ('invalid-local-certificate' !== $check->status) {
            return false;
        }

        $message = mb_strtolower($check->message);

        return str_contains($message, 'datei wurde nicht gefunden')
            || str_contains($message, 'zertifikat wurde nicht gefunden')
            || str_contains($message, 'zertifikatsdatei wurde nicht gefunden');
    }
}
