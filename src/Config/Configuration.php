<?php

namespace App\Config;

final readonly class Configuration
{
    /**
     * @param list<string> $managedNameservers
     */
    public function __construct(
        public string $ispconfigServer,
        public string $ispconfigUsername,
        public string $ispconfigPassword,
        public string $acmeExecutable,
        public string $dnsProvider,
        public int $dnsSleep,
        public array $managedNameservers,
        public int $localServerId = 0,
        public int $installationTimeout = 180,
        public int $pollInterval = 5,
        public int $networkTimeout = 15,
        public int $retryCooldown = 86400,
        public string $stateDirectory = '/var/lib/ispconfig-acme-manager',
        public string $acmeHome = '/root/.acme.sh',
        public string $acmeConfigHome = '/root/.acme.sh',
        public string $caFile = '',
        public string $vhostDirectory = '/etc/apache2/sites-available',
        public bool $automaticRepair = false,
    ) {
        if ($localServerId < 0 || min($installationTimeout, $pollInterval, $networkTimeout, $retryCooldown) < 1) {
            throw new \InvalidArgumentException('Server-ID oder Zeitlimits ungültig.');
        }
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Konfigurationsdatei wurde nicht gefunden: %s', $path));
        }

        $config = require $path;

        if (!\is_array($config)) {
            throw new \RuntimeException('Die Konfigurationsdatei muss ein Array zurückgeben.');
        }

        $normalizedConfig = [];

        foreach ($config as $key => $value) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Die Konfigurationsdatei enthält einen ungültigen Schlüssel.');
            }

            $normalizedConfig[$key] = $value;
        }

        /* @var array<string, mixed> $normalizedConfig */

        return new self(
            ispconfigServer: self::requiredString($normalizedConfig, 'server'),
            ispconfigUsername: self::requiredString($normalizedConfig, 'username'),
            ispconfigPassword: self::requiredString($normalizedConfig, 'password'),
            acmeExecutable: self::requiredString($normalizedConfig, 'acme'),
            dnsProvider: self::requiredString($normalizedConfig, 'dns_provider'),
            dnsSleep: self::requiredInt($normalizedConfig, 'dns_sleep'),
            localServerId: self::requiredInt($normalizedConfig + ['local_server_id' => 0], 'local_server_id'),
            installationTimeout: self::requiredInt($normalizedConfig + ['installation_timeout' => 180], 'installation_timeout'),
            pollInterval: self::requiredInt($normalizedConfig + ['poll_interval' => 5], 'poll_interval'),
            networkTimeout: self::requiredInt($normalizedConfig + ['network_timeout' => 15], 'network_timeout'),
            retryCooldown: self::requiredInt($normalizedConfig + ['retry_cooldown' => 86400], 'retry_cooldown'),
            stateDirectory: self::requiredString($normalizedConfig + ['state_directory' => '/var/lib/ispconfig-acme-manager'], 'state_directory'),
            acmeHome: self::requiredString($normalizedConfig + ['acme_home' => '/root/.acme.sh'], 'acme_home'),
            acmeConfigHome: self::requiredString($normalizedConfig + ['acme_config_home' => '/root/.acme.sh'], 'acme_config_home'),
            caFile: \array_key_exists('ca_file', $normalizedConfig) ? self::requiredString($normalizedConfig, 'ca_file') : '',
            vhostDirectory: self::requiredString($normalizedConfig + ['vhost_directory' => '/etc/apache2/sites-available'], 'vhost_directory'),
            automaticRepair: ($normalizedConfig['automatic_repair'] ?? false) === true,
            managedNameservers: self::requiredStringList(
                $normalizedConfig,
                'managed_nameservers',
            ),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new \RuntimeException(\sprintf('Der Konfigurationswert "%s" fehlt oder ist ungültig.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredInt(array $config, string $key): int
    {
        $value = $config[$key] ?? null;

        if (!\is_int($value) || $value < 0) {
            throw new \RuntimeException(\sprintf('Der Konfigurationswert "%s" fehlt oder ist ungültig.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function requiredStringList(
        array $config,
        string $key,
    ): array {
        $value = $config[$key] ?? null;

        if (!\is_array($value) || [] === $value) {
            throw new \RuntimeException(\sprintf('Der Konfigurationswert "%s" fehlt oder ist ungültig.', $key));
        }

        $values = [];

        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                throw new \RuntimeException(\sprintf('Der Konfigurationswert "%s" enthält einen ungültigen Eintrag.', $key));
            }

            $normalized = strtolower(rtrim(trim($entry), '.'));

            if (!\in_array($normalized, $values, true)) {
                $values[] = $normalized;
            }
        }

        return $values;
    }
}
