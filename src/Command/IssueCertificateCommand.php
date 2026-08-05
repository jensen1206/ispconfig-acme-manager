<?php

namespace App\Command;

use App\Domain\Hostname;
use App\ISPConfig\ApiClient;
use App\Service\CertificateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class IssueCertificateCommand extends Command
{
    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct('issue');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Stellt ein DNS-01-Zertifikat aus, installiert und verifiziert es.',
            )
            ->addArgument(
                name: 'domain',
                mode: InputArgument::REQUIRED,
                description: 'Domain der ISPConfig-Website',
            )
            ->addOption(
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Erzwingt eine neue Ausstellung über acme.sh.',
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

            $hostname = new Hostname($domain);
            $force = (bool) $input->getOption('force');
            $skipConfirmation = (bool) $input->getOption('yes');

            $plan = $this->certificateService->preview($hostname);

            $io->title('ISPConfig DNS-Zertifikat ausstellen');

            $io->definitionList(
                ['Domain' => $plan->website->domain()],
                ['Domain-ID' => (string) $plan->website->id],
                ['Client-Gruppe' => $plan->website->systemGroup],
                [
                    'Hostnamen' => implode(', ', $plan->certificate->hostnameValues()),
                ],
                [
                    'Wildcard' => $plan->certificate->containsWildcard() ? 'ja' : 'nein',
                ],
                ['Ziel-Key' => $plan->certificate->keyPath],
                ['Ziel-Zertifikat' => $plan->certificate->certificatePath],
                [
                    'ISPConfig Let’s Encrypt aktuell' => $plan->website->letsEncryptEnabled ? 'ja' : 'nein',
                ],
                ['Erzwungene Ausstellung' => $force ? 'ja' : 'nein'],
            );

            $io->warning([
                'Das Zertifikat wird per DNS-01 ausgestellt.',
                'Danach wird die Website über die ISPConfig Remote API aktualisiert.',
                'ISPConfig Let’s Encrypt wird dabei deaktiviert.',
            ]);

            if (
                !$skipConfirmation
                && !$io->confirm(
                    'Zertifikat jetzt ausstellen und Website aktualisieren?',
                    false,
                )
            ) {
                $io->warning('Vorgang wurde abgebrochen.');

                return Command::SUCCESS;
            }

            $io->section('1. Zertifikat ausstellen und installieren');

            $result = $this->certificateService->issue(
                hostname: $hostname,
                force: $force,
            );

            $io->section('2. ISPConfig aktualisieren');

            $updateDescription = match (true) {
                \is_bool($result->updateResult) => $result->updateResult ? 'true' : 'false',

                \is_int($result->updateResult) => (string) $result->updateResult,

                \is_string($result->updateResult) => $result->updateResult,

                \is_array($result->updateResult) => 'Array-Antwort',

                default => get_debug_type($result->updateResult),
            };

            $io->writeln(\sprintf(
                '<info>✔</info> ISPConfig API-Antwort: %s',
                $updateDescription,
            ));

            $io->section('3. Lokale Zertifikatsprüfung');

            foreach ($result->verificationReport->checks as $check) {
                $io->writeln(\sprintf(
                    '%s <comment>%s</comment>: %s',
                    $check->successful
                        ? '<info>✔</info>'
                        : '<error>✘</error>',
                    $check->name,
                    $check->message,
                ));
            }

            if (!$result->verificationReport->successful()) {
                $io->error(
                    'Das Zertifikat wurde installiert, aber mindestens eine Prüfung ist fehlgeschlagen.',
                );

                return Command::FAILURE;
            }

            $certificate = $result->plan->certificate;
            $certificateInfo = $result->verificationReport->certificateInfo;

            $io->success([
                'Zertifikat wurde erfolgreich ausgestellt, installiert und geprüft.',
                \sprintf('Domain: %s', $certificate->domain()),
                \sprintf('Key: %s', $certificate->keyPath),
                \sprintf(
                    'Zertifikat: %s',
                    $certificate->certificatePath,
                ),
                \sprintf(
                    'Gültig bis: %s',
                    $certificateInfo->validUntil->format('d.m.Y H:i:s'),
                ),
                \sprintf(
                    'Restlaufzeit: %d Tage',
                    $certificateInfo->daysUntilExpiration(),
                ),
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
