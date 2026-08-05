<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Hostname;
use App\ISPConfig\Website;
use App\ISPConfig\WebsiteRepository;
use Throwable;

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

            if ($check->status === 'ssl-disabled') {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: 'ssl-disabled',
                    message: $check->message,
                );
            }

            if ($check->status === 'external-dns') {
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

            $missingCertificate = $this->isMissingCertificate(
                $check,
            );

            if ($dryRun) {
                return new WatchItemResult(
                    domain: $website->domain(),
                    status: $missingCertificate
                        ? 'issued'
                        : 'renewed',
                    message: $missingCertificate
                        ? sprintf(
                            'Für "%s" würde ein neues Zertifikat ausgestellt.',
                            $website->domain(),
                        )
                        : sprintf(
                            'Das Zertifikat würde erneuert: %s',
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
                $result->verificationReport !== null
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
                status: $missingCertificate
                    ? 'issued'
                    : 'renewed',
                message: $missingCertificate
                    ? 'Ein neues Zertifikat wurde erfolgreich ausgestellt, installiert und geprüft.'
                    : 'Das Zertifikat wurde erfolgreich erneuert, installiert und geprüft.',
                changed: true,
            );
        } catch (Throwable $exception) {
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
        if ($check->status !== 'invalid-local-certificate') {
            return false;
        }

        $message = mb_strtolower($check->message);

        return str_contains($message, 'datei wurde nicht gefunden')
            || str_contains($message, 'zertifikat wurde nicht gefunden')
            || str_contains(
                $message,
                'zertifikatsdatei wurde nicht gefunden',
            );
    }
}
