<?php

namespace App\Command;

use App\Domain\Hostname;
use App\ISPConfig\ApiClient;
use App\Service\CertificateVerificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class VerifyCertificateCommand extends Command
{
    public function __construct(
        private readonly CertificateVerificationService $verificationService,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct('verify');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Prüft das installierte Zertifikat einer ISPConfig-Website.',
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

            $report = $this->verificationService->verify(
                new Hostname($domain),
            );

            $info = $report->certificateInfo;

            $io->title('ISPConfig Zertifikatsprüfung');

            $io->section('Zertifikatsinformationen');

            $io->definitionList(
                ['Common Name' => $info->commonName],
                ['Aussteller' => $info->issuer],
                [
                    'Gültig ab' => $info->validFrom->format('d.m.Y H:i:s'),
                ],
                [
                    'Gültig bis' => $info->validUntil->format('d.m.Y H:i:s'),
                ],
                [
                    'Restlaufzeit' => \sprintf('%d Tage', $info->daysUntilExpiration()),
                ],
                ['SHA-256' => $info->fingerprint],
            );

            $io->section('Subject Alternative Names');
            $io->listing($info->subjectAlternativeNames);

            $io->section('Prüfungen');

            foreach ($report->checks as $check) {
                $io->writeln(\sprintf(
                    '%s <comment>%s</comment>: %s',
                    $check->successful
                        ? '<info>✔</info>'
                        : '<error>✘</error>',
                    $check->name,
                    $check->message,
                ));
            }

            if (!$report->successful()) {
                $io->error(
                    'Mindestens eine Zertifikatsprüfung ist fehlgeschlagen.',
                );

                return Command::FAILURE;
            }

            $io->success(
                'Das lokale Zertifikat wurde erfolgreich geprüft.',
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
