<?php

namespace App\Certificate;

final readonly class CertificateReader
{
    public function read(string $path): CertificateInfo
    {
        $content = $this->readFile($path);
        if (str_ends_with($path, '.crt')) {
            $bundlePath = substr($path, 0, -4).'.bundle';
            if (is_file($bundlePath) && is_readable($bundlePath)) {
                $content .= "\n".$this->readFile($bundlePath);
            }
        }

        return $this->readPem($content);
    }

    public function readPem(string $content): CertificateInfo
    {
        $certificate = openssl_x509_read($content);

        if (false === $certificate) {
            throw new \RuntimeException('Das Zertifikat konnte nicht gelesen werden.');
        }

        $data = openssl_x509_parse($certificate, true);

        if (!\is_array($data)) {
            throw new \RuntimeException('Das Zertifikat konnte nicht analysiert werden.');
        }

        $validFromTimestamp = $data['validFrom_time_t'] ?? null;
        $validUntilTimestamp = $data['validTo_time_t'] ?? null;

        if (
            !\is_int($validFromTimestamp)
            || !\is_int($validUntilTimestamp)
        ) {
            throw new \RuntimeException('Der Gültigkeitszeitraum des Zertifikats fehlt.');
        }

        $commonName = $this->readCommonName($data);
        $issuer = $this->readIssuer($data);
        $subjectAlternativeNames = $this->readSubjectAlternativeNames($data);

        $fingerprint = openssl_x509_fingerprint(
            $certificate,
            'sha256',
        );

        if (!\is_string($fingerprint) || '' === $fingerprint) {
            throw new \RuntimeException('Der SHA-256-Fingerprint konnte nicht ermittelt werden.');
        }

        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $content, $blocks);
        $extensions = $data['extensions'] ?? [];
        $crl = \is_array($extensions) ? ($extensions['crlDistributionPoints'] ?? '') : '';
        $serial = $data['serialNumberHex'] ?? '';

        return new CertificateInfo(
            commonName: $commonName,
            issuer: $issuer,
            validFrom: (new \DateTimeImmutable())
                ->setTimestamp($validFromTimestamp),
            validUntil: (new \DateTimeImmutable())
                ->setTimestamp($validUntilTimestamp),
            subjectAlternativeNames: $subjectAlternativeNames,
            fingerprint: strtoupper($fingerprint),
            serialNumber: \is_string($serial) ? (ltrim(strtoupper($serial), '0') ?: '0') : '',
            pem: ($blocks[0][0] ?? '')."\n",
            chainPem: implode("\n", \array_slice($blocks[0], 1)),
            crlDistributionPoints: \is_string($crl) ? $crl : '',
        );
    }

    public function keyMatchesCertificate(
        string $certificatePath,
        string $keyPath,
    ): bool {
        $certificateContent = $this->readFile($certificatePath);
        $keyContent = $this->readFile($keyPath);

        $certificate = openssl_x509_read($certificateContent);

        if (false === $certificate) {
            throw new \RuntimeException(\sprintf('Das Zertifikat konnte nicht gelesen werden: %s', $certificatePath));
        }

        $publicKey = openssl_pkey_get_public($certificate);

        if (false === $publicKey) {
            throw new \RuntimeException('Der öffentliche Schlüssel konnte nicht aus dem Zertifikat gelesen werden.');
        }

        $privateKey = openssl_pkey_get_private($keyContent);

        if (false === $privateKey) {
            throw new \RuntimeException(\sprintf('Der private Schlüssel konnte nicht gelesen werden: %s', $keyPath));
        }

        $publicDetails = openssl_pkey_get_details($publicKey);
        $privateDetails = openssl_pkey_get_details($privateKey);

        if (
            !\is_array($publicDetails)
            || !\is_array($privateDetails)
        ) {
            throw new \RuntimeException('Die Schlüsselinformationen konnten nicht gelesen werden.');
        }

        $publicPem = $publicDetails['key'] ?? null;
        $privatePublicPem = $privateDetails['key'] ?? null;

        return \is_string($publicPem)
            && \is_string($privatePublicPem)
            && hash_equals($publicPem, $privatePublicPem);
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function readCommonName(array $data): string
    {
        $subject = $data['subject'] ?? null;

        if (!\is_array($subject)) {
            return 'unbekannt';
        }

        $commonName = $subject['CN'] ?? null;

        return \is_string($commonName) && '' !== $commonName
            ? $commonName
            : 'unbekannt';
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function readIssuer(array $data): string
    {
        $issuer = $data['issuer'] ?? null;

        if (!\is_array($issuer)) {
            return 'unbekannt';
        }

        $parts = [];

        foreach (['C', 'O', 'CN'] as $key) {
            $value = $issuer[$key] ?? null;

            if (\is_string($value) && '' !== $value) {
                $parts[] = \sprintf('%s=%s', $key, $value);
            }
        }

        return [] !== $parts
            ? implode(', ', $parts)
            : 'unbekannt';
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<string>
     */
    private function readSubjectAlternativeNames(array $data): array
    {
        $extensions = $data['extensions'] ?? null;

        if (!\is_array($extensions)) {
            return [];
        }

        $subjectAlternativeName = $extensions['subjectAltName'] ?? null;

        if (!\is_string($subjectAlternativeName)) {
            return [];
        }

        $hostnames = [];

        foreach (explode(',', $subjectAlternativeName) as $entry) {
            $entry = trim($entry);

            if (!str_starts_with($entry, 'DNS:')) {
                continue;
            }

            $hostname = trim(substr($entry, 4));

            if ('' !== $hostname) {
                $hostnames[] = strtolower($hostname);
            }
        }

        return array_values(array_unique($hostnames));
    }

    private function readFile(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Datei wurde nicht gefunden: %s', $path));
        }

        $content = file_get_contents($path);

        if (!\is_string($content) || '' === trim($content)) {
            throw new \RuntimeException(\sprintf('Datei ist leer oder konnte nicht gelesen werden: %s', $path));
        }

        return $content;
    }
}
