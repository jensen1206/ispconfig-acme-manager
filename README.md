# ISPConfig ACME Manager

![CI](https://github.com/jensen1206/ispconfig-acme-manager/actions/workflows/ci.yml/badge.svg)

A modern PHP CLI application that automatically issues, installs, verifies and renews ACME certificates for **ISPConfig** using the **official ISPConfig Remote API** and **DNS-01 validation**.

> **Project Status:** Production Ready (v0.7.0)

## Why?

ISPConfig ACME Manager is designed to work alongside ISPConfig without modifying the ISPConfig core.

### Features

- Official ISPConfig Remote API
- DNS-01 validation using acme.sh
- Automatic certificate installation
- Automatic ISPConfig update
- Automatic Let's Encrypt deactivation
- Local certificate verification
- Live certificate verification
- Intelligent renewal
- Bulk renewal
- Dry Run mode
- Cron mode
- External DNS detection
- Wildcard support
- Doctor command
- GitHub Actions
- PHPUnit
- PHPStan
- Rector
- PHP-CS-Fixer

## Commands

```bash
php bin/ispconfig-dns-cert doctor
php bin/ispconfig-dns-cert preview example.com
php bin/ispconfig-dns-cert issue example.com
php bin/ispconfig-dns-cert verify example.com
php bin/ispconfig-dns-cert renew example.com
php bin/ispconfig-dns-cert renew --all
php bin/ispconfig-dns-cert renew --all --dry-run
php bin/ispconfig-dns-cert renew --all --cron
php bin/ispconfig-dns-cert update example.com
php bin/ispconfig-dns-cert update-debug example.com
```

## Requirements

- PHP 8.2+
- Composer
- ISPConfig 3.3+
- acme.sh
- Apache
- Linux

## Installation

```bash
git clone https://github.com/jensen1206/ispconfig-acme-manager.git
cd ispconfig-acme-manager
composer install
cp config/example.php /etc/ispconfig-dns-cert.php
chmod 600 /etc/ispconfig-dns-cert.php
```

## Cron

```cron
0 3 * * * flock -n /var/run/ispconfig-dns-cert.lock bash -c 'cd /opt/ispconfig-dns-cert && /usr/bin/php bin/ispconfig-dns-cert renew --all --cron >> /var/log/ispconfig-dns-cert-cron.log 2>&1'
```

Example output:

```text
[SUMMARY]
checked=30
valid=28
renewed=0
ssl_disabled=1
external_dns=1
failed=0
duration=0.92 s
```

## Development

```bash
composer validate --strict
composer lint
composer stan
composer test
composer check
composer rector
composer fix
```

## Roadmap

### v0.7
- Watch command
- Automatic certificate provisioning
- Production improvements

### v0.8
- Additional DNS providers

### v1.0
- Stable release
- Complete documentation
- Packagist

## License

MIT

## Maintainer

**Jens Wiecker**

https://wiecker.eu
