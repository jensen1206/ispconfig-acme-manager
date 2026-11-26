<?php

namespace App\Command;

use App\Config\Configuration;
use App\Service\DashboardCertificateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RenewDashboardCertificateCommand extends Command
{
    public function __construct(private readonly DashboardCertificateService $service, private readonly Configuration $configuration)
    {
        parent::__construct('dashboard:renew');
    }

    protected function configure(): void
    {
        $this->setDescription('Erneuert und verifiziert das TLS-Zertifikat des ISPConfig-Dashboards.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Erzwingt eine neue Ausstellung über acme.sh.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Überspringt die Sicherheitsabfrage.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $certificate = $this->service->certificate();
            $force = (bool) $input->getOption('force');
            $io->title('ISPConfig Dashboard-Zertifikat erneuern');
            $io->definitionList(
                ['Dashboard' => \sprintf('https://%s:%d/', $certificate->domain(), $this->configuration->dashboardPort())],
                ['Ziel-Key' => $certificate->keyPath], ['Ziel-Zertifikat' => $certificate->certificatePath],
                ['Reload' => 'Konfigurierter Dashboard-Reload'], ['Erzwungene Ausstellung' => $force ? 'ja' : 'nein'],
            );
            if (!(bool) $input->getOption('yes') && !$io->confirm('Dashboard-Zertifikat auf diesem Server jetzt erneuern?', false)) {
                $io->warning('Vorgang wurde abgebrochen.');

                return Command::SUCCESS;
            }
            $result = $this->service->renew($force);
            $io->success([
                $result->issued ? 'Dashboard-Zertifikat neu ausgestellt, installiert und live verifiziert.' : 'Vorhandenes Dashboard-Zertifikat installiert und live verifiziert.',
                'Gültig bis: '.$result->certificateInfo->validUntil->format('d.m.Y H:i:s'),
                'Restlaufzeit: '.$result->certificateInfo->daysUntilExpiration().' Tage',
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
