<?php

namespace App\Service;

use App\Acme\AcmeManager;
use App\Certificate\CertificateFactory;
use App\Dns\NameserverChecker;
use App\Domain\Hostname;
use App\ISPConfig\Website;
use App\ISPConfig\WebsiteRepository;

final readonly class CertificateService
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private CertificateFactory $certificateFactory,
        private AcmeManager $acmeManager,

        private CertificateVerificationService $verificationService,
        private NameserverChecker $nameserverChecker,
        private CertificateInstallationService $installation,
        private OperationGuard $guard,
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
            installCommand: ['php', 'bin/ispconfig-dns-cert', 'update', $website->domain(), '--yes'],
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
        int $renewBeforeDays = 30,
    ): CertificateIssueResult {
        $this->verificationService->assertLocal($website);
        foreach ($website->hostnames as $hostname) {
            $this->nameserverChecker->assertManaged($hostname);
        }
        $plan = $this->previewWebsite($website);
        $source = $this->verificationService->verifyWebsite($website, false, true);
        if (!$force && \in_array($source->status('local'), [\App\Certificate\CertificateStatus::REVOCATION_UNKNOWN,
            \App\Certificate\CertificateStatus::CERTIFICATE_INVALID], true)) {
            throw new \RuntimeException('ACME-Bestand nicht sicher prüfbar; keine automatische Neuausstellung. '.$source->summary());
        }
        $issueResult = new \App\Process\ProcessResult(0, 'Vorhandenes Zertifikat verwendet.', '', skipped: true);
        if ($force || !$source->scopeSuccessful('local') || ($source->certificateInfo?->daysUntilExpiration() ?? 0) <= $renewBeforeDays) {
            $issueResult = $this->guard->run($website, 'issue', fn () => $this->acmeManager->issue($plan->certificate, $force));
        }
        $verificationReport = $this->installation->install($website, $plan->certificate);
        $installResult = new \App\Process\ProcessResult(0, 'Installation per ISPConfig bestätigt.', '');
        $updateResult = true;

        return new CertificateIssueResult(
            plan: $plan,
            issueResult: $issueResult,
            installResult: $installResult,
            updateResult: $updateResult,
            verificationReport: $verificationReport,
            issued: !$issueResult->skipped,
        );
    }

    public function reinstall(Website $website): VerificationReport
    {
        $this->verificationService->assertLocal($website);
        $certificate = $this->certificateFactory->fromWebsite($website);
        $local = $this->verificationService->verifyWebsite($website, false);
        $useLocal = $local->scopeSuccessful('local') && null !== $local->localCertificatePath && null !== $local->localKeyPath;
        if ($useLocal) {
            $certificate = new \App\Certificate\Certificate(
                $certificate->primaryHostname, $certificate->hostnames, $local->localKeyPath, $local->localCertificatePath,
                $certificate->acmeDirectory, $certificate->keyType, $certificate->certificateAuthority, $certificate->challengeType,
            );
        }

        return $this->installation->install($website, $certificate, !$useLocal);
    }

    /**
     * @param list<string> $command
     */
    public function formatCommand(array $command): string
    {
        return $this->acmeManager->formatCommand($command);
    }
}
