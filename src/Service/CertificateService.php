<?php

namespace App\Service;

use App\Acme\AcmeManager;
use App\Certificate\CertificateFactory;
use App\Domain\Hostname;
use App\ISPConfig\WebsiteRepository;
use App\Process\ProcessResult;

final readonly class CertificateService
{
    public function __construct(
        private WebsiteRepository $websiteRepository,
        private CertificateFactory $certificateFactory,
        private AcmeManager $acmeManager,
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

    /**
     * @return array{
     *     plan: CertificatePlan,
     *     issueResult: ProcessResult,
     *     installResult: ProcessResult
     * }
     */
    public function issue(
        Hostname $hostname,
        bool $force = false,
    ): array {
        $plan = $this->preview($hostname);

        $issueResult = $this->acmeManager->issue(
            certificate: $plan->certificate,
            force: $force,
        );

        $installResult = $this->acmeManager->install(
            $plan->certificate,
        );

        return [
            'plan' => $plan,
            'issueResult' => $issueResult,
            'installResult' => $installResult,
        ];
    }

    /**
     * @param list<string> $command
     */
    public function formatCommand(array $command): string
    {
        return $this->acmeManager->formatCommand($command);
    }
}
