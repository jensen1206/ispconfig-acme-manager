<?php

namespace App\Tests;

use App\Certificate\CertificateReader;
use App\Certificate\CertificateStatus;
use App\Certificate\CertificateValidator;
use App\Certificate\HostnameMatcher;
use App\Process\ProcessRunner;
use App\Service\VerificationCheck;
use App\Service\VerificationReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CertificateValidationTest extends TestCase
{
    private static string $directory;
    private static string $root;
    private static string $leaf;
    private static string $goodCrl;
    private static string $revokedCrl;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/acme-test-'.bin2hex(random_bytes(10));
        mkdir(self::$directory, 0700);
        $d = str_replace('\\', '/', self::$directory);
        file_put_contents($d.'/index', '');
        file_put_contents($d.'/serial', "1000\n");
        file_put_contents($d.'/crlnumber', "1000\n");
        file_put_contents($d.'/openssl.cnf', "[ req ]\ndistinguished_name=dn\n[ dn ]\n[ ca ]\ndefault_ca=local\n[ local ]\ndatabase=$d/index\nnew_certs_dir=$d\ncertificate=$d/root.pem\nprivate_key=$d/root.key\nserial=$d/serial\ncrlnumber=$d/crlnumber\ndefault_md=sha256\ndefault_days=90\ndefault_crl_days=7\npolicy=policy\nx509_extensions=server\n[ policy ]\ncommonName=supplied\n[ server ]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:example.test,DNS:*.example.test\ncrlDistributionPoints=URI:http://8.8.8.8/test.crl\nauthorityKeyIdentifier=keyid,issuer\n");
        self::openssl(['req', '-new', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', "$d/root.key", '-out', "$d/root.pem", '-days', '365', '-subj', '/CN=Test Root', '-config', "$d/openssl.cnf", '-addext', 'basicConstraints=critical,CA:true', '-addext', 'keyUsage=critical,keyCertSign,cRLSign', '-addext', 'subjectKeyIdentifier=hash']);
        self::openssl(['req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', "$d/leaf.key", '-out', "$d/leaf.csr", '-subj', '/CN=example.test', '-config', "$d/openssl.cnf"]);
        self::openssl(['ca', '-batch', '-config', "$d/openssl.cnf", '-in', "$d/leaf.csr", '-out', "$d/leaf.pem"]);
        self::openssl(['ca', '-gencrl', '-config', "$d/openssl.cnf", '-out', "$d/good.crl"]);
        self::$goodCrl = file_get_contents("$d/good.crl");
        self::openssl(['ca', '-revoke', "$d/leaf.pem", '-config', "$d/openssl.cnf"]);
        self::openssl(['ca', '-gencrl', '-config', "$d/openssl.cnf", '-out', "$d/revoked.crl"]);
        self::$revokedCrl = file_get_contents("$d/revoked.crl");
        self::$root = "$d/root.pem";
        self::$leaf = "$d/leaf.pem";
    }

    private static function openssl(array $arguments): void
    {
        $result = (new ProcessRunner())->run(['openssl', ...$arguments], 30, false);
        if (!$result->successful()) {
            throw new \RuntimeException('Test-CA konnte nicht erzeugt werden: '.$result->combinedOutput());
        }
    }

    public static function tearDownAfterClass(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir(self::$directory);
    }

    private function report(string $crl, int $httpStatus = 200): VerificationReport
    {
        $reader = new CertificateReader();
        $info = $reader->read(self::$leaf);
        $validator = new CertificateValidator(new ProcessRunner(), new MockHttpClient(new MockResponse($crl, ['http_code' => $httpStatus])), 5, self::$root);

        return new VerificationReport($info, $validator->check($info, ['example.test', 'www.example.test'], 'local'));
    }

    public function testTrustedCertificateWithValidCrl(): void
    {
        self::assertTrue($this->report(self::$goodCrl)->successful());
        self::assertSame(CertificateStatus::VALID, $this->report(self::$goodCrl)->status('local'));
    }

    public function testRevocationIndependentOfRemainingLifetime(): void
    {
        $report = $this->report(self::$revokedCrl);
        self::assertGreaterThan(30, $report->certificateInfo->daysUntilExpiration());
        self::assertSame(CertificateStatus::REVOKED, $report->status('local'));
        self::assertFalse($report->successful());
    }

    public function testUnavailableAndMalformedCrlAreUnknown(): void
    {
        self::assertSame(CertificateStatus::REVOCATION_UNKNOWN, $this->report('', 503)->status('local'));
        self::assertSame(CertificateStatus::REVOCATION_UNKNOWN, $this->report('not a CRL')->status('local'));
    }

    public function testInvalidCrlSignatureIsUnknownNotRevoked(): void
    {
        $base64 = preg_replace('/-----[^\n]+-----|\s/', '', self::$revokedCrl);
        $der = base64_decode($base64, true);
        $der[\strlen($der) - 1] = \chr(\ord($der[\strlen($der) - 1]) ^ 1);
        $corrupt = "-----BEGIN X509 CRL-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END X509 CRL-----\n";
        self::assertSame(CertificateStatus::REVOCATION_UNKNOWN, $this->report($corrupt)->status('local'));
    }

    public function testWrongHostnameIsNotHiddenBySuccessfulCrl(): void
    {
        $info = (new CertificateReader())->read(self::$leaf);
        $validator = new CertificateValidator(new ProcessRunner(), new MockHttpClient(new MockResponse(self::$goodCrl)), 5, self::$root);
        $report = new VerificationReport($info, $validator->check($info, ['other.test'], 'public'));
        self::assertSame(CertificateStatus::HOSTNAME_MISMATCH, $report->status('public'));
        self::assertFalse($report->successful());
    }

    public function testPrivateKeyAndSerial(): void
    {
        $reader = new CertificateReader();
        self::assertSame('1000', $reader->read(self::$leaf)->serialNumber);
        self::assertTrue($reader->keyMatchesCertificate(self::$leaf, self::$directory.'/leaf.key'));
        self::assertFalse($reader->keyMatchesCertificate(self::$leaf, self::$directory.'/root.key'));
    }

    public function testWildcardCoversExactlyOneLabel(): void
    {
        self::assertTrue(HostnameMatcher::matches('www.example.test', ['*.example.test']));
        self::assertFalse(HostnameMatcher::matches('example.test', ['*.example.test']));
        self::assertFalse(HostnameMatcher::matches('a.b.example.test', ['*.example.test']));
        self::assertFalse(HostnameMatcher::matches('*.example.test', ['www.example.test']));
    }

    public function testUnknownDoesNotHideExpiredAndScopesStayIndependent(): void
    {
        $report = new VerificationReport(null, [
            new VerificationCheck('time', false, '', CertificateStatus::EXPIRED),
            new VerificationCheck('crl', false, '', CertificateStatus::REVOCATION_UNKNOWN),
            new VerificationCheck('live', true, '', CertificateStatus::VALID, 'public'),
        ]);
        self::assertSame(CertificateStatus::EXPIRED, $report->status('local'));
        self::assertSame(CertificateStatus::VALID, $report->status('public'));
        self::assertFalse($report->successful());
    }

    public static function installationCases(): array
    {
        return ['delayed success' => [true, false], 'TLS never ready' => [false, false], 'new vhost error' => [true, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('installationCases')]
    public function testInstallationWaitsForLiveCertificateAndPreservesDirectives(bool $available, bool $artifact): void
    {
        $d = self::$directory.'/workflow-'.bin2hex(random_bytes(4));
        mkdir($d, 0700);
        mkdir($d.'/example.test_ecc', 0700);
        copy(self::$leaf, $d.'/example.test_ecc/example.test.cer');
        copy(self::$leaf, $d.'/example.test_ecc/fullchain.cer');
        copy(self::$directory.'/leaf.key', $d.'/example.test_ecc/example.test.key');
        copy(self::$root, $d.'/example.test_ecc/ca.cer');
        $config = new \App\Config\Configuration('https://panel.example.test', 'user', 'secret', '/unused', 'dns_ispconfig', 0, ['ns.example.test'],
            localServerId: 1, installationTimeout: 5, pollInterval: 1, stateDirectory: $d.'/state', acmeConfigHome: $d, caFile: self::$root, vhostDirectory: $d);
        $hostname = new \App\Domain\Hostname('example.test');
        $website = new \App\ISPConfig\Website(1, 1, $hostname, [$hostname], $d, 'web1', 'client1', 1, true, false);
        $raw = ['domain_id' => 1, 'apache_directives' => 'ProxyPass / http://backend/', 'nginx_directives' => 'unchanged', 'ssl' => 'y', 'ssl_letsencrypt' => 'n'];
        $updates = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$raw, &$updates, $artifact, $d): MockResponse {
            $action = parse_url($url, \PHP_URL_QUERY);
            $response = match ($action) {
                'login' => 'session', 'client_get' => ['client_id' => 1], 'sites_web_domain_get' => $raw,
                'logout' => true, default => null,
            };
            if ('sites_web_domain_update' === $action) {
                $payload = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame('ProxyPass / http://backend/', $payload['params']['apache_directives']);
                self::assertSame('unchanged', $payload['params']['nginx_directives']);
                $raw = $payload['params'];
                ++$updates;
                if ($artifact) {
                    file_put_contents($d.'/example.test.vhost.err', 'test error');
                }
                $response = true;
            }

            return new MockResponse(json_encode(['code' => 'ok', 'response' => $response], \JSON_THROW_ON_ERROR));
        });
        $api = new \App\ISPConfig\ApiClient($http, $config);
        $repository = new \App\ISPConfig\WebsiteRepository($api);
        $reader = new CertificateReader();
        $runner = $this->createMock(\App\Process\ProcessRunnerInterface::class);
        $probes = 0;
        $runner->method('run')->willReturnCallback(static function (array $command, ?int $timeout, bool $liveOutput) use (&$probes, $available): \App\Process\ProcessResult {
            if ('s_client' === $command[1]) {
                ++$probes;

                return new \App\Process\ProcessResult(0, !$available || 1 === $probes ? '' : file_get_contents(self::$leaf), '');
            }

            return (new ProcessRunner())->run($command, $timeout, $liveOutput);
        });
        $factory = new \App\Certificate\CertificateFactory($config);
        $verification = new \App\Service\CertificateVerificationService($repository, $factory, $reader,
            new \App\Certificate\LiveCertificateReader($runner, $reader),
            new CertificateValidator($runner, new MockHttpClient(static fn () => new MockResponse(self::$goodCrl)), 5, self::$root), $config);
        $updater = new \App\ISPConfig\WebsiteUpdater($api, $repository, new \App\ISPConfig\ClientIdResolver($api));
        $installer = new \App\Service\CertificateInstallationService($updater, $verification, $config, new \App\Service\OperationGuard($config));
        if (!$available || $artifact) {
            try {
                $installer->install($website, $factory->fromWebsite($website));
                self::fail('Installation must fail');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('INSTALLATION_FAILED', $error->getMessage());
            }
            self::assertSame(1, $updates);

            return;
        }
        self::assertTrue($installer->install($website, $factory->fromWebsite($website))->successful());
        self::assertSame(1, $updates);
        self::assertSame(2, $probes);
    }

    public static function dashboardCases(): array
    {
        return ['reuse and delayed TLS' => ['success'], 'unknown CRL' => ['unknown'], 'wrong key' => ['key'], 'TLS timeout' => ['timeout'], 'reload failure' => ['reload']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dashboardCases')]
    public function testDashboardRenewal(string $scenario): void
    {
        $d = self::$directory.'/dashboard-'.bin2hex(random_bytes(4));
        mkdir($d, 0700);
        mkdir($d.'/example.test_ecc', 0700);
        copy(self::$leaf, $d.'/example.test_ecc/fullchain.cer');
        copy(self::$directory.('key' === $scenario ? '/root.key' : '/leaf.key'), $d.'/example.test_ecc/example.test.key');
        $config = new \App\Config\Configuration('https://example.test:8080', 'user', 'test-secret', '/unused-acme', 'dns_ispconfig', 0, ['ns.example.test'],
            localServerId: 1, installationTimeout: 5, pollInterval: 1, stateDirectory: $d.'/state', acmeHome: '/custom-acme', acmeConfigHome: $d, caFile: self::$root,
            dashboardCertificatePath: $d.'/panel.crt', dashboardKeyPath: $d.'/panel.key', dashboardReloadCommand: 'test reload command');
        $runner = $this->createMock(\App\Process\ProcessRunnerInterface::class);
        $installs = 0;
        $probes = 0;
        $runner->method('run')->willReturnCallback(static function (array $command, ?int $timeout, bool $liveOutput) use ($d, $scenario, &$installs, &$probes): \App\Process\ProcessResult {
            self::assertNotContains('--issue', $command, 'Valid/unknown/unusable source must not cause unnecessary issuance.');
            if (\in_array('--install-cert', $command, true)) {
                ++$installs;
                self::assertFalse($liveOutput);
                self::assertSame($d, $command[array_search('--config-home', $command, true) + 1]);
                self::assertSame('/custom-acme', $command[array_search('--home', $command, true) + 1]);
                self::assertSame('test reload command', $command[array_search('--reloadcmd', $command, true) + 1]);
                if ('reload' === $scenario) {
                    throw new \RuntimeException('test-secret PRIVATE KEY');
                }
                copy(self::$leaf, $d.'/panel.crt');
                copy(self::$directory.'/leaf.key', $d.'/panel.key');

                return new \App\Process\ProcessResult(0, '', '');
            }
            if ('s_client' === $command[1]) {
                ++$probes;
                self::assertContains('example.test:8080', $command);

                return new \App\Process\ProcessResult(0, 'timeout' === $scenario || 1 === $probes ? '' : file_get_contents(self::$leaf), '');
            }

            return (new ProcessRunner())->run($command, $timeout, $liveOutput);
        });
        $reader = new CertificateReader();
        $manager = new \App\Acme\AcmeManager($config, $runner);
        $service = new \App\Service\DashboardCertificateService($config, $manager, $reader,
            new \App\Certificate\LiveCertificateReader($runner, $reader),
            new CertificateValidator($runner, new MockHttpClient(static fn () => new MockResponse('unknown' === $scenario ? 'invalid CRL' : self::$goodCrl)), 5, self::$root),
            new \App\Dns\NameserverChecker($config), new \App\Service\OperationGuard($config));
        $command = new \App\Command\RenewDashboardCertificateCommand($service, $config);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertTrue($command->getDefinition()->hasOption('force'));
        self::assertTrue($command->getDefinition()->hasShortcut('f'));
        self::assertTrue($command->getDefinition()->hasShortcut('y'));
        $tester->setInputs(['no']);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $installs);
        self::assertDirectoryDoesNotExist($d.'/state');
        $exit = $tester->execute(['--yes' => true]);
        self::assertStringNotContainsString('test-secret', $tester->getDisplay());
        self::assertStringNotContainsString('PRIVATE KEY', $tester->getDisplay());
        if ('success' === $scenario) {
            self::assertSame(0, $exit, $tester->getDisplay());
            self::assertSame(1, $installs);
            self::assertSame(2, $probes);
            self::assertStringContainsString('Vorhandenes Dashboard-Zertifikat', $tester->getDisplay());
        } else {
            self::assertSame(1, $exit);
            self::assertSame(\in_array($scenario, ['unknown', 'key'], true) ? 0 : 1, $installs);
        }
    }
}
