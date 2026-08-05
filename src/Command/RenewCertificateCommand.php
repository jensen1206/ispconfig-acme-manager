<?php

namespace App\Command;

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
            $skipConfirmation = (bool) $input->getOption('yes');

            if (null !== $domain && !\is_string($domain)) {
                $io->error(
                    'Das Argument "domain" muss eine Zeichenkette sein.',
                );

                return Command::INVALID;
            }

            if (!\is_string($days) || !ctype_digit($days)) {
                $io->error(
                    'Die Option "--days" muss eine positive ganze Zahl sein.',
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
                    'Die Option "--dry-run" kann derzeit nur zusammen mit "--all" verwendet werden.',
                );

                return Command::INVALID;
            }

            if ($dryRun && $force) {
                $io->error(
                    'Die Optionen "--dry-run" und "--force" können nicht zusammen verwendet werden.',
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
            $this->apiClient->logout();
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
                'Erneuerungsgrenze' => \sprintf('%d Tage', $renewBeforeDays),
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
    ): int {
        $websites = $this->websiteRepository->findAll();

        $io->title(
            $dryRun
                ? 'Alle ISPConfig-Zertifikate prüfen – Dry Run'
                : 'Alle ISPConfig-Zertifikate erneuern',
        );

        $io->definitionList(
            ['Aktive Websites' => (string) \count($websites)],
            [
                'Erneuerungsgrenze' => \sprintf('%d Tage', $renewBeforeDays),
            ],
            ['Erzwungen' => $force ? 'ja' : 'nein'],
            ['Dry Run' => $dryRun ? 'ja' : 'nein'],
        );

        if ([] === $websites) {
            $io->warning(
                'Es wurden keine aktiven Websites gefunden.',
            );

            return Command::SUCCESS;
        }

        if (
            !$dryRun
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
                if ($dryRun) {
                    $check = $this->renewService->checkWebsite(
                        website: $website,
                        renewBeforeDays: $renewBeforeDays,
                    );

                    if ('external-dns' === $check->status) {
                        ++$externalDns;

                        $results[] = [
                            'domain' => $website->domain(),
                            'status' => '<comment>⏭ externes DNS</comment>',
                            'message' => $check->message,
                            'priority' => 30,
                        ];

                        continue;
                    }

                    if ('ssl-disabled' === $check->status) {
                        ++$sslDisabled;

                        $results[] = [
                            'domain' => $website->domain(),
                            'status' => '<comment>⏭ SSL deaktiviert</comment>',
                            'message' => $check->message,
                            'priority' => 30,
                        ];

                        continue;
                    }

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

                $check = $this->renewService->checkWebsite(
                    website: $website,
                    renewBeforeDays: $renewBeforeDays,
                );

                if ('external-dns' === $check->status) {
                    ++$externalDns;

                    $results[] = [
                        'domain' => $website->domain(),
                        'status' => '<comment>⏭ externes DNS</comment>',
                        'message' => $check->message,
                        'priority' => 30,
                    ];

                    continue;
                }

                if ('ssl-disabled' === $check->status) {
                    ++$sslDisabled;

                    $results[] = [
                        'domain' => $website->domain(),
                        'status' => '<comment>⏭ SSL deaktiviert</comment>',
                        'message' => $check->message,
                        'priority' => 30,
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

                    $results[] = [
                        'domain' => $website->domain(),
                        'status' => '<info>✔ erneuert</info>',
                        'message' => $result->message,
                        'priority' => 20,
                    ];

                    continue;
                }

                ++$valid;

                $results[] = [
                    'domain' => $website->domain(),
                    'status' => '<info>✔ gültig</info>',
                    'message' => $result->message,
                    'priority' => 40,
                ];
            } catch (\Throwable $exception) {
                ++$failed;

                $results[] = [
                    'domain' => $website->domain(),
                    'status' => '<error>✖ Fehler</error>',
                    'message' => $exception->getMessage(),
                    'priority' => 0,
                ];
            }
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
}
