<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final readonly class Configuration
{
    public function __construct(
        public string $ispconfigServer,
        public string $ispconfigUsername,
        public string $ispconfigPassword,
        public string $acmeExecutable,
        public string $dnsProvider,
        public int $dnsSleep,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'Konfigurationsdatei wurde nicht gefunden: %s',
                $path,
            ));
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new RuntimeException(
                'Die Konfigurationsdatei muss ein Array zurückgeben.',
            );
        }

        $normalizedConfig = [];

        foreach ($config as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException(
                    'Die Konfigurationsdatei enthält einen ungültigen Schlüssel.',
                );
            }

            $normalizedConfig[$key] = $value;
        }

        /** @var array<string, mixed> $normalizedConfig */

        return new self(
            ispconfigServer: self::requiredString($normalizedConfig, 'server'),
            ispconfigUsername: self::requiredString($normalizedConfig, 'username'),
            ispconfigPassword: self::requiredString($normalizedConfig, 'password'),
            acmeExecutable: self::requiredString($normalizedConfig, 'acme'),
            dnsProvider: self::requiredString($normalizedConfig, 'dns_provider'),
            dnsSleep: self::requiredInt($normalizedConfig, 'dns_sleep'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'Der Konfigurationswert "%s" fehlt oder ist ungültig.',
                $key,
            ));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredInt(array $config, string $key): int
    {
        $value = $config[$key] ?? null;

        if (!is_int($value) || $value < 0) {
            throw new RuntimeException(sprintf(
                'Der Konfigurationswert "%s" fehlt oder ist ungültig.',
                $key,
            ));
        }

        return $value;
    }
}