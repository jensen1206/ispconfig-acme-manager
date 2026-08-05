<?php

namespace App\Dns;

final readonly class NameserverCheckResult
{
    /**
     * @param list<string> $nameservers
     */
    public function __construct(
        public string $requestedDomain,
        public string $resolvedZone,
        public array $nameservers,
        public bool $managed,
    ) {
    }

    public function message(): string
    {
        if ($this->managed) {
            return \sprintf(
                'DNS-Zone "%s" wird über die konfigurierten Nameserver verwaltet: %s',
                $this->resolvedZone,
                implode(', ', $this->nameservers),
            );
        }

        return \sprintf(
            'DNS-Zone "%s" wird extern verwaltet: %s',
            $this->resolvedZone,
            [] !== $this->nameservers
                ? implode(', ', $this->nameservers)
                : 'keine autoritativen Nameserver ermittelt',
        );
    }
}
