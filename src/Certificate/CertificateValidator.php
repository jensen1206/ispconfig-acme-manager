<?php

namespace App\Certificate;

use App\Process\ProcessRunnerInterface;
use App\Service\VerificationCheck;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CertificateValidator
{
    /** @var array<string, array{body: string, at: int}> */
    private array $crlCache = [];

    public function __construct(private ProcessRunnerInterface $runner, private HttpClientInterface $http, private int $timeout = 15, private string $caFile = '')
    {
    }

    /** @param list<string> $hostnames
     * @return list<VerificationCheck>
     */
    public function check(CertificateInfo $info, array $hostnames, string $scope, int $days = 30, ?float $deadline = null): array
    {
        $deadline ??= microtime(true) + $this->timeout;
        $now = new \DateTimeImmutable();
        $timeStatus = $now > $info->validUntil ? CertificateStatus::EXPIRED
            : ($now < $info->validFrom ? CertificateStatus::CERTIFICATE_INVALID
                : ($info->daysUntilExpiration() <= $days ? CertificateStatus::EXPIRING : CertificateStatus::VALID));
        $checks = [new VerificationCheck('Gültigkeitszeitraum', $info->isCurrentlyValid(),
            $info->validFrom->format(\DATE_ATOM).' bis '.$info->validUntil->format(\DATE_ATOM), $timeStatus, $scope)];
        foreach ($hostnames as $hostname) {
            $matches = HostnameMatcher::matches($hostname, $info->subjectAlternativeNames);
            $checks[] = new VerificationCheck('SAN', $matches, $hostname,
                $matches ? CertificateStatus::VALID : CertificateStatus::HOSTNAME_MISMATCH, $scope);
        }
        $directory = sys_get_temp_dir().'/acme-verify-'.bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Temporäres Prüfverzeichnis nicht verfügbar.');
        }
        try {
            $this->write($directory.'/leaf.pem', $info->pem);
            $this->write($directory.'/chain.pem', $info->chainPem);
            $command = ['openssl', 'verify', '-purpose', 'sslserver'];
            if ('' !== $this->caFile) {
                array_push($command, '-CAfile', $this->caFile);
            }
            if ('' !== trim($info->chainPem)) {
                array_push($command, '-untrusted', $directory.'/chain.pem');
            }
            $chain = $this->runner->run([...$command, $directory.'/leaf.pem'], $this->remaining($deadline), false);
            $checks[] = new VerificationCheck('Vertrauenskette', $chain->successful(),
                $chain->successful() ? 'Vertrauenskette gültig.' : 'Vertrauenskette, Zweck oder Gültigkeit nicht bestätigt.',
                $chain->successful() ? CertificateStatus::VALID : CertificateStatus::CERTIFICATE_INVALID, $scope);
            $revocation = CertificateStatus::REVOCATION_UNKNOWN;
            if ($chain->successful()) {
                preg_match_all('~URI:(https?://[^\s,]+)~i', $info->crlDistributionPoints, $urls);
                foreach (\array_slice(array_unique($urls[1]), 0, 4) as $url) {
                    try {
                        $cached = $this->crlCache[$url] ?? null;
                        if (null !== $cached && time() - $cached['at'] < 60) {
                            $body = $cached['body'];
                        } else {
                            $host = parse_url($url, \PHP_URL_HOST);
                            if (!\is_string($host) || null !== parse_url($url, \PHP_URL_USER) || null !== parse_url($url, \PHP_URL_PORT)) {
                                continue;
                            }
                            $addresses = gethostbynamel($host);
                            if (false === $addresses || [] === $addresses) {
                                continue;
                            }
                            foreach ($addresses as $address) {
                                if (false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE)) {
                                    throw new \RuntimeException('Nicht öffentliche CRL-Adresse.');
                                }
                            }
                            $response = $this->http->request('GET', $url, [
                                'resolve' => [$host => $addresses[0]], 'proxy' => null, 'no_proxy' => '*',
                                'timeout' => $this->remaining($deadline), 'max_duration' => $this->remaining($deadline), 'max_redirects' => 0,
                                'on_progress' => static function (int $downloaded, int $size): void {
                                    if (max($downloaded, $size) > 16 * 1024 * 1024) {
                                        throw new \RuntimeException('CRL zu groß.');
                                    }
                                },
                            ]);
                            if (200 !== $response->getStatusCode()) {
                                $response->cancel();
                                continue;
                            }
                            $body = $response->getContent();
                            if (strlen($body) > 16 * 1024 * 1024) {
                                throw new \RuntimeException('CRL zu groß.');
                            }
                            if (count($this->crlCache) >= 16) {
                                array_shift($this->crlCache);
                            }
                            $this->crlCache[$url] = ['body' => $body, 'at' => time()];
                        }
                        $this->write($directory.'/crl.raw', $body);
                        $conversion = $this->runner->run(['openssl', 'crl', '-inform',
                            str_contains($body, '-----BEGIN X509 CRL-----') ? 'PEM' : 'DER',
                            '-in', $directory.'/crl.raw', '-out', $directory.'/crl.pem'], $this->remaining($deadline), false);
                        if (!$conversion->successful()) {
                            continue;
                        }
                        $result = $this->runner->run([...$command, '-crl_check', '-CRLfile',
                            $directory.'/crl.pem', $directory.'/leaf.pem'], $this->remaining($deadline), false);
                        if ($result->successful()) {
                            $revocation = CertificateStatus::VALID;
                            break;
                        }
                        preg_match_all('/error (\d+) at (\d+) depth lookup/', $result->combinedOutput(), $errors, \PREG_SET_ORDER);
                        if (1 === \count($errors) && '23' === $errors[0][1] && '0' === $errors[0][2]) {
                            $revocation = CertificateStatus::REVOKED;
                            break;
                        }
                    } catch (\Throwable) { /* Never treat network/parse failures as revocation. */
                    }
                }
            }
            $checks[] = new VerificationCheck('CRL', CertificateStatus::VALID === $revocation,
                match ($revocation) {
                    CertificateStatus::VALID => 'Gültige CRL geprüft; kein Widerruf des Leaf-Zertifikats.',
                    CertificateStatus::REVOKED => 'Widerruf durch gültige CRL bestätigt.',
                    default => 'Widerrufsstatus nicht feststellbar (CRL/Kette fehlt, ungültig oder nicht erreichbar).',
                }, $revocation, $scope);
        } catch (\Throwable) {
            $checks[] = new VerificationCheck('Kette/CRL', false, 'Kryptografische Prüfung nicht abschließbar.', CertificateStatus::REVOCATION_UNKNOWN, $scope);
        } finally {
            foreach (['leaf.pem', 'chain.pem', 'crl.raw', 'crl.pem'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory);
        }

        return $checks;
    }

    private function remaining(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new \RuntimeException('Prüfzeitlimit erreicht.');
        }

        return min($remaining, $this->timeout);
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) !== \strlen($content)) {
            throw new \RuntimeException('Prüfdatei konnte nicht geschrieben werden.');
        }
    }
}
