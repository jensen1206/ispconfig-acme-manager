<?php

namespace App\Dns;

use App\Config\Configuration;
use App\Domain\Hostname;

final readonly class NameserverChecker
{
    public function __construct(
        private Configuration $configuration,
    ) {
    }

    public function check(Hostname $hostname): NameserverCheckResult
    {
        $domain = strtolower(rtrim($hostname->value(), '.'));

        if (str_starts_with($domain, '*.')) {
            $domain = substr($domain, 2);
        }

        [$resolvedZone, $nameservers] = $this->resolveNameservers($domain);

        if ([] === $nameservers) {
            throw new \RuntimeException('CONNECTION_ERROR: Keine autoritativen Nameserver ermittelt.');
        }
        $managedNameservers = $this->configuration->managedNameservers;

        /*
         * Die Zone gilt nur dann als von uns verwaltet, wenn alle öffentlich
         * autoritativen Nameserver in der Konfiguration hinterlegt sind.
         */
        $managed = true;

        foreach ($nameservers as $nameserver) {
            if (!\in_array($nameserver, $managedNameservers, true)) {
                $managed = false;

                break;
            }
        }

        return new NameserverCheckResult(
            requestedDomain: $domain,
            resolvedZone: $resolvedZone,
            nameservers: $nameservers,
            managed: $managed,
        );
    }

    public function assertManaged(Hostname $hostname): void
    {
        $result = $this->check($hostname);

        if ($result->managed) {
            $challenge = '_acme-challenge.'.$hostname->baseDomain();
            $aliases = dns_get_record($challenge, \DNS_CNAME);
            if (false === $aliases) {
                throw new \RuntimeException('CONNECTION_ERROR: Challenge-Delegation nicht prüfbar.');
            }
            if ([] !== $aliases) {
                throw new \RuntimeException('EXTERNAL_DNS: CNAME-Delegation der ACME-Challenge wird nicht automatisch bearbeitet.');
            }
            [, $servers] = $this->resolveNameservers($challenge);
            if ([] === $servers || [] !== array_diff($servers, $this->configuration->managedNameservers)) {
                throw new \RuntimeException('EXTERNAL_DNS: Challenge-Zone nicht vollständig verwaltet.');
            }

            return;
        }

        throw new \RuntimeException(\sprintf('DNS-01 über dns_ispconfig wurde übersprungen. %s', $result->message()));
    }

    /**
     * Subdomains besitzen häufig keine eigenen NS-Records. Deshalb wird
     * schrittweise bis zur übergeordneten DNS-Zone gesucht.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function resolveNameservers(string $domain): array
    {
        $labels = explode('.', $domain);

        while (\count($labels) >= 2) {
            $candidate = implode('.', $labels);
            $nameservers = $this->queryNameservers($candidate);

            if ([] !== $nameservers) {
                return [$candidate, $nameservers];
            }

            array_shift($labels);
        }

        return [$domain, []];
    }

    /**
     * @return list<string>
     */
    private function queryNameservers(string $domain): array
    {
        $records = dns_get_record($domain, \DNS_NS);

        if (false === $records) {
            return [];
        }

        $nameservers = [];

        foreach ($records as $record) {
            $target = $record['target'] ?? null;

            if (!\is_string($target) || '' === trim($target)) {
                continue;
            }

            $normalized = strtolower(rtrim(trim($target), '.'));

            if (!\in_array($normalized, $nameservers, true)) {
                $nameservers[] = $normalized;
            }
        }

        sort($nameservers);

        return $nameservers;
    }
}
