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
                'Prüft das lokale und das live ausgelieferte Zertifikat einer ISPConfig-Website.',
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

            $localInfo = $report->certificateInfo;
            $liveInfo = $report->liveCertificateInfo;

            $io->title('ISPConfig Zertifikatsprüfung');

            $io->section('Lokales Zertifikat');

            $io->definitionList(
                ['Common Name' => $localInfo->commonName],
                ['Aussteller' => $localInfo->issuer],
                [
                    'Gültig ab' => $localInfo->validFrom->format('d.m.Y H:i:s'),
                ],
                [
                    'Gültig bis' => $localInfo->validUntil->format('d.m.Y H:i:s'),
                ],
                [
                    'Restlaufzeit' => \sprintf(
                        '%d Tage',
                        $localInfo->daysUntilExpiration(),
                    ),
                ],
                ['SHA-256' => $localInfo->fingerprint],
            );

            $io->section('Lokale Subject Alternative Names');

            if ([] === $localInfo->subjectAlternativeNames) {
                $io->warning(
                    'Das lokale Zertifikat enthält keine Subject Alternative Names.',
                );
            } else {
                $io->listing(
                    $localInfo->subjectAlternativeNames,
                );
            }

            if (null !== $liveInfo) {
                $io->section('Live-Zertifikat');

                $io->definitionList(
                    ['Common Name' => $liveInfo->commonName],
                    ['Aussteller' => $liveInfo->issuer],
                    [
                        'Gültig ab' => $liveInfo->validFrom->format('d.m.Y H:i:s'),
                    ],
                    [
                        'Gültig bis' => $liveInfo->validUntil->format('d.m.Y H:i:s'),
                    ],
                    [
                        'Restlaufzeit' => \sprintf(
                            '%d Tage',
                            $liveInfo->daysUntilExpiration(),
                        ),
                    ],
                    ['SHA-256' => $liveInfo->fingerprint],
                );

                $io->section('Live Subject Alternative Names');

                if ([] === $liveInfo->subjectAlternativeNames) {
                    $io->warning(
                        'Das live ausgelieferte Zertifikat enthält keine Subject Alternative Names.',
                    );
                } else {
                    $io->listing(
                        $liveInfo->subjectAlternativeNames,
                    );
                }
            }

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
                'Das lokale und das live ausgelieferte Zertifikat wurden erfolgreich geprüft.',
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
