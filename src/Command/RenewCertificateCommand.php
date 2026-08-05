<?php

namespace App\Command;

use App\Console\OutputMode;
use App\Domain\Hostname;
use App\ISPConfig\ApiClient;
use App\ISPConfig\WebsiteRepository;
use App\Service\RenewService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RenewCertificateCommand extends Command
{
    public function __construct(
        private readonly RenewService $renewService,
        private readonly WebsiteRepository $websiteRepository,
        private readonly ApiClient $apiClient,
    ) {
        parent::__construct('renew');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Erneuert Zertifikate von ISPConfig-Websites bei Bedarf.',
            )
            ->addArgument(
                name: 'domain',
                mode: InputArgument::OPTIONAL,
                description: 'Domain der ISPConfig-Website',
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
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Erzwingt die Erneuerung.',
            )
            ->addOption(
                name: 'dry-run',
                mode: InputOption::VALUE_NONE,
                description: 'Prüft nur, welche Zertifikate erneuert würden.',
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
            $force = (bool) $input->getOption('force');
            $dryRun = (bool) $input->getOption('dry-run');
            $cron = (bool) $input->getOption('cron');
            $skipConfirmation = (bool) $input->getOption('yes');

            $outputMode = $cron
                ? OutputMode::Cron
                : OutputMode::Interactive;

            if (null !== $domain && !\is_string($domain)) {
                $io->error(
                    'Das Argument "domain" muss eine Zeichenkette sein.',
                );

                return Command::INVALID;
            }

            if (!\is_string($days) || !ctype_digit($days)) {
                $io->error(
                    'Die Option "--days" muss eine nicht negative ganze Zahl sein.',
                );

                return Command::INVALID;
            }

            if ($all && \is_string($domain) && '' !== trim($domain)) {
                $io->error(
                    'Eine Domain und die Option "--all" dürfen nicht gleichzeitig verwendet werden.',
                );

                return Command::INVALID;
            }

            if (!$all && (!\is_string($domain) || '' === trim($domain))) {
                $io->error(
                    'Gib eine Domain an oder verwende die Option "--all".',
                );

                return Command::INVALID;
            }

            if ($dryRun && !$all) {
                $io->error(
                    'Die Option "--dry-run" kann nur zusammen mit "--all" verwendet werden.',
                );

                return Command::INVALID;
            }

            if ($dryRun && $force) {
                $io->error(
                    'Die Optionen "--dry-run" und "--force" können nicht zusammen verwendet werden.',
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

            if ($all) {
                return $this->renewAll(
                    io: $io,
                    renewBeforeDays: $renewBeforeDays,
                    force: $force,
                    skipConfirmation: $skipConfirmation,
                    dryRun: $dryRun,
                    outputMode: $outputMode,
                );
            }

            return $this->renewDomain(
                io: $io,
                domain: trim((string) $domain),
                renewBeforeDays: $renewBeforeDays,
                force: $force,
                skipConfirmation: $skipConfirmation,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->apiClient->logoutSilently();
        }
    }

    private function renewDomain(
        SymfonyStyle $io,
        string $domain,
        int $renewBeforeDays,
        bool $force,
        bool $skipConfirmation,
    ): int {
        $io->title('ISPConfig Zertifikat erneuern');

        $io->definitionList(
            ['Domain' => $domain],
            [
                'Erneuerungsgrenze' => \sprintf(
                    '%d Tage',
                    $renewBeforeDays,
                ),
            ],
            ['Erzwungen' => $force ? 'ja' : 'nein'],
        );

        if (
            !$skipConfirmation
            && !$io->confirm(
                'Zertifikat jetzt prüfen und bei Bedarf erneuern?',
                true,
            )
        ) {
            $io->warning('Vorgang wurde abgebrochen.');

            return Command::SUCCESS;
        }

        $result = $this->renewService->renew(
            hostname: new Hostname($domain),
            renewBeforeDays: $renewBeforeDays,
            force: $force,
        );

        if (!$result->renewed) {
            $io->success($result->message);

            return Command::SUCCESS;
        }

        $io->writeln(\sprintf(
            '<info>✔</info> %s',
            $result->message,
        ));

        if (
            null !== $result->verificationReport
            && !$result->verificationReport->successful()
        ) {
            $io->error(
                'Das Zertifikat wurde verarbeitet, aber die abschließende Prüfung ist fehlgeschlagen.',
            );

            return Command::FAILURE;
        }

        $certificateInfo = $result
            ->verificationReport
            ?->certificateInfo;

        $messages = [
            'Das Zertifikat wurde erfolgreich erneuert und geprüft.',
        ];

        if (null !== $certificateInfo) {
            $messages[] = \sprintf(
                'Gültig bis: %s',
                $certificateInfo->validUntil->format('d.m.Y H:i:s'),
            );

            $messages[] = \sprintf(
                'Restlaufzeit: %d Tage',
                $certificateInfo->daysUntilExpiration(),
            );
        }

        $io->success($messages);

        return Command::SUCCESS;
    }

    private function renewAll(
        SymfonyStyle $io,
        int $renewBeforeDays,
        bool $force,
        bool $skipConfirmation,
        bool $dryRun,
        OutputMode $outputMode,
    ): int {
        $startedAt = microtime(true);
        $websites = $this->websiteRepository->findAll();

        if (OutputMode::Interactive === $outputMode) {
            $io->title(
                $dryRun
                    ? 'Alle ISPConfig-Zertifikate prüfen – Dry Run'
                    : 'Alle ISPConfig-Zertifikate erneuern',
            );

            $io->definitionList(
                ['Aktive Websites' => (string) \count($websites)],
                [
                    'Erneuerungsgrenze' => \sprintf(
                        '%d Tage',
                        $renewBeforeDays,
                    ),
                ],
                ['Erzwungen' => $force ? 'ja' : 'nein'],
                ['Dry Run' => $dryRun ? 'ja' : 'nein'],
                ['Cron-Modus' => 'nein'],
            );
        }

        if ([] === $websites) {
            if (OutputMode::Interactive === $outputMode) {
                $io->warning(
                    'Es wurden keine aktiven Websites gefunden.',
                );
            } else {
                $io->writeln(
                    '[OK] Keine aktiven ISPConfig-Websites gefunden.',
                );
            }

            return Command::SUCCESS;
        }

        if (
            OutputMode::Interactive === $outputMode
            && !$dryRun
            && !$skipConfirmation
            && !$io->confirm(
                'Alle aktiven Websites jetzt prüfen und bei Bedarf erneuern?',
                false,
            )
        ) {
            $io->warning('Vorgang wurde abgebrochen.');

            return Command::SUCCESS;
        }

        /**
         * @var list<array{
         *     domain: string,
         *     status: string,
         *     message: string,
         *     priority: int
         * }> $results
         */
        $results = [];

        $valid = 0;
        $wouldRenew = 0;
        $renewed = 0;
        $sslDisabled = 0;
        $externalDns = 0;
        $failed = 0;

        foreach ($websites as $website) {
            try {
                $check = $this->renewService->checkWebsite(
                    website: $website,
                    renewBeforeDays: $renewBeforeDays,
                );

                if ('external-dns' === $check->status) {
                    ++$externalDns;

                    if (OutputMode::Interactive === $outputMode) {
                        $results[] = [
                            'domain' => $website->domain(),
                            'status' => '<comment>⏭ externes DNS</comment>',
                            'message' => $check->message,
                            'priority' => 30,
                        ];
                    }

                    continue;
                }

                if ('ssl-disabled' === $check->status) {
                    ++$sslDisabled;

                    if (OutputMode::Interactive === $outputMode) {
                        $results[] = [
                            'domain' => $website->domain(),
                            'status' => '<comment>⏭ SSL deaktiviert</comment>',
                            'message' => $check->message,
                            'priority' => 30,
                        ];
                    }

                    continue;
                }

                if ($dryRun) {
                    if ($check->renewalRequired) {
                        ++$wouldRenew;

                        $results[] = [
                            'domain' => $website->domain(),
                            'status' => '<fg=yellow>⚠ würde erneuert</>',
                            'message' => $check->message,
                            'priority' => 10,
                        ];

                        continue;
                    }

                    ++$valid;

                    $results[] = [
                        'domain' => $website->domain(),
                        'status' => '<info>✔ gültig</info>',
                        'message' => $check->message,
                        'priority' => 40,
                    ];

                    continue;
                }

                $result = $this->renewService->renewWebsite(
                    website: $website,
                    renewBeforeDays: $renewBeforeDays,
                    force: $force,
                );

                if ($result->renewed) {
                    ++$renewed;

                    if (OutputMode::Cron === $outputMode) {
                        $io->writeln(\sprintf(
                            '[RENEWED] %s: %s',
                            $website->domain(),
                            $result->message,
                        ));
                    } else {
                        $results[] = [
                            'domain' => $website->domain(),
                            'status' => '<info>✔ erneuert</info>',
                            'message' => $result->message,
                            'priority' => 20,
                        ];
                    }

                    continue;
                }

                ++$valid;

                if (OutputMode::Interactive === $outputMode) {
                    $results[] = [
                        'domain' => $website->domain(),
                        'status' => '<info>✔ gültig</info>',
                        'message' => $result->message,
                        'priority' => 40,
                    ];
                }
            } catch (\Throwable $exception) {
                ++$failed;

                if (OutputMode::Cron === $outputMode) {
                    $io->writeln(\sprintf(
                        '<error>[ERROR] %s: %s</error>',
                        $website->domain(),
                        $exception->getMessage(),
                    ));
                } else {
                    $results[] = [
                        'domain' => $website->domain(),
                        'status' => '<error>✖ Fehler</error>',
                        'message' => $exception->getMessage(),
                        'priority' => 0,
                    ];
                }
            }
        }

        $duration = microtime(true) - $startedAt;

        if (OutputMode::Cron === $outputMode) {
            return $this->writeCronSummary(
                io: $io,
                checked: \count($websites),
                valid: $valid,
                renewed: $renewed,
                sslDisabled: $sslDisabled,
                externalDns: $externalDns,
                failed: $failed,
                duration: $duration,
            );
        }

        usort(
            $results,
            static function (array $left, array $right): int {
                $priorityComparison =
                    $left['priority'] <=> $right['priority'];

                if (0 !== $priorityComparison) {
                    return $priorityComparison;
                }

                return strcasecmp(
                    $left['domain'],
                    $right['domain'],
                );
            },
        );

        $rows = array_map(
            static fn (array $result): array => [
                $result['domain'],
                $result['status'],
                $result['message'],
            ],
            $results,
        );

        $io->table(
            ['Domain', 'Status', 'Ergebnis'],
            $rows,
        );

        if ($dryRun) {
            $io->section('Zusammenfassung');

            $io->definitionList(
                ['Geprüft' => (string) \count($websites)],
                ['Gültig' => (string) $valid],
                ['Würden erneuert' => (string) $wouldRenew],
                ['SSL deaktiviert' => (string) $sslDisabled],
                ['Externes DNS' => (string) $externalDns],
                ['Prüffehler' => (string) $failed],
                ['Laufzeit' => $this->formatDuration($duration)],
            );

            if ($failed > 0) {
                $io->error(
                    'Einige Websites konnten im Dry Run nicht geprüft werden.',
                );

                return Command::FAILURE;
            }

            if ($wouldRenew > 0) {
                $io->note([
                    \sprintf(
                        '%d Zertifikat(e) würden erneuert werden.',
                        $wouldRenew,
                    ),
                    'Empfohlener nächster Befehl:',
                    'php bin/ispconfig-dns-cert renew --all --yes',
                ]);
            } else {
                $io->success(
                    'Alle verwalteten Zertifikate sind noch ausreichend lange gültig.',
                );
            }

            $io->success(
                'Dry Run abgeschlossen. Es wurden keine Änderungen vorgenommen.',
            );

            return Command::SUCCESS;
        }

        $io->section('Zusammenfassung');

        $io->definitionList(
            ['Geprüft' => (string) \count($websites)],
            ['Erneuert' => (string) $renewed],
            ['Noch gültig' => (string) $valid],
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

        $io->success(
            'Alle verwalteten Websites wurden erfolgreich geprüft.',
        );

        return Command::SUCCESS;
    }

    private function writeCronSummary(
        SymfonyStyle $io,
        int $checked,
        int $valid,
        int $renewed,
        int $sslDisabled,
        int $externalDns,
        int $failed,
        float $duration,
    ): int {
        $io->newLine();

        $io->writeln(\sprintf(
            '[SUMMARY] checked=%d valid=%d renewed=%d ssl_disabled=%d external_dns=%d failed=%d duration=%s',
            $checked,
            $valid,
            $renewed,
            $sslDisabled,
            $externalDns,
            $failed,
            $this->formatDuration($duration),
        ));

        if ($failed > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
