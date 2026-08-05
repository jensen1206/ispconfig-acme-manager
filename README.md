# ISPConfig ACME Manager

A modern PHP CLI application for automated ACME certificate management on ISPConfig servers.

Unlike the default ISPConfig Let's Encrypt integration, ISPConfig ACME Manager focuses on **DNS-01 validation**, making certificate management more reliable while remaining fully compatible with future ISPConfig updates by using the official Remote API.

> **Project Status:** Active Development (v0.4.0)

---

# Features

## Implemented

- Modern Symfony Console CLI
- Official ISPConfig Remote API integration
- Website discovery
- DNS-01 certificate issuance
- Automatic certificate installation
- Automatic ISPConfig website update
- Automatic Let's Encrypt deactivation
- Local certificate verification
- Intelligent renewal
- Bulk renewal
- Dry Run mode
- External DNS detection
- Wildcard certificate support
- Certificate preview
- Environment diagnostics (`doctor`)
- Update debug mode
- Strong typing
- PHP Enums
- Value Objects
- PHPStan Level Max
- Rector
- PHPUnit
- PHP-CS-Fixer

---

# Commands

Show all commands

```bash
php bin/ispconfig-dns-cert list
```

Preview certificate creation

```bash
php bin/ispconfig-dns-cert preview example.com
```

Issue a certificate

```bash
php bin/ispconfig-dns-cert issue example.com
```

Verify a certificate

```bash
php bin/ispconfig-dns-cert verify example.com
```

Renew a single certificate

```bash
php bin/ispconfig-dns-cert renew example.com
```

Renew all managed certificates

```bash
php bin/ispconfig-dns-cert renew --all
```

Preview renewal without changing anything

```bash
php bin/ispconfig-dns-cert renew --all --dry-run
```

Update website certificate

```bash
php bin/ispconfig-dns-cert update example.com
```

Show update payload

```bash
php bin/ispconfig-dns-cert update-debug example.com
```

Run diagnostics

```bash
php bin/ispconfig-dns-cert doctor
```

---

# Workflow

The complete certificate lifecycle is fully automated.

```
Preview
    │
    ▼
Issue
    │
    ▼
DNS-01 Validation
    │
    ▼
Certificate Installation
    │
    ▼
ISPConfig Remote API Update
    │
    ▼
Automatic Let's Encrypt Disable
    │
    ▼
Certificate Verification
```

---

# Intelligent Renewal

Certificates are only renewed when necessary.

The renewal process automatically checks

- Certificate validity
- Remaining lifetime
- Private key
- Subject Alternative Names
- Certificate files
- External DNS management

Example

```bash
php bin/ispconfig-dns-cert renew --all --dry-run
```

Example output

```
✔ wiecker.eu              valid
✔ art-picturedesign.de    valid
⚠ cms.wiecker.eu          would renew
⏭ harz-web24.de          external DNS
```

No certificates are requested during a dry run.

---

# External DNS Detection

Domains whose DNS is not managed by ISPConfig are automatically detected.

Example

```
harz-web24.de

Nameservers

ns81.domaincontrol.com
ns82.domaincontrol.com

Status

External DNS

Skipped
```

This prevents unnecessary ACME requests and avoids DNS validation failures.

---

# Requirements

- PHP 8.2+
- Composer
- ISPConfig 3.3+
- acme.sh
- Apache
- DNS zones managed by ISPConfig

---

# Installation

Clone the repository

```bash
git clone https://github.com/jensen1206/ispconfig-acme-manager.git
```

Install dependencies

```bash
composer install
```

Create the configuration

```bash
cp config/example.php /etc/ispconfig-dns-cert.php
chmod 600 /etc/ispconfig-dns-cert.php
```

---

# Configuration

```php
<?php

return [

    'server' => 'https://server.example.com:8080',

    'username' => 'acme-manager',

    'password' => 'change-me',

    'acme' => '/root/.acme.sh/acme.sh',

    'dns_provider' => 'dns_ispconfig',

    'dns_sleep' => 120,

    'managed_nameservers' => [

        'ns1.example.com',

        'ns2.example.com',

    ],

];
```

---

# Architecture

```
Symfony Console
        │
        ▼
Commands
        │
        ▼
Services
        │
        ├───────────────┐
        ▼               ▼
ISPConfig          Certificate
        │               │
        ▼               ▼
Remote API      ACME Manager
        │               │
        └──────┬────────┘
               ▼
        Process Runner
               │
               ▼
            acme.sh
```

---

# Design Goals

- No ISPConfig core modifications
- No direct database updates
- Official ISPConfig Remote API only
- Fully update compatible
- Object-oriented architecture
- Strong typing
- Readonly classes
- Value Objects
- Enums
- PSR-12
- PHPStan Level Max

---

# Development

Format code

```bash
composer fix
```

Run syntax checks

```bash
composer lint
```

Run static analysis

```bash
composer stan
```

Run tests

```bash
composer test
```

Run Rector

```bash
composer rector
```

---

# Current Roadmap

## Version 0.5

- Live certificate verification
- GitHub Actions
- Logging

## Version 0.6

- ZeroSSL support
- Google Trust Services
- SSL.com
- Buypass

## Version 1.0

- Stable release
- Packagist
- Complete documentation

---

# Contributing

Contributions, ideas and bug reports are welcome.

Please open an issue before implementing larger features.

---

# License

MIT License

---

# Maintainer

**Jens Wiecker**

Full Stack Developer

🌐 https://wiecker.eu
