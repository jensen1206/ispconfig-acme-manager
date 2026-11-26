<?php

namespace App\Command;

use App\Console\OutputMode;
use App\Domain\Hostname;
use App\ISPConfig\ApiClient;
use App\Service\WatchReport;
use App\Service\WebsiteWatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class WatchCommand extends Command
{
    public function __construct(
        private readonly WebsiteWatcher $websiteWatcher,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct('watch');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Prüft Websites und stellt fehlende oder ablaufende Zertifikate automatisch aus.',
            )
            ->addArgument(
                name: 'domain',
                mode: InputArgument::OPTIONAL,
                description: 'Domain einer einzelnen ISPConfig-Website',
            )
            ->addOption(
                name: 'all',
                mode: InputOption::VALUE_NONE,
                description: 'Prüft alle aktiven ISPConfig-Websites.',
            )
            ->addOption(
                name: 'days',
                shortcut: 'd',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Erneuern, wenn höchstens so viele Tage verbleiben.',
                default: '30',
            )
            ->addOption(
                name: 'dry-run',
                mode: InputOption::VALUE_NONE,
                description: 'Zeigt nur an, welche Änderungen durchgeführt würden.',
            )
            ->addOption(
                name: 'cron',
                mode: InputOption::VALUE_NONE,
                description: 'Nicht interaktiver Modus für Cronjobs.',
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
            $all = (bool) $input->getOption('all');
            $days = $input->getOption('days');
            $dryRun = (bool) $input->getOption('dry-run');
            $cron = (bool) $input->getOption('cron');
            $skipConfirmation = (bool) $input->getOption('yes');

            if (null !== $domain && !\is_string($domain)) {
                $io->error(
                    'Das Argument "domain" muss eine Zeichenkette sein.',
                );

                return Command::INVALID;
            }

            $normalizedDomain = \is_string($domain)
                ? trim($domain)
                : '';

            if (!\is_string($days) || !ctype_digit($days)) {
                $io->error(
                    'Die Option "--days" muss eine nicht negative ganze Zahl sein.',
                );

                return Command::INVALID;
            }

            if ($all && '' !== $normalizedDomain) {
                $io->error(
                    'Eine Domain und die Option "--all" dürfen nicht gleichzeitig verwendet werden.',
                );

                return Command::INVALID;
            }

            if (!$all && '' === $normalizedDomain) {
                $io->error(
                    'Gib eine Domain an oder verwende die Option "--all".',
                );

                return Command::INVALID;
            }

            if ($cron && !$all) {
                $io->error(
                    'Die Option "--cron" kann nur zusammen mit "--all" verwendet werden.',
                );

                return Command::INVALID;
            }

            if ($cron && $dryRun) {
                $io->error(
                    'Die Optionen "--cron" und "--dry-run" können nicht zusammen verwendet werden.',
                );

                return Command::INVALID;
            }

            if ($cron && $skipConfirmation) {
                $io->error(
                    'Die Option "--yes" ist im Cron-Modus nicht erforderlich.',
                );

                return Command::INVALID;
            }

            $renewBeforeDays = (int) $days;

            $outputMode = $cron
                ? OutputMode::Cron
                : OutputMode::Interactive;

            if (OutputMode::Interactive === $outputMode) {
                $this->writeHeader(
                    io: $io,
                    domain: $normalizedDomain,
                    all: $all,
                    renewBeforeDays: $renewBeforeDays,
                    dryRun: $dryRun,
                );

                if (
                    !$dryRun
                    && !$skipConfirmation
                    && !$io->confirm(
                        $all
                            ? 'Alle aktiven Websites prüfen und Zertifikate bei Bedarf ausstellen oder erneuern?'
                            : \sprintf(
                                'Die Website "%s" jetzt prüfen und das Zertifikat bei Bedarf ausstellen oder erneuern?',
                                $normalizedDomain,
                            ),
                        false,
                    )
                ) {
                    $io->warning('Vorgang wurde abgebrochen.');

                    return Command::SUCCESS;
                }
            }

            $report = $all
                ? $this->websiteWatcher->watchAll(
                    renewBeforeDays: $renewBeforeDays,
                    dryRun: $dryRun,
                )
                : $this->websiteWatcher->watchDomain(
                    hostname: new Hostname($normalizedDomain),
                    renewBeforeDays: $renewBeforeDays,
                    dryRun: $dryRun,
                );

            if (OutputMode::Cron === $outputMode) {
                return $this->writeCronOutput(
                    io: $io,
                    report: $report,
                );
            }

            return $this->writeInteractiveOutput(
                io: $io,
                report: $report,
                dryRun: $dryRun,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->apiClient->logoutSilently();
        }
    }

    private function writeHeader(
        SymfonyStyle $io,
        string $domain,
        bool $all,
        int $renewBeforeDays,
        bool $dryRun,
    ): void {
        $io->title(
            $dryRun
                ? 'ISPConfig Certificate Watcher – Dry Run'
                : 'ISPConfig Certificate Watcher',
        );

        $io->definitionList(
            [
                'Auswahl' => $all
                    ? 'Alle Websites'
                    : $domain,
            ],
            [
                'Erneuerungsgrenze' => \sprintf(
                    '%d Tage',
                    $renewBeforeDays,
                ),
            ],
            ['Dry Run' => $dryRun ? 'ja' : 'nein'],
        );
    }

    private function writeInteractiveOutput(
        SymfonyStyle $io,
        WatchReport $report,
        bool $dryRun,
    ): int {
        $rows = [];

        foreach ($report->items as $item) {
            $rows[] = [
                'domain' => $item->domain,
                'status' => $this->formatStatus(
                    status: $item->status,
                    dryRun: $dryRun,
                ),
                'message' => $item->message,
                'local' => $item->verificationReport?->status('local')->value ?? '-',
                'public' => $item->verificationReport?->status('public')->value ?? '-',
                'installation' => $item->verificationReport?->status('installation')->value ?? '-',
                'priority' => $this->statusPriority(
                    $item->status,
                ),
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $priorityComparison =
                    $left['priority'] <=> $right['priority'];

                if (0 !== $priorityComparison) {
                    return $priorityComparison;
                }

                return strcasecmp(
                    (string) $left['domain'],
                    (string) $right['domain'],
                );
            },
        );

        $io->table(
            ['Domain', 'Lokal', 'Öffentlich', 'Installation', 'Aktion/Status', 'Ergebnis'],
            array_map(
                static fn (array $row): array => [
                    $row['domain'],
                    $row['local'],
                    $row['public'],
                    $row['installation'],
                    $row['status'],
                    $row['message'],
                ],
                $rows,
            ),
        );

        $io->section('Zusammenfassung');

        $io->definitionList(
            ['Geprüft' => (string) $report->checkedCount()],
            ['Gültig' => (string) $report->validCount()],
            [
                $dryRun
                    ? 'Würden ausgestellt'
                    : 'Neu ausgestellt' => (string) $report->issuedCount(),
            ],
            [
                $dryRun
                    ? 'Würden erneuert'
                    : 'Erneuert' => (string) $report->renewedCount(),
            ],
            [
                'SSL deaktiviert' => (string) $report->sslDisabledCount(),
            ],
            [
                'Externes DNS' => (string) $report->externalDnsCount(),
            ],
            ['Fehler' => (string) $report->failedCount()],
            ['Laufzeit' => $this->formatDuration($report->duration)],
        );

        if (!$report->successful()) {
            $io->error(\sprintf(
                '%d Website(s) konnten nicht verarbeitet werden.',
                $report->failedCount(),
            ));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->success(
                'Dry Run abgeschlossen. Es wurden keine Änderungen vorgenommen.',
            );

            return Command::SUCCESS;
        }

        $io->success(
            'Certificate Watcher erfolgreich abgeschlossen.',
        );

        return Command::SUCCESS;
    }

    private function writeCronOutput(
        SymfonyStyle $io,
        WatchReport $report,
    ): int {
        foreach ($report->items as $item) {
            if ('reinstalled' === $item->status) {
                $io->writeln('[REINSTALLED] '.$item->domain.': '.$item->message);
                continue;
            }
            if ('issued' === $item->status) {
                $io->writeln(\sprintf(
                    '[ISSUED] %s: %s',
                    $item->domain,
                    $item->message,
                ));

                continue;
            }

            if ('renewed' === $item->status) {
                $io->writeln(\sprintf(
                    '[RENEWED] %s: %s',
                    $item->domain,
                    $item->message,
                ));

                continue;
            }

            if ($item->failed) {
                $io->writeln(\sprintf(
                    '<error>[ERROR] %s: %s</error>',
                    $item->domain,
                    $item->message,
                ));
            }
        }

        $io->writeln(\sprintf(
            '[SUMMARY] checked=%d valid=%d issued=%d renewed=%d ssl_disabled=%d external_dns=%d failed=%d duration=%s',
            $report->checkedCount(),
            $report->validCount(),
            $report->issuedCount(),
            $report->renewedCount(),
            $report->sslDisabledCount(),
            $report->externalDnsCount(),
            $report->failedCount(),
            $this->formatDuration($report->duration),
        ));

        return $report->successful()
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function formatStatus(
        string $status,
        bool $dryRun,
    ): string {
        return match ($status) {
            'reinstalled' => $dryRun ? '<comment>würde erneut installiert</comment>' : '<info>erneut installiert</info>',
            'valid' => '<info>✔ gültig</info>',
            'issued' => $dryRun
                ? '<fg=yellow>⚠ würde ausgestellt</>'
                : '<info>✔ ausgestellt</info>',
            'renewed' => $dryRun
                ? '<fg=yellow>⚠ würde erneuert</>'
                : '<info>✔ erneuert</info>',
            'ssl-disabled' => '<comment>⏭ SSL deaktiviert</comment>',
            'external-dns' => '<comment>⏭ externes DNS</comment>',
            'error' => '<error>✖ Fehler</error>',
            default => '<comment>unbekannt</comment>',
        };
    }

    private function statusPriority(string $status): int
    {
        return match ($status) {
            'error' => 0,
            'issued', 'renewed' => 10,
            'external-dns', 'ssl-disabled' => 20,
            'valid' => 30,
            default => 40,
        };
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return \sprintf('%.2f s', $seconds);
        }

        $minutes = intdiv((int) $seconds, 60);
        $remainingSeconds = (int) $seconds % 60;

        return \sprintf(
            '%d min %d s',
            $minutes,
            $remainingSeconds,
        );
    }
}
