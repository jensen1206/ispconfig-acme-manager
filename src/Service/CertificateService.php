<?php

namespace App\Service;

use App\Acme\AcmeManager;
use App\Certificate\CertificateFactory;
use App\Domain\Hostname;
use App\ISPConfig\WebsiteRepository;
use App\ISPConfig\WebsiteUpdater;

final readonly class CertificateService
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private CertificateFactory $certificateFactory,
        private AcmeManager $acmeManager,
        private WebsiteUpdater $websiteUpdater,
        private CertificateVerificationService $verificationService,
    ) {
    }

    public function preview(Hostname $hostname): CertificatePlan
    {
        $website = $this->websiteRepository->findByDomain($hostname);
        $certificate = $this->certificateFactory->fromWebsite($website);

        return new CertificatePlan(
            website: $website,
            certificate: $certificate,
            issueCommand: $this->acmeManager->issueCommand($certificate),
            installCommand: $this->acmeManager->installCommand($certificate),
        );
    }

    public function issue(
        Hostname $hostname,
        bool $force = false,
    ): CertificateIssueResult {
        $plan = $this->preview($hostname);

        /*
         * 1. Zertifikat per DNS-01 ausstellen.
         */
        $issueResult = $this->acmeManager->issue(
            certificate: $plan->certificate,
            force: $force,
        );

        /*
         * 2. Zertifikat in den SSL-Pfad der Website installieren.
         */
        $installResult = $this->acmeManager->install(
            certificate: $plan->certificate,
        );

        /*
         * 3. Zertifikat über die offizielle Remote API in ISPConfig speichern.
         *
         * Dabei wird ssl_letsencrypt auf "n" gesetzt.
         */
        $updateResult = $this->websiteUpdater->saveCertificate(
            website: $plan->website,
            certificate: $plan->certificate,
        );

        /*
         * 4. Das installierte lokale Zertifikat prüfen.
         */
        $verificationReport = $this->verificationService->verify(
            hostname: $hostname,
        );

        return new CertificateIssueResult(
            plan: $plan,
            issueResult: $issueResult,
            installResult: $installResult,
            updateResult: $updateResult,
            verificationReport: $verificationReport,
        );
    }

    /**
     * @param list<string> $command
     */
    public function formatCommand(array $command): string
    {
        return $this->acmeManager->formatCommand($command);
    }
}
