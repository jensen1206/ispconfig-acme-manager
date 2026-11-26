# ISPConfig ACME Manager

[![CI](https://github.com/jensen1206/ispconfig-acme-manager/actions/workflows/ci.yml/badge.svg)](https://github.com/jensen1206/ispconfig-acme-manager/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-black?logo=symfony)](https://symfony.com/)
[![License](https://img.shields.io/github/license/jensen1206/ispconfig-acme-manager)](LICENSE)
[![Release](https://img.shields.io/github/v/release/jensen1206/ispconfig-acme-manager)](https://github.com/jensen1206/ispconfig-acme-manager/releases)
[![Issues](https://img.shields.io/github/issues/jensen1206/ispconfig-acme-manager)](https://github.com/jensen1206/ispconfig-acme-manager/issues)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/Code%20Style-PSR--12-blue)](https://www.php-fig.org/psr/psr-12/)

A modern PHP CLI application for automatic ACME certificate management on ISPConfig using the official Remote API.

## Status

**Version 1.0.5**

Upgrade: set `local_server_id` before mutation. Automatic repair is opt-in (`automatic_repair`). See [configuration, safety policy and verification limits](README.de.md). The manager now validates CRLs and confirms installation through live TLS polling. Cleanup settings remain read-only/manual; no production integration test has been performed.

## Features

- Automatic certificate issuance
- Automatic renewal
- Automatic installation
- Local verification
- Live verification
- Watch workflow
- Cron mode
- Dry Run
- External DNS detection
- GitHub Actions
- PHPUnit
- PHPStan
- Rector

## Quick Start

```bash
composer install
php bin/ispconfig-dns-cert doctor
php bin/ispconfig-dns-cert watch example.com
```

### Verify your installation

```bash
php bin/ispconfig-dns-cert doctor
```

### Manage a single website

Automatically issue or renew the certificate if required.

```bash
php bin/ispconfig-dns-cert watch example.com
```

### Check all websites

Verify all managed websites and automatically issue or renew certificates when required.

```bash
php bin/ispconfig-dns-cert watch --all
```

### Dry Run

Preview all required actions without making any changes.

```bash
php bin/ispconfig-dns-cert watch --all --dry-run
```

### Automated Cron Mode

Run the watcher in unattended mode.

```bash
php bin/ispconfig-dns-cert watch --all --cron
```

> [!TIP]
> **Recommended Workflow**
>
> The `watch` command is the recommended way to manage certificates.
>
> It automatically decides whether to:
>
> - issue a new certificate when required
> - renew an existing certificate when required
> - leave the current certificate unchanged
>
> For most users, the following commands are all that is needed:
>
> ```bash
> php bin/ispconfig-dns-cert watch example.com
> php bin/ispconfig-dns-cert watch --all
> php bin/ispconfig-dns-cert watch --all --dry-run
> php bin/ispconfig-dns-cert watch --all --cron
> ```
>
> The `issue` and `renew` commands are available for advanced or manual workflows. For most users, the `watch` command is the recommended entry point.


## Commands

- doctor
- preview
- issue
- verify
- renew
- watch
- update
- update-debug

## Recommended Cron

```cron
0 3 * * * flock -n /var/run/ispconfig-dns-cert.lock bash -c 'cd /opt/ispconfig-dns-cert && /usr/bin/php bin/ispconfig-dns-cert watch --all --cron >> /var/log/ispconfig-dns-cert-cron.log 2>&1'
```

## Development

```bash
composer validate --strict
composer check
```

## Roadmap

- Final documentation
- Production validation
- Stable release

## License

MIT

## Maintainer

**Jens Wiecker**

https://wiecker.eu
