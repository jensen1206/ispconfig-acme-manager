<?php

namespace App\Tests;

use App\Certificate\CertificateFactory;
use App\Certificate\CertificateInfo;
use App\Certificate\CertificateReader;
use App\Certificate\CertificateStatus as S;
use App\Certificate\CertificateValidator;
use App\Certificate\LiveCertificateReader;
use App\Config\Configuration;
use App\Domain\Hostname;
use App\ISPConfig\ApiClient;
use App\ISPConfig\Website;
use App\ISPConfig\WebsiteRepository;
use App\Process\ProcessResult;
use App\Process\ProcessRunnerInterface;
use App\Service\CertificateVerificationService;
use App\Service\OperationGuard;
use App\Service\RenewalPolicy;
use App\Service\VerificationCheck;
use App\Service\VerificationReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SafetyTest extends TestCase
{
    private function config(string $state = ''): Configuration
    {
        return new Configuration('https://panel.example.test', 'test-user', 'test-password', '/not/acme.sh', 'dns_ispconfig', 0, ['ns.example.test'], localServerId: 1, stateDirectory: $state);
    }

    private function website(int $serverId = 1): Website
    {
        $host = new Hostname('example.test');

        return new Website(2, 1, $host, [$host], '/nonexistent-test-root', 'web2', 'client1', $serverId, true, false);
    }

    private function report(S $local, S $public = S::VALID): VerificationReport
    {
        $info = new CertificateInfo('example.test', 'test', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+60 days'), ['example.test'], 'fingerprint');

        return new VerificationReport($info, [
            new VerificationCheck('local', S::VALID === $local, '', $local),
            new VerificationCheck('public', S::VALID === $public, '', $public, 'public'),
        ]);
    }

    public function testNetworkAndUnknownRevocationNeverCauseIssuance(): void
    {
        foreach ([S::CONNECTION_ERROR, S::REVOCATION_UNKNOWN] as $status) {
            $decision = RenewalPolicy::decide($this->report(S::VALID, $status), $this->report(S::VALID), 30, true);
            self::assertFalse($decision->renewalRequired);
        }
        self::assertFalse(RenewalPolicy::decide($this->report(S::REVOCATION_UNKNOWN), $this->report(S::REVOCATION_UNKNOWN), 30, false)->renewalRequired);
    }

    public function testRevocationRequiresRenewalEvenWithSixtyDaysRemaining(): void
    {
        self::assertTrue(RenewalPolicy::decide($this->report(S::REVOKED), $this->report(S::REVOKED), 30, false)->renewalRequired);
    }

    public function testMismatchUsesExistingCertificateOnlyWhenRepairEnabled(): void
    {
        $report = $this->report(S::VALID, S::CERTIFICATE_MISMATCH);
        self::assertSame('reinstall', RenewalPolicy::decide($report, $this->report(S::VALID), 30, true)->status);
        self::assertFalse(RenewalPolicy::decide($report, $this->report(S::VALID), 30, false)->renewalRequired);
    }

    public function testApiErrorsNeverEchoResponseSecrets(): void
    {
        foreach ([new MockResponse('test-password PRIVATE KEY secret', ['http_code' => 500]), new MockResponse('test-password invalid JSON')] as $response) {
            $api = new ApiClient(new MockHttpClient($response), $this->config());
            try {
                $api->login();
                self::fail('Expected API failure');
            } catch (\App\ISPConfig\ApiException $error) {
                self::assertStringNotContainsString('test-password', $error->getMessage());
                self::assertStringNotContainsString('PRIVATE KEY', $error->getMessage());
            }
        }
    }

    public function testMissingLocalCertificateDoesNotSuppressPublicCheck(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->expects(self::once())->method('run')->willReturn(new ProcessResult(1, '', 'Connection refused'));
        $config = $this->config();
        $reader = new CertificateReader();
        $api = new ApiClient(new MockHttpClient(), $config);
        $service = new CertificateVerificationService(new WebsiteRepository($api), new CertificateFactory($config), $reader,
            new LiveCertificateReader($runner, $reader), new CertificateValidator($runner, new MockHttpClient()), $config);
        $report = $service->verifyWebsite($this->website());
        self::assertSame(S::CERTIFICATE_MISSING, $report->status('local'));
        self::assertSame(S::HTTPS_UNAVAILABLE, $report->status('public'));
    }

    public function testForeignServerNeverReadsLocalFiles(): void
    {
        $runner = $this->createMock(ProcessRunnerInterface::class);
        $runner->expects(self::never())->method('run');
        $config = $this->config();
        $reader = new CertificateReader();
        $service = new CertificateVerificationService(new WebsiteRepository(new ApiClient(new MockHttpClient(), $config)),
            new CertificateFactory($config), $reader, new LiveCertificateReader($runner, $reader),
            new CertificateValidator($runner, new MockHttpClient()), $config);
        self::assertSame(S::CERTIFICATE_INVALID, $service->verifyWebsite($this->website(99), false)->status('local'));
    }

    public function testFailedOperationPersistsCooldownAndReleasesLock(): void
    {
        $directory = sys_get_temp_dir().'/acme-guard-test-'.bin2hex(random_bytes(8));
        $guard = new OperationGuard($this->config($directory));
        try {
            try {
                $guard->run($this->website(), 'issue', static fn () => throw new \RuntimeException('simulated failure'));
            } catch (\RuntimeException $error) {
                self::assertSame('simulated failure', $error->getMessage());
            }
            try {
                $guard->run($this->website(), 'issue', static fn () => self::fail('Repeated issuance'));
                self::fail('Expected cooldown');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('retry_cooldown', $error->getMessage());
            }
            self::assertSame('installed', $guard->run($this->website(), 'install-new-fingerprint', static fn () => 'installed'));
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testDashboardConfigurationCompatibility(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dashboard-config-');
        try {
            $settings = ['server' => 'https://panel.example.test:8080', 'username' => 'test', 'password' => 'test',
                'acme' => '/custom/acme.sh', 'dns_provider' => 'dns_ispconfig', 'dns_sleep' => 120,
                'managed_nameservers' => ['ns.example.test'], 'dashboard_certificate' => '/custom/panel.crt',
                'dashboard_key' => '/custom/panel.key', 'dashboard_reload_command' => 'custom reload'];
            file_put_contents($path, '<?php return '.var_export($settings, true).';');
            $configuration = Configuration::fromFile($path);
            self::assertSame('panel.example.test', $configuration->dashboardHostname());
            self::assertSame(8080, $configuration->dashboardPort());
            self::assertSame('/custom/panel.crt', $configuration->dashboardCertificatePath);
            self::assertSame('/custom/panel.key', $configuration->dashboardKeyPath);
            self::assertSame('custom reload', $configuration->dashboardReloadCommand);
            self::assertSame(443, $this->config()->dashboardPort());
            unset($settings['dashboard_certificate'], $settings['dashboard_key'], $settings['dashboard_reload_command']);
            file_put_contents($path, '<?php return '.var_export($settings, true).';');
            $defaults = Configuration::fromFile($path);
            self::assertSame('/usr/local/ispconfig/interface/ssl/ispserver.crt', $defaults->dashboardCertificatePath);
            self::assertSame('/usr/local/ispconfig/interface/ssl/ispserver.key', $defaults->dashboardKeyPath);
            self::assertSame('systemctl reload apache2', $defaults->dashboardReloadCommand);
        } finally {
            unlink($path);
        }
    }
}
