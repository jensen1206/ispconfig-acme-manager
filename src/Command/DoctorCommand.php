<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DoctorService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class DoctorCommand extends Command
{
    public function __construct(
        private readonly DoctorService $doctorService,
    ) {
        parent::__construct('doctor');
    }

    protected function configure(): void
    {
        $this->setDescription(
            'Prüft die Server-, ISPConfig- und ACME-Konfiguration.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        try {
            $io->title('ISPConfig DNS Certificate Manager – Diagnose');

            $checks = $this->doctorService->run();
            $hasErrors = false;

            foreach ($checks as $check) {
                if ($check->successful) {
                    $io->writeln(sprintf(
                        '<info>✔</info> <comment>%s</comment>: %s',
                        $check->name,
                        $check->message,
                    ));

                    continue;
                }

                $hasErrors = true;

                $io->writeln(sprintf(
                    '<error>✘</error> <comment>%s</comment>: %s',
                    $check->name,
                    $check->message,
                ));
            }

            if ($hasErrors) {
                $io->error(
                    'Mindestens eine Prüfung ist fehlgeschlagen.',
                );

                return Command::FAILURE;
            }

            $io->success(
                'Alle Prüfungen wurden erfolgreich abgeschlossen.',
            );

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}