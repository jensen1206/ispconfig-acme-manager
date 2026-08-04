<?php

declare(strict_types=1);

namespace App\ISPConfig;

use RuntimeException;

final readonly class ClientIdResolver
{
    public function __construct(
        private ApiClient $apiClient,
    ) {
    }

    public function resolve(Website $website): int
    {
        if (!preg_match(
            '/^client([1-9][0-9]*)$/',
            $website->systemGroup,
            $matches,
        )) {
            throw new RuntimeException(sprintf(
                'Aus der Systemgruppe "%s" konnte keine Client-ID ermittelt werden.',
                $website->systemGroup,
            ));
        }

        $clientId = (int) $matches[1];

        $result = $this->apiClient->request(
            'client_get',
            [
                'client_id' => $clientId,
            ],
        );

        if (!is_array($result) || $result === []) {
            throw new RuntimeException(sprintf(
                'ISPConfig-Client %d wurde nicht gefunden.',
                $clientId,
            ));
        }

        $returnedClientId = $result['client_id'] ?? null;

        if (!$this->numericValueEquals($returnedClientId, $clientId)) {
            throw new RuntimeException(sprintf(
                'ISPConfig hat für Client %d eine unerwartete Antwort geliefert.',
                $clientId,
            ));
        }

        return $clientId;
    }

    private function numericValueEquals(mixed $value, int $expected): bool
    {
        if (is_int($value)) {
            return $value === $expected;
        }

        return is_string($value)
            && ctype_digit($value)
            && (int) $value === $expected;
    }
}
