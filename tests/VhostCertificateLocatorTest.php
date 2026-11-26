<?php

namespace App\Tests;

use App\Certificate\VhostCertificateLocator;
use PHPUnit\Framework\TestCase;

final class VhostCertificateLocatorTest extends TestCase
{
    public static function unsupportedConfigurations(): array
    {
        return [
            ['<VirtualHost *:80>\n</VirtualHost>'],
            ["<VirtualHost *:443>\nSSLCertificateFile /a.crt\n</VirtualHost>"],
            ["<VirtualHost *:443>\nSSLCertificateFile /a.crt\nSSLCertificateFile /b.crt\nSSLCertificateKeyFile /a.key\n</VirtualHost>"],
            ["Include /other.conf\n<VirtualHost *:443>\nSSLCertificateFile /a.crt\nSSLCertificateKeyFile /a.key\n</VirtualHost>"],
            ["<VirtualHost *:443>\nSSLCertificateFile relative.crt\nSSLCertificateKeyFile /a.key\n</VirtualHost>"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedConfigurations')]
    public function testAmbiguousConfigurationDoesNotFallBack(string $content): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vhost-');
        try {
            file_put_contents($file, $content);
            $this->expectException(\RuntimeException::class);
            (new VhostCertificateLocator())->locate($file, '/old.crt', '/old.key');
        } finally {
            unlink($file);
        }
    }

    public function testDuplicateIpv4AndIpv6BlocksWithSamePair(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vhost-');
        try {
            $directives = "\nSSLCertificateFile \"/ssl/site cert.crt\"\nSSLCertificateKeyFile /ssl/site.key\n";
            file_put_contents($file, '<VirtualHost *:443>'.$directives.'</VirtualHost>'."\n".'<VirtualHost [::]:443>'.$directives.'</VirtualHost>');
            self::assertSame(['/ssl/site cert.crt', '/ssl/site.key'], (new VhostCertificateLocator())->locate($file, '/old.crt', '/old.key'));
        } finally {
            unlink($file);
        }
    }
}
