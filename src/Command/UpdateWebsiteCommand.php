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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateWebsiteCommand extends Command
{
    public function __construct(
        private readonly WebsiteRepository $websiteRepository,
        private readonly CertificateFactory $certificateFactory,
        private readonly WebsiteUpdater $websiteUpdater,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct('update');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Speichert ein vorhandenes ACME-Zertifikat über die ISPConfig-API.',
            )
            ->addArgument(
                name: 'domain',
                mode: InputArgument::REQUIRED,
                description: 'Domain der ISPConfig-Website',
            )
            ->addOption(
                name: 'yes',
                shortcut: 'y',
                mode: InputOption::VALUE_NONE,
                description: 'Überspringt die Sicherheitsabfrage.',
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
            $parameters = $this->websiteUpdater
                ->buildCertificateParameters($certificate);

            /*
             * Ermittelt und verifiziert die Client-ID bereits vor der
             * Sicherheitsabfrage. Dabei wird noch nichts verändert.
             */
            $clientId = $this->websiteUpdater->resolveClientId($website);

            $io->title('ISPConfig Website-Zertifikat aktualisieren');

            $io->definitionList(
                ['Domain' => $website->domain()],
                ['Domain-ID' => (string) $website->id],
                ['Client-ID' => (string) $clientId],
                ['Systemgruppe' => $website->systemGroup],
                ['Systemgruppen-ID' => (string) $website->systemGroupId],
                ['ssl' => $parameters['ssl']],
                ['ssl_letsencrypt' => $parameters['ssl_letsencrypt']],
                ['ssl_action' => $parameters['ssl_action']],
                [
                    'Zertifikat' => \sprintf(
                        '%d Bytes',
                        \strlen($parameters['ssl_cert']),
                    ),
                ],
                [
                    'Bundle' => \sprintf(
                        '%d Bytes',
                        \strlen($parameters['ssl_bundle']),
                    ),
                ],
                [
                    'Privater Schlüssel' => \sprintf(
                        '%d Bytes',
                        \strlen($parameters['ssl_key']),
                    ),
                ],
            );

            if (
                !(bool) $input->getOption('yes')
                && !$io->confirm(
                    'Diese Daten jetzt über die ISPConfig-API speichern?',
                    false,
                )
            ) {
                $io->warning('Vorgang wurde abgebrochen.');

                return Command::SUCCESS;
            }

            $result = $this->websiteUpdater->saveCertificate(
                website: $website,
                certificate: $certificate,
            );

            $resultDescription = match (true) {
                \is_bool($result) => $result ? 'true' : 'false',
                \is_int($result) => (string) $result,
                \is_string($result) => $result,
                \is_array($result) => 'Array-Antwort',
                default => get_debug_type($result),
            };

            $io->success([
                'ISPConfig hat das Website-Update angenommen.',
                \sprintf('API-Antwort: %s', $resultDescription),
                'Die Zertifikatsinhalte wurden nicht ausgegeben.',
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->apiClient->logoutSilently();
        }
    }
}
