# ISPConfig ACME Manager

A modern PHP CLI application for automated ACME certificate management on ISPConfig servers.

Unlike traditional HTTP-01 workflows, ISPConfig ACME Manager focuses on **DNS-01 validation**, making certificate management more reliable while remaining fully compatible with future ISPConfig updates by using the official Remote API.

> **Project Status:** Early Development (v0.1.0)

---

# Why?

The default Let's Encrypt integration in ISPConfig relies on HTTP-01 validation.

While this works for many environments, it can become problematic when using:

- Reverse proxies
- CDNs
- Firewalls
- Multiple web servers
- Strict redirect rules
- Let's Encrypt secondary validation

DNS-01 validation eliminates these limitations and additionally enables wildcard certificates.

---

# Features

## Implemented

- Modern Symfony Console CLI
- Official ISPConfig Remote API integration
- Website discovery by domain
- DNS-01 certificate planning
- Let's Encrypt support
- ACME command generation
- Environment diagnostics (`doctor`)
- Certificate preview
- Fully object-oriented architecture
- Value Objects
- PHP Enums
- PHPStan Level Max
- Rector
- PHPUnit
- PHP-CS-Fixer

---

## Planned

- Automatic certificate issuance
- Automatic certificate installation
- Automatic ISPConfig SSL updates
- Automatic Apache reload
- Certificate verification
- Certificate renewal
- Renew all websites
- Wildcard certificate management
- ZeroSSL support
- Google Trust Services support
- SSL.com support
- Buypass support

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
git clone https://github.com/harz-web24/ispconfig-acme.git
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

Example configuration

```php
<?php

return [

    'api_url' => 'https://server.example.com:8080',

    'username' => 'acme-manager',

    'password' => 'secret',

    'acme' => '/root/.acme.sh/acme.sh',

    'dns_provider' => 'dns_ispconfig',

    'dns_sleep' => 120,

];

```

## ISPConfig Remote API

The Remote API must be enabled.

Example endpoint:

https://server.example.com:8080/remote/json.php

Create a dedicated Remote User with the required permissions:

- Websites Domain Functions
- DNS Zone Functions
- DNS TXT Functions (if required)

Do **not** use your ISPConfig administrator account.


---

# Commands

Show available commands

```bash
php bin/ispconfig-dns-cert list
```

Preview certificate generation

```bash
php bin/ispconfig-dns-cert preview example.com
```

Issue a certificate

```bash
php bin/ispconfig-dns-cert issue example.com
```

Run diagnostics

```bash
php bin/ispconfig-dns-cert doctor
```

---

# Architecture

```
Symfony Console
        │
        ▼
CertificateService
        │
        ▼
WebsiteRepository
        │
        ▼
ISPConfig Remote API
        │
        ▼
Website
        │
        ▼
CertificateFactory
        │
        ▼
Certificate
        │
        ▼
AcmeManager
        │
        ▼
ProcessRunner
        │
        ▼
acme.sh
```

---

# Project Structure

```
src/

├── Acme
├── Certificate
├── Command
├── Config
├── Domain
├── ISPConfig
├── Process
└── Service
```

---

# Development

Run PHP-CS-Fixer

```bash
composer fix
```

Run static analysis

```bash
composer stan
```

Run syntax checks

```bash
composer lint
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

# Design Goals

The project follows a few simple principles:

- No ISPConfig core modifications
- No direct database updates
- Fully update-compatible
- Official ISPConfig Remote API only
- Strong typing
- Readonly classes
- Value Objects
- Enums
- PSR-12 coding style
- PHPStan Level Max

---

# Roadmap

## Version 0.1

- Project foundation
- Preview command
- Doctor command
- ISPConfig Remote API integration

## Version 0.2

- Issue command
- WebsiteUpdater
- Certificate verification

## Version 0.3

- Renew command
- Renew all certificates
- Logging

## Version 0.4

- ZeroSSL
- Google Trust Services
- SSL.com
- Buypass

---

# Contributing

Contributions, ideas and bug reports are welcome.

Please open an issue before implementing larger changes to discuss the proposed solution.

---

# Maintainer

**Jens Wiecker**

Full Stack Developer, PHP & Symfony Enthusiast

🌐 https://wiecker.eu

---

# License

MIT License
---

# Support

If you discover a bug or have a feature request, please open an issue on GitHub.

Security-related issues should be reported privately before public disclosure.