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
                'Stellt ein DNS-01-Zertifikat aus und installiert es.',
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
                description: 'Erzwingt eine neue Ausstellung.',
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
                [
                    'Hostnamen' => implode(', ', $plan->certificate->hostnameValues()),
                ],
                [
                    'Wildcard' => $plan->certificate->containsWildcard() ? 'ja' : 'nein',
                ],
                [
                    'Ziel-Key' => $plan->certificate->keyPath,
                ],
                [
                    'Ziel-Zertifikat' => $plan->certificate->certificatePath,
                ],
                [
                    'Erzwungene Ausstellung' => $force ? 'ja' : 'nein',
                ],
            );

            if (
                !$skipConfirmation
                && !$io->confirm(
                    'Zertifikat jetzt ausstellen und installieren?',
                    false,
                )
            ) {
                $io->warning('Vorgang wurde abgebrochen.');

                return Command::SUCCESS;
            }

            $io->section('Zertifikat ausstellen');

            $result = $this->certificateService->issue(
                hostname: $hostname,
                force: $force,
            );

            $certificate = $result['plan']->certificate;

            if (!is_file($certificate->keyPath)) {
                throw new \RuntimeException(\sprintf('Der installierte Schlüssel wurde nicht gefunden: %s', $certificate->keyPath));
            }

            if (!is_file($certificate->certificatePath)) {
                throw new \RuntimeException(\sprintf('Das installierte Zertifikat wurde nicht gefunden: %s', $certificate->certificatePath));
            }

            $io->success([
                'Zertifikat wurde ausgestellt und installiert.',
                \sprintf('Key: %s', $certificate->keyPath),
                \sprintf(
                    'Zertifikat: %s',
                    $certificate->certificatePath,
                ),
            ]);

            if ($result['plan']->website->letsEncryptEnabled) {
                $io->warning(
                    'ISPConfig Let’s Encrypt ist für diese Website noch aktiviert. '
                    .'Das deaktivieren wir im nächsten Schritt automatisch über die API.',
                );
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->apiClient->logout();
        }
    }
}
