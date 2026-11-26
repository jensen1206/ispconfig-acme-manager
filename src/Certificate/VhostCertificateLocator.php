<?php

namespace App\Certificate;

final class VhostCertificateLocator
{
    /** @return array{string, string} */
    public function locate(string $vhostPath, string $certificatePath, string $keyPath): array
    {
        if (file_exists($vhostPath)) {
            if (!is_file($vhostPath) || !is_readable($vhostPath)) {
                throw new \RuntimeException('VHost-Zertifikatszuordnung nicht lesbar.');
            }
            $content = file_get_contents($vhostPath);
            if (!\is_string($content)) {
                throw new \RuntimeException('VHost-Zertifikatszuordnung nicht lesbar.');
            }
            // This deliberately handles only explicit paths in generated TLS blocks.
            // Includes/macros/conditional configuration need Apache-aware resolution.
            if (preg_match('/^\s*(?:Include\S*|Define|Use|<\/?(?:IfDefine|If|ElseIf|Else|Macro)\b)/mi', $content)) {
                throw new \RuntimeException('VHost enthält dynamische Konfiguration; Zertifikatszuordnung nicht eindeutig.');
            }
            preg_match_all('/<VirtualHost\s+([^>]+)>(.*?)<\/VirtualHost\s*>/si', $content, $blocks, \PREG_SET_ORDER);
            $pairs = [];
            foreach ($blocks as $block) {
                if (!preg_match('/(?:^|\s)\S*:443(?:\s|$)/', $block[1])) {
                    continue;
                }
                $certificate = $this->directive($block[2], 'SSLCertificateFile');
                $key = $this->directive($block[2], 'SSLCertificateKeyFile');
                $pairs[$certificate."\0".$key] = [$certificate, $key];
            }
            if (1 !== \count($pairs)) {
                throw new \RuntimeException('Keine eindeutige Zertifikat/Schlüssel-Zuordnung im HTTPS-VHost.');
            }

            return array_values($pairs)[0];
        }
        $legacyCertificate = substr($certificatePath, 0, -4).'-le.crt';
        $legacyKey = substr($keyPath, 0, -4).'-le.key';
        if ((is_file($certificatePath) || is_file($keyPath)) && (is_file($legacyCertificate) || is_file($legacyKey))) {
            throw new \RuntimeException('Mehrere lokale Zertifikatspaare, aber keine lesbare VHost-Zuordnung; keine automatische Auswahl.');
        }
        if (!is_file($certificatePath) && !is_file($keyPath) && is_file($legacyCertificate) && is_file($legacyKey)) {
            return [$legacyCertificate, $legacyKey];
        }

        return [$certificatePath, $keyPath];
    }

    private function directive(string $block, string $name): string
    {
        preg_match_all('/^\s*'.preg_quote($name, '/').'\s+([^\r\n]+)$/mi', $block, $matches);
        if (1 !== \count($matches[1])) {
            throw new \RuntimeException('Unvollständige oder mehrdeutige TLS-Dateizuordnung.');
        }
        $value = trim($matches[1][0]);
        if (preg_match('/^"([^"]+)"\s*$/D', $value, $quoted)) {
            $value = $quoted[1];
        } elseif (preg_match('/\s/', $value)) {
            throw new \RuntimeException('TLS-Dateipfad nicht eindeutig.');
        }
        $absolute = str_starts_with($value, '/') || (\PHP_OS_FAMILY === 'Windows' && 1 === preg_match('~^[A-Za-z]:/~', $value));
        if (!$absolute || false !== strpbrk($value, '$\\')) {
            throw new \RuntimeException('Nur explizite absolute TLS-Dateipfade werden unterstützt.');
        }

        return $value;
    }
}
