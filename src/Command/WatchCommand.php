<?php

namespace App\Command;

use App\Console\OutputMode;
use App\ISPConfig\ApiClient;
use App\Service\WatchItemResult;
use App\Service\WebsiteWatcher;
use Symfony\Component\Console\Command\Command;
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
                'Überwacht alle ISPConfig-Websites und stellt fehlende oder ablaufende Zertifikate automatisch aus.',
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
            $days = $input->getOption('days');
            $dryRun = (bool) $input->getOption('dry-run');
            $cron = (bool) $input->getOption('cron');
            $skipConfirmation = (bool) $input->getOption('yes');

            if (!\is_string($days) || !ctype_digit($days)) {
                $io->error(
                    'Die Option "--days" muss eine nicht negative ganze Zahl sein.',
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
                $io->title(
                    $dryRun
                        ? 'ISPConfig Certificate Watcher – Dry Run'
                        : 'ISPConfig Certificate Watcher',
                );

                $io->definitionList(
                    [
                        'Erneuerungsgrenze' => \sprintf(
                            '%d Tage',
                            $renewBeforeDays,
                        ),
                    ],
                    ['Dry Run' => $dryRun ? 'ja' : 'nein'],
                    ['Cron-Modus' => 'nein'],
                );

                if (
                    !$dryRun
                    && !$skipConfirmation
                    && !$io->confirm(
                        'Alle aktiven Websites prüfen und Zertifikate bei Bedarf ausstellen oder erneuern?',
                        false,
                    )
                ) {
                    $io->warning('Vorgang wurde abgebrochen.');

                    return Command::SUCCESS;
                }
            }

            $report = $this->websiteWatcher->watch(
                renewBeforeDays: $renewBeforeDays,
                dryRun: $dryRun,
            );

            if (OutputMode::Cron === $outputMode) {
                return $this->writeCronOutput(
                    io: $io,
                    items: $report->items,
                    checked: $report->checkedCount(),
                    valid: $report->validCount(),
                    issued: $report->issuedCount(),
                    renewed: $report->renewedCount(),
                    sslDisabled: $report->sslDisabledCount(),
                    externalDns: $report->externalDnsCount(),
                    failed: $report->failedCount(),
                    duration: $report->duration,
                );
            }

            return $this->writeInteractiveOutput(
                io: $io,
                items: $report->items,
                checked: $report->checkedCount(),
                valid: $report->validCount(),
                issued: $report->issuedCount(),
                renewed: $report->renewedCount(),
                sslDisabled: $report->sslDisabledCount(),
                externalDns: $report->externalDnsCount(),
                failed: $report->failedCount(),
                duration: $report->duration,
                dryRun: $dryRun,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->apiClient->logoutSilently();
        }
    }

    /**
     * @param list<WatchItemResult> $items
     */
    private function writeInteractiveOutput(
        SymfonyStyle $io,
        array $items,
        int $checked,
        int $valid,
        int $issued,
        int $renewed,
        int $sslDisabled,
        int $externalDns,
        int $failed,
        float $duration,
        bool $dryRun,
    ): int {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                $item->domain,
                $this->formatStatus(
                    status: $item->status,
                    dryRun: $dryRun,
                ),
                $item->message,
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                return strcmp(
                    self::stripConsoleFormatting((string) $left[1]),
                    self::stripConsoleFormatting((string) $right[1]),
                );
            },
        );

        $io->table(
            ['Domain', 'Status', 'Ergebnis'],
            $rows,
        );

        $io->section('Zusammenfassung');

        $io->definitionList(
            ['Geprüft' => (string) $checked],
            ['Gültig' => (string) $valid],
            [
                $dryRun
                    ? 'Würden ausgestellt'
                    : 'Neu ausgestellt' => (string) $issued,
            ],
            [
                $dryRun
                    ? 'Würden erneuert'
                    : 'Erneuert' => (string) $renewed,
            ],
            ['SSL deaktiviert' => (string) $sslDisabled],
            ['Externes DNS' => (string) $externalDns],
            ['Fehler' => (string) $failed],
            ['Laufzeit' => $this->formatDuration($duration)],
        );

        if ($failed > 0) {
            $io->error(\sprintf(
                '%d Website(s) konnten nicht verarbeitet werden.',
                $failed,
            ));

            return Command::FAILURE;
        }

        if ($dryRun) {
            if ($issued > 0 || $renewed > 0) {
                $io->note([
                    \sprintf(
                        '%d Zertifikat(e) würden neu ausgestellt.',
                        $issued,
                    ),
                    \sprintf(
                        '%d Zertifikat(e) würden erneuert.',
                        $renewed,
                    ),
                    'Es wurden keine Änderungen vorgenommen.',
                ]);
            } else {
                $io->success(
                    'Alle verwalteten Websites sind bereits vollständig versorgt.',
                );
            }

            return Command::SUCCESS;
        }

        if (0 === $issued && 0 === $renewed) {
            $io->success(
                'Alle verwalteten Websites besitzen ein gültiges Zertifikat.',
            );

            return Command::SUCCESS;
        }

        $io->success([
            'Certificate Watcher erfolgreich abgeschlossen.',
            \sprintf(
                '%d Zertifikat(e) neu ausgestellt.',
                $issued,
            ),
            \sprintf(
                '%d Zertifikat(e) erneuert.',
                $renewed,
            ),
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param list<WatchItemResult> $items
     */
    private function writeCronOutput(
        SymfonyStyle $io,
        array $items,
        int $checked,
        int $valid,
        int $issued,
        int $renewed,
        int $sslDisabled,
        int $externalDns,
        int $failed,
        float $duration,
    ): int {
        foreach ($items as $item) {
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
            $checked,
            $valid,
            $issued,
            $renewed,
            $sslDisabled,
            $externalDns,
            $failed,
            $this->formatDuration($duration),
        ));

        return $failed > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    private function formatStatus(
        string $status,
        bool $dryRun,
    ): string {
        return match ($status) {
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

    private static function stripConsoleFormatting(
        string $value,
    ): string {
        return (string) preg_replace(
            '/<[^>]+>/',
            '',
            $value,
        );
    }
}
