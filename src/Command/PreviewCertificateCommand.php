<?php

namespace App\Command;

use App\Domain\Hostname;
use App\ISPConfig\ApiClient;
use App\Service\CertificateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PreviewCertificateCommand extends Command
{
    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct('preview');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Zeigt die geplante DNS-01-Ausstellung und Installation an.',
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
                $io->error('Das Argument "domain" muss eine Zeichenkette sein.');

                return Command::INVALID;
            }

            $hostname = new Hostname($domain);

            $plan = $this->certificateService->preview($hostname);
            $website = $plan->website;
            $certificate = $plan->certificate;

            $io->title('ISPConfig DNS-Zertifikat – Vorschau');

            $io->section('Website');

            $io->definitionList(
                ['ID' => (string) $website->id],
                ['Domain' => $website->domain()],
                ['DocumentRoot' => $website->documentRoot],
                ['System-User' => $website->systemUser],
                ['System-Gruppe' => $website->systemGroup],
                ['Server-ID' => (string) $website->serverId],
                ['SSL' => $website->sslEnabled ? 'ja' : 'nein'],
                [
                    'ISPConfig Let’s Encrypt' => $website->letsEncryptEnabled ? 'ja' : 'nein',
                ],
            );

            $io->section('Zertifikat');

            $io->definitionList(
                ['Primärer Hostname' => $certificate->domain()],
                [
                    'Wildcard' => $certificate->containsWildcard() ? 'ja' : 'nein',
                ],
                ['Schlüsseltyp' => $certificate->keyType->value],
                [
                    'Zertifizierungsstelle' => $certificate->certificateAuthority->value,
                ],
                [
                    'Challenge' => $certificate->challengeType->value,
                ],
                ['ACME-Verzeichnis' => $certificate->acmeDirectory],
                ['Ziel-Key' => $certificate->keyPath],
                ['Ziel-Zertifikat' => $certificate->certificatePath],
            );

            $io->section('Zertifikatsnamen');
            $io->listing($certificate->hostnameValues());

            $io->section('Ausstellungsbefehl');
            $io->writeln(
                $this->certificateService->formatCommand(
                    $plan->issueCommand,
                ),
            );

            $io->section('Installationsbefehl');
            $io->writeln(
                $this->certificateService->formatCommand(
                    $plan->installCommand,
                ),
            );

            $io->success(
                'Vorschau abgeschlossen. Es wurden keine Änderungen vorgenommen.',
            );

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->apiClient->logoutSilently();
        }
    }
}
