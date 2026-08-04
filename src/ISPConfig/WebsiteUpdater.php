<?php

declare(strict_types=1);

namespace App\ISPConfig;

use App\Certificate\Certificate;
use RuntimeException;

final readonly class WebsiteUpdater
{
    public function __construct(
        private ApiClient $apiClient,
        private ClientIdResolver $clientIdResolver,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function buildCertificateParameters(
        Certificate $certificate,
    ): array {
        return [
            'ssl' => 'y',
            'ssl_letsencrypt' => 'n',
            'ssl_domain' => $certificate->domain(),
            'ssl_cert' => $this->readRequiredFile(
                $certificate->acmeCertificatePath(),
            ),
            'ssl_bundle' => $this->readRequiredFile(
                $certificate->acmeBundlePath(),
            ),
            'ssl_key' => $this->readRequiredFile(
                $certificate->acmeKeyPath(),
            ),
            'ssl_action' => 'save',
        ];
    }

    public function resolveClientId(Website $website): int
    {
        return $this->clientIdResolver->resolve($website);
    }

    public function saveCertificate(
        Website $website,
        Certificate $certificate,
    ): mixed {
        $clientId = $this->clientIdResolver->resolve($website);

        $result = $this->apiClient->updateWebsite(
            clientId: $clientId,
            domainId: $website->id,
            parameters: $this->buildCertificateParameters($certificate),
        );

        if ($result === false || $result === null) {
            throw new RuntimeException(sprintf(
                'ISPConfig konnte die Website "%s" nicht aktualisieren.',
                $website->domain(),
            ));
        }

        return $result;
    }

    private function readRequiredFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'Zertifikatsdatei wurde nicht gefunden: %s',
                $path,
            ));
        }

        $content = file_get_contents($path);

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException(sprintf(
                'Zertifikatsdatei ist leer oder konnte nicht gelesen werden: %s',
                $path,
            ));
        }

        return trim($content);
    }
}
