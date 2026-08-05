<?php

namespace App\Service;

use App\Acme\AcmeManager;
use App\Certificate\CertificateFactory;
use App\Dns\NameserverChecker;
use App\Domain\Hostname;
use App\ISPConfig\Website;
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
        private NameserverChecker $nameserverChecker,
    ) {
    }

    public function preview(Hostname $hostname): CertificatePlan
    {
        return $this->previewWebsite(
            $this->websiteRepository->findByDomain($hostname),
        );
    }

    public function previewWebsite(Website $website): CertificatePlan
    {
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
        return $this->issueWebsite(
            website: $this->websiteRepository->findByDomain($hostname),
            force: $force,
        );
    }

    public function issueWebsite(
        Website $website,
        bool $force = false,
    ): CertificateIssueResult {
        $this->nameserverChecker->assertManaged(
            $website->primaryHostname,
        );

        $plan = $this->previewWebsite($website);

        $issueResult = $this->acmeManager->issue(
            certificate: $plan->certificate,
            force: $force,
        );

        $installResult = $this->acmeManager->install(
            certificate: $plan->certificate,
        );

        $updateResult = $this->websiteUpdater->saveCertificate(
            website: $plan->website,
            certificate: $plan->certificate,
        );

        /*
         * Direkt nach Installation und Apache-Reload wird nur der lokale
         * Zertifikatszustand geprüft.
         *
         * Eine sofortige Live-TLS-Prüfung kann kurzzeitig fehlschlagen,
         * obwohl das Zertifikat korrekt installiert wurde.
         */
        $verificationReport = $this->verificationService->verifyWebsite(
            website: $plan->website,
            includeLiveCertificate: false,
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
