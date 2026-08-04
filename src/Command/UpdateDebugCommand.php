<?php

namespace App\Command;

use App\Certificate\CertificateFactory;
use App\Domain\Hostname;
use App\ISPConfig\ApiClient;
use App\ISPConfig\WebsiteRepository;
use App\ISPConfig\WebsiteUpdater;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateDebugCommand extends Command
{
    public function __construct(
        private readonly WebsiteRepository $websiteRepository,
        private readonly CertificateFactory $certificateFactory,
        private readonly WebsiteUpdater $websiteUpdater,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct('update-debug');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Zeigt den geplanten ISPConfig-Update-Payload an, ohne Änderungen zu speichern.',
            )
            ->addArgument(
                name: 'domain',
                mode: InputArgument::REQUIRED,
                description: 'Domain der ISPConfig-Website',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        try {
            $domain = $input->getArgument('domain');

            if (!\is_string($domain)) {
                $io->error(
                    'Das Argument "domain" muss eine Zeichenkette sein.',
                );

                return Command::INVALID;
            }

            $website = $this->websiteRepository->findByDomain(
                new Hostname($domain),
            );

            $certificate = $this->certificateFactory->fromWebsite($website);

            $clientId = $this->websiteUpdater->resolveClientId($website);

            $parameters = $this->websiteUpdater
                ->buildCertificateParameters($certificate);

            $io->title('ISPConfig Website-Update – Debug');

            $io->section('API-Zuordnung');

            $io->definitionList(
                ['Domain' => $website->domain()],
                ['Domain-ID' => (string) $website->id],
                ['Client-ID' => (string) $clientId],
                ['Systemgruppe' => $website->systemGroup],
                ['Systemgruppen-ID' => (string) $website->systemGroupId],
                ['Server-ID' => (string) $website->serverId],
            );

            $io->section('API-Methode');

            $io->definitionList(
                ['Methode' => 'sites_web_domain_update'],
                ['client_id' => (string) $clientId],
                ['primary_id' => (string) $website->id],
            );

            $io->section('Update-Parameter');

            $rows = [];

            foreach ($parameters as $name => $value) {
                $displayValue = match ($name) {
                    'ssl_cert',
                    'ssl_bundle',
                    'ssl_key' => \sprintf(
                        '%d Bytes',
                        \strlen($value),
                    ),
                    default => $value,
                };

                $rows[] = [$name, $displayValue];
            }

            $io->table(
                ['Feld', 'Wert'],
                $rows,
            );

            $io->section('Zertifikatsdateien');

            $io->definitionList(
                [
                    'Zertifikat' => $certificate->acmeCertificatePath(),
                ],
                [
                    'Bundle' => $certificate->acmeBundlePath(),
                ],
                [
                    'Privater Schlüssel' => $certificate->acmeKeyPath(),
                ],
            );

            $io->warning([
                'Es wurde kein API-Update ausgeführt.',
                'Zertifikat und privater Schlüssel wurden nicht ausgegeben.',
            ]);

            $io->success(
                'Der ISPConfig-Update-Payload konnte vollständig vorbereitet werden.',
            );

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->apiClient->logout();
        }
    }
}
