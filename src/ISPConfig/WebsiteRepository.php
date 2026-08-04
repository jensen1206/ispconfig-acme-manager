<?php

namespace App\ISPConfig;

use App\Domain\Hostname;

final readonly class WebsiteRepository
{
    public function __construct(
        private ApiClient $apiClient,
    ) {
    }

    public function findByDomain(Hostname $hostname): Website
    {
        $result = $this->apiClient->request(
            'sites_web_domain_get',
            [
                'primary_id' => [
                    'domain' => $hostname->value(),
                    'type' => 'vhost',
                    'active' => 'y',
                ],
            ],
        );

        if (!\is_array($result) || [] === $result) {
            throw new ApiException(\sprintf('Für die Domain "%s" wurde keine aktive ISPConfig-Website gefunden.', $hostname->value()));
        }

        /*
         * Je nach ISPConfig-Version liefert die API entweder direkt einen
         * einzelnen Datensatz oder eine Liste mit Datensätzen.
         */
        if (\array_key_exists('domain_id', $result)) {
            return $this->createWebsite(
                $this->normalizeStringKeyedArray($result),
            );
        }

        $first = reset($result);

        if (!\is_array($first)) {
            throw new ApiException(\sprintf('Die ISPConfig-Antwort für "%s" hat eine unerwartete Struktur.', $hostname->value()));
        }

        return $this->createWebsite(
            $this->normalizeStringKeyedArray($first),
        );
    }

    public function findById(int $id): Website
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Die Website-ID muss größer als 0 sein.');
        }

        $data = $this->apiClient->request(
            'sites_web_domain_get',
            [
                'primary_id' => $id,
            ],
        );

        if (!\is_array($data) || !\array_key_exists('domain_id', $data)) {
            throw new ApiException(\sprintf('Die Website mit der ID %d wurde nicht gefunden.', $id));
        }

        return $this->createWebsite(
            $this->normalizeStringKeyedArray($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createWebsite(array $data): Website
    {
        $primaryHostname = new Hostname(
            $this->requiredString($data, 'domain'),
        );

        return new Website(
            id: $this->requiredInt($data, 'domain_id'),
            systemGroupId: $this->requiredInt($data, 'sys_groupid'),
            primaryHostname: $primaryHostname,
            hostnames: $this->createHostnames(
                primaryHostname: $primaryHostname,
                subdomain: $this->optionalString($data, 'subdomain'),
            ),
            documentRoot: $this->requiredString($data, 'document_root'),
            systemUser: $this->requiredString($data, 'system_user'),
            systemGroup: $this->requiredString($data, 'system_group'),
            serverId: $this->requiredInt($data, 'server_id'),
            sslEnabled: $this->yesNoToBool($data['ssl'] ?? null),
            letsEncryptEnabled: $this->yesNoToBool(
                $data['ssl_letsencrypt'] ?? null,
            ),
        );
    }

    /**
     * @return list<Hostname>
     */
    private function createHostnames(
        Hostname $primaryHostname,
        ?string $subdomain,
    ): array {
        $hostnames = [$primaryHostname];

        switch ($subdomain) {
            case 'www':
                $hostnames[] = new Hostname(
                    'www.'.$primaryHostname->value(),
                );
                break;

            case '*':
                $hostnames[] = new Hostname(
                    '*.'.$primaryHostname->value(),
                );
                break;

            case '':
            case 'none':
            case null:
                break;

            default:
                throw new ApiException(\sprintf('Unbekannte ISPConfig-Subdomain-Einstellung "%s" für %s.', $subdomain, $primaryHostname->value()));
        }

        return $hostnames;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new ApiException(\sprintf('Das Pflichtfeld "%s" fehlt in der ISPConfig-Antwort.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new ApiException(\sprintf('Das Feld "%s" hat einen unerwarteten Datentyp.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new ApiException(\sprintf('Das Pflichtfeld "%s" fehlt oder ist keine gültige Zahl.', $key));
    }

    private function yesNoToBool(mixed $value): bool
    {
        return 'y' === $value;
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedArray(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                throw new ApiException('Die ISPConfig-Antwort enthält einen ungültigen Array-Schlüssel.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
