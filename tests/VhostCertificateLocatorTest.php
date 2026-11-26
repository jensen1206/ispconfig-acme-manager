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
            ["<VirtualHost *:443>\n<IfDefine TLS>\nSSLCertificateFile /a.crt\nSSLCertificateKeyFile /a.key\n</IfDefine>\n</VirtualHost>"],
            ["<IfDefine TLS>\n<VirtualHost *:443>\nSSLCertificateFile /a.crt\nSSLCertificateKeyFile /a.key\n</VirtualHost>\n</IfDefine>"],
            ["<VirtualHost *:443>\n<If true>\nSSLCertificateFile /a.crt\n</If>\nSSLCertificateKeyFile /a.key\n</VirtualHost>"],
            ["<VirtualHost *:443>\nSSLCertificateFile /a.crt\n<Else>\nSSLCertificateKeyFile /a.key\n</Else>\n</VirtualHost>"],
            ["<VirtualHost *:443>\nSSLCertificateFile /a.crt\nSSLCertificateKeyFile /a.key\n<If true>\nInclude /other.conf\n</If>\n</VirtualHost>"],
            ["<VirtualHost *:443>\nSSLCertificateFile /a.crt\nSSLCertificateKeyFile /a.key\n<If true>\n</IfDefine>\n</VirtualHost>"],
            ["<VirtualHost *:443>\nSSLCertificateFile /a.crt\nSSLCertificateKeyFile /a.key\n<If true>\n</VirtualHost>"],
            ["<VirtualHost *:443>\nSSLCertificateFile /a.crt\nSSLCertificateKeyFile /a.key\n<If true> SSLCertificateFile /b.crt </If>\n</VirtualHost>"],
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

    public function testIspconfigPhpConditionsDoNotHideExplicitTlsPair(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vhost-');
        $phpHandler = <<<'APACHE'
<IfModule mod_proxy_fcgi.c>
    <If "-f '%{REQUEST_FILENAME}'">
        SetHandler "proxy:unix:/run/php/site.sock|fcgi://localhost"
    </If>
</IfModule>
<IfModule mod_fastcgi.c>
    <If "-f '%{REQUEST_FILENAME}'">
        <IfDefine PHP_HANDLER>
            SetHandler php-script
        </IfDefine>
    </If>
    <ElseIf "-f '%{REQUEST_FILENAME}.php'">
        SetHandler php-script
    </ElseIf>
    <Else>
        SetHandler none
    </Else>
</IfModule>
APACHE;
        try {
            file_put_contents($file, "<VirtualHost *:80>\n".$phpHandler."\n</VirtualHost>\n"
                ."<VirtualHost *:443>\n<IfModule mod_ssl.c>\n"
                ."SSLCertificateFile /ssl/cms.wiecker.eu-le.crt\n"
                ."SSLCertificateKeyFile /ssl/cms.wiecker.eu-le.key\n</IfModule>\n"
                .$phpHandler."\n</VirtualHost>\n");
            self::assertSame(
                ['/ssl/cms.wiecker.eu-le.crt', '/ssl/cms.wiecker.eu-le.key'],
                (new VhostCertificateLocator())->locate($file, '/ssl/cms.wiecker.eu.crt', '/ssl/cms.wiecker.eu.key'),
            );
        } finally {
            unlink($file);
        }
    }
}
