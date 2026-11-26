<?php

namespace App\Service;

use App\Config\Configuration;
use App\ISPConfig\Website;

final readonly class OperationGuard
{
    public function __construct(private Configuration $configuration)
    {
    }

    /** @template T
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(Website $website, string $action, callable $operation): mixed
    {
        $directory = $this->configuration->stateDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Aktionsstatus kann nicht gespeichert werden.');
        }
        $path = $directory.'/'.$website->serverId.'-'.$website->id;
        $lock = fopen($path.'.lock', 'c+');
        if (false === $lock) {
            throw new \RuntimeException('Sperrdatei nicht verfügbar.');
        }
        try {
            if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
                throw new \RuntimeException('Vorgang für diese Website läuft bereits.');
            }
            $statePath = $path.'-'.hash('sha256', $action).'.attempt';
            $last = is_file($statePath) ? file_get_contents($statePath) : false;
            if (\is_string($last) && time() - (int) $last < $this->configuration->retryCooldown) {
                throw new \RuntimeException('Wiederholung gesperrt (retry_cooldown); keine erneute Ausstellung/Installation.');
            }
            // Persist before starting, including failures and process termination.
            if (false === file_put_contents($statePath, (string) time(), \LOCK_EX)) {
                throw new \RuntimeException('Aktionsstatus konnte nicht gesichert werden.');
            }

            return $operation();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
