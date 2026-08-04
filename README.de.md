# ISPConfig ACME Manager

Ein modernes PHP-CLI-Tool zur Verwaltung von ACME-Zertifikaten für ISPConfig.

Der Fokus liegt auf einer vollständig automatisierten DNS-01-Zertifikatsverwaltung, ohne Änderungen am ISPConfig-Core vorzunehmen.

Alle Änderungen erfolgen ausschließlich über die offizielle ISPConfig Remote API und bleiben dadurch updatefähig.

> **Projektstatus:** Entwicklung (v0.1)

---

# Ziele

Dieses Projekt ersetzt die herkömmliche HTTP-01-Zertifikatsausstellung durch eine moderne DNS-01-Lösung.

Dadurch werden unter anderem folgende Probleme vermieden:

- Probleme mit der Secondary Validation von Let's Encrypt
- HTTP-Weiterleitungen
- Reverse Proxys
- Firewalls
- Mehrere Webserver
- CDN-Probleme

Zusätzlich ermöglicht DNS-01:

- Wildcard-Zertifikate
- Vollautomatische Verlängerungen
- Unterstützung verschiedener Zertifizierungsstellen

---

# Funktionen

## Bereits implementiert

- Symfony Console
- ISPConfig Remote API
- Website-Erkennung
- DNS-01 Zertifikatsplanung
- Let's Encrypt Unterstützung
- ACME-Befehlsvorschau
- Doctor-Funktion
- PHPStan Level Max
- Rector
- PHPUnit
- PHP-CS-Fixer

---

## Geplant

- Zertifikat automatisch ausstellen
- Zertifikat automatisch installieren
- SSL automatisch in ISPConfig speichern
- Apache automatisch neu laden
- Zertifikat verifizieren
- Zertifikate erneuern
- Alle Zertifikate erneuern
- Wildcard-Zertifikate
- ZeroSSL
- Google Trust Services
- SSL.com
- Buypass

---

# Voraussetzungen

- PHP 8.2 oder neuer
- Composer
- ISPConfig 3.3+
- acme.sh
- Apache
- DNS-Zonen in ISPConfig

---

# Installation

Repository klonen

```bash
git clone https://github.com/harz-web24/ispconfig-acme.git
```

Abhängigkeiten installieren

```bash
composer install
```

Konfiguration erstellen

```bash
cp config/example.php /etc/ispconfig-dns-cert.php
chmod 600 /etc/ispconfig-dns-cert.php
```

---

# Konfiguration

```php
<?php

return [

    'api_url' => 'https://server:8080/remote/json.php',

    'username' => 'acme-manager',

    'password' => 'passwort',

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

# Befehle

Verfügbare Befehle

```bash
php bin/ispconfig-dns-cert list
```

Vorschau

```bash
php bin/ispconfig-dns-cert preview example.de
```

Zertifikat ausstellen

```bash
php bin/ispconfig-dns-cert issue example.de
```

System prüfen

```bash
php bin/ispconfig-dns-cert doctor
```

---

# Architektur

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
ISPConfig API
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

# Projektstruktur

```
src/

Acme/
Certificate/
Command/
Config/
Domain/
ISPConfig/
Process/
Service/
```

---

# Entwicklung

Formatter

```bash
composer fix
```

Syntaxprüfung

```bash
composer lint
```

PHPStan

```bash
composer stan
```

Tests

```bash
composer test
```

Rector

```bash
composer rector
```

---

# Roadmap

## Version 0.1

- Grundgerüst
- Vorschau
- Doctor
- ISPConfig API

## Version 0.2

- Zertifikate ausstellen
- WebsiteUpdater
- Verify

## Version 0.3

- Renew
- Renew All
- Logging

## Version 0.4

- ZeroSSL
- Google Trust
- SSL.com
- Buypass

---

# Projektphilosophie

Dieses Projekt verfolgt drei grundlegende Ziele:

- Keine Änderungen am ISPConfig-Core
- Keine direkten Datenbankänderungen
- Vollständige Updatefähigkeit

Alle Änderungen erfolgen ausschließlich über die offizielle ISPConfig Remote API.

Dadurch bleibt das Projekt auch nach zukünftigen ISPConfig-Versionen wartbar.

---

# Maintainer

**Jens Wiecker**

Full Stack Developer, PHP & Symfony Enthusiast

🌐 https://wiecker.eu

---

# License

MIT License
