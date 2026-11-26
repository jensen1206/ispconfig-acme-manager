<?php

namespace App\ISPConfig;

use App\Certificate\Certificate;

final readonly class WebsiteUpdater
{
    public function __construct(
        private ApiClient $apiClient,
        private WebsiteRepository $websiteRepository,
        private ClientIdResolver $clientIdResolver,
    ) {
    }

    /**
     * Nur die Werte, die wir am vorhandenen Datensatz überschreiben.
     *
     * @return array<string, string>
     */
    public function buildCertificateParameters(
        Certificate $certificate,
        bool $acmeSource = true,
    ): array {
        if (!$acmeSource) {
            $info = (new \App\Certificate\CertificateReader())->read($certificate->certificatePath);

            return [
                'ssl' => 'y', 'ssl_letsencrypt' => 'n', 'ssl_domain' => $certificate->domain(),
                'ssl_cert' => $info->pem, 'ssl_bundle' => $info->chainPem,
                'ssl_key' => $this->readRequiredFile($certificate->keyPath), 'ssl_action' => 'save',
            ];
        }

        return [
            'ssl' => 'y',
            'ssl_letsencrypt' => 'n',
            'ssl_letsencrypt_exclude' => 'n',
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
        bool $acmeSource = true,
    ): mixed {
        $clientId = $this->clientIdResolver->resolve($website);

        /*
         * ISPConfig erwartet beim Website-Update einen vollständigen
         * Parametersatz. Deshalb laden wir zuerst den aktuellen Datensatz.
         */
        $parameters = $this->websiteRepository->findRawById(
            $website->id,
        );

        /*
         * Diese Verwaltungsfelder gehören nicht in das params-Array.
         */
        foreach ([
            'domain_id',
            'sys_userid',
            'sys_groupid',
            'sys_perm_user',
            'sys_perm_group',
            'sys_perm_other',
            'added_date',
            'added_by',
        ] as $field) {
            unset($parameters[$field]);
        }

        foreach (
            $this->buildCertificateParameters($certificate, $acmeSource) as $field => $value
        ) {
            $parameters[$field] = $value;
        }

        $result = $this->apiClient->updateWebsite(
            clientId: $clientId,
            domainId: $website->id,
            parameters: $parameters,
        );
        if ($result === false || $result === null) {
            throw new \RuntimeException('INSTALLATION_FAILED: ISPConfig hat das Update nicht bestätigt.');
        }

        $this->assertCertificateWasSaved(
            website: $website,
            certificate: $certificate,
            acmeSource: $acmeSource,
        );

        return $result;
    }

    private function assertCertificateWasSaved(
        Website $website,
        Certificate $certificate,
        bool $acmeSource,
    ): void {
        $updated = $this->websiteRepository->findRawById(
            $website->id,
        );

        $ssl = $updated['ssl'] ?? null;
        $letsEncrypt = $updated['ssl_letsencrypt'] ?? null;
        $sslDomain = $updated['ssl_domain'] ?? null;
        $certificateContent = $updated['ssl_cert'] ?? null;
        $bundleContent = $updated['ssl_bundle'] ?? null;
        $keyContent = $updated['ssl_key'] ?? null;

        if ('y' !== $ssl) {
            throw new \RuntimeException('ISPConfig hat SSL nicht aktiviert.');
        }

        if ('n' !== $letsEncrypt) {
            throw new \RuntimeException('ISPConfig Let’s Encrypt wurde nicht deaktiviert.');
        }

        if ($sslDomain !== $certificate->domain()) {
            throw new \RuntimeException(\sprintf('ISPConfig hat eine unerwartete SSL-Domain gespeichert: %s', \is_string($sslDomain) ? $sslDomain : 'unbekannt'));
        }

        if (!\is_string($certificateContent) || '' === trim($certificateContent)) {
            throw new \RuntimeException('ISPConfig hat das Zertifikat nicht gespeichert.');
        }

        $expected = $this->readRequiredFile($acmeSource ? $certificate->acmeCertificatePath() : $certificate->certificatePath);
        $expectedFingerprint = openssl_x509_fingerprint($expected, 'sha256');
        $actualFingerprint = openssl_x509_fingerprint($certificateContent, 'sha256');
        if (false === $expectedFingerprint || $actualFingerprint !== $expectedFingerprint) {
            throw new \RuntimeException('INSTALLATION_FAILED: Gespeichertes Zertifikat weicht ab.');
        }

        if (!\is_string($bundleContent) || '' === trim($bundleContent)) {
            throw new \RuntimeException('ISPConfig hat das CA-Bundle nicht gespeichert.');
        }

        if (!\is_string($keyContent) || '' === trim($keyContent)) {
            throw new \RuntimeException('ISPConfig hat den privaten Schlüssel nicht gespeichert.');
        }
    }

    private function readRequiredFile(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Zertifikatsdatei wurde nicht gefunden: %s', $path));
        }

        $content = file_get_contents($path);

        if (!\is_string($content) || '' === trim($content)) {
            throw new \RuntimeException(\sprintf('Zertifikatsdatei ist leer oder konnte nicht gelesen werden: %s', $path));
        }

        return trim($content);
    }
}
