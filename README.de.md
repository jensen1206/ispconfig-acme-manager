# ISPConfig ACME Manager 1.0.5

PHP-CLI für DNS-01-Zertifikate mit acme.sh und der ISPConfig Remote API. PHP 8.2+, PHP-OpenSSL, OpenSSL CLI und die bestehenden Composer-Abhängigkeiten werden benötigt.

## Upgrade von 0.3.0

Vor dem ersten schreibenden Aufruf in `/etc/ispconfig-dns-cert.php` `local_server_id` auf die ISPConfig-ID des ausführenden Webservers setzen. Der Standard `0` sperrt lokale Prüfungen und Änderungen. Fremde Server werden nur öffentlich geprüft; lokale Pfade werden nicht als fehlende Zertifikate interpretiert. Auf jedem Webserver mit eigener Konfiguration ausführen.

`src/Config/example.php` enthält alle Optionen. Vorhandene Schlüssel und CLI-Optionen bleiben erhalten. Keine Servereinstellung wird automatisch geändert.

| Option | Standard | Zweck |
|---|---|---|
| `local_server_id` | `0` | Explizite lokale Serverzuordnung |
| `installation_timeout` | `180` | Wartefrist nach API-Aktualisierung, Sekunden |
| `poll_interval` | `5` | Abstand zwischen Prüfungen |
| `network_timeout` | `15` | Zeitbudget einer Zertifikatsprüfung/TLS-Verbindung |
| `retry_cooldown` | `86400` | Mindestabstand gleicher Aktionen, Sekunden |
| `state_directory` | `/var/lib/ispconfig-acme-manager` | Sperren und Versuchstimestamps, ohne Schlüssel/Zugangsdaten |
| `acme_home` | `/root/.acme.sh` | acme.sh-Programmverzeichnis |
| `acme_config_home` | `/root/.acme.sh` | Account- und Zertifikatsbestand |
| `automatic_repair` | `false` | Wiederinstallation bei Abweichungen ausdrücklich freigeben |
| `vhost_directory` | `/etc/apache2/sites-available` | Lesende Prüfung neuer `.vhost.err`-Dateien |
| `ca_file` | Systemstandard | Optionaler OpenSSL-Vertrauensspeicher |

`automatic_repair` nur aktivieren, wenn öffentliches TLS auf diesem Webserver terminiert. Bei CDN, Loadbalancer oder TLS-Reverse-Proxy deaktiviert lassen. Proxy- und Apache-Direktiven werden aus dem aktuellen API-Datensatz unverändert übernommen. Gleichzeitige administrative Website-Änderungen während eines vollständigen API-Updates sollten vermieden werden.

## Befehle

```sh
php bin/ispconfig-dns-cert doctor
php bin/ispconfig-dns-cert preview example.org
php bin/ispconfig-dns-cert verify example.org
php bin/ispconfig-dns-cert watch --all --dry-run
php bin/ispconfig-dns-cert watch --all --cron
php bin/ispconfig-dns-cert issue example.org --yes
php bin/ispconfig-dns-cert update example.org --yes
php bin/ispconfig-dns-cert renew --all --days 30 --yes
php bin/ispconfig-dns-cert update-debug example.org
```

`verify` zeigt lokale und öffentliche Befunde getrennt. Geprüft werden Zeitfenster, SAN-Abdeckung einschließlich Wildcards, Schlüsselzuordnung, Vertrauenskette/Serverzweck, CRL, Seriennummer und SHA256-Fingerprint. Konkrete Hostnamen werden mit SNI abgefragt; Wildcards werden nicht als Netzwerkadresse benutzt. Pro Hostname wird die vom Resolver ausgewählte Verbindung geprüft, keine vollständige Loadbalancer-Inventarisierung.

Statuswerte: `VALID`, `EXPIRING`, `EXPIRED`, `REVOKED`, `REVOCATION_UNKNOWN`, `HOSTNAME_MISMATCH`, `CERTIFICATE_MISMATCH`, `CERTIFICATE_MISSING`, `HTTPS_UNAVAILABLE`, `INSTALLATION_FAILED`, `EXTERNAL_DNS`, `CONNECTION_ERROR`. Zusätzlich `CERTIFICATE_INVALID` für fehlerhafte/noch nicht gültige Zertifikate und `NOT_CHECKED` für nicht ausgeführte Teilprüfungen. Mehrere Fehler bleiben im Prüfbericht erhalten.

CRL-Prüfung betrifft das Leaf-Zertifikat. OpenSSL prüft Signatur, Zuordnung und Gültigkeit der CRL. Nicht erreichbare, fehlende oder ungültige CRLs ergeben **REVOCATION_UNKNOWN**, niemals REVOKED. Es gibt keinen OCSP-Fallback. CRLs werden nur von öffentlichen IPv4-Zielen über HTTP(S), ohne Redirects, mit festgelegter DNS-Auflösung und Größenlimit geladen. IPv6-only-CRL-Ziele bleiben unbekannt. Ein kurzer Cache existiert nur im Prozess; OpenSSL prüft die CRL-Gültigkeit bei jeder Verwendung erneut.

`issue` und `update` melden Erfolg erst, wenn das erwartete Zertifikat öffentlich verifiziert wurde. Es wird kein manueller Apache-Reload mehr vor der ISPConfig-Verarbeitung ausgeführt. Ohne verifizierte Jobstatus-API dient TLS-Polling als Bestätigung der wirksamen Installation; es beweist keinen bestimmten internen Jobabschluss. Neue lesbare `.vhost.err`, `.crt.err`, `.key.err` führen zum Fehler. Fehlende Dateirechte begrenzen diese Zusatzdiagnose. Betriebssystem-DNS-Auflösung kann außerhalb der kontrollierten Prozess-/HTTP-Zeitlimits liegen.

`renew` und `watch` erkennen Widerruf unabhängig von der Restlaufzeit. Installationsprobleme verwenden nach Möglichkeit vorhandene gültige Zertifikate. Unbekannter Widerrufsstatus und reine Verbindungsfehler lösen keine Neuausstellung aus. Externe DNS-Zonen und CNAME-Challenge-Delegationen werden nicht automatisch bearbeitet.

Versuche werden vor der Aktion gespeichert. Fehlversuche, Rate-Limit-Antworten und Prozessabbrüche unterliegen derselben Sperrfrist. `--force` umgeht diese Sperre nicht. Installation eines anderen Fingerprints wird separat behandelt. Der Manager verändert keine ACME-Cronjobs oder alten Reload-Hooks; bestehende acme.sh-Automatisierung muss beim Upgrade auf konkurrierende Aktionen geprüft werden.

Dry-Run liest und prüft, führt aber keine Ausstellung, Installation, API-Änderung oder persistente Aktionsänderung aus. Temporäre öffentliche Zertifikats-/CRL-Dateien werden für OpenSSL benötigt und anschließend entfernt.

Exit-Codes: `0` erfolgreicher Lauf/keine Aktion, `1` Prüf- oder Installationsfehler einschließlich erforderlicher unbekannter Widerrufsprüfung, `2` ungültige CLI-Optionen. `EXPIRING` allein ist bei `verify` kein Fehler; die verbleibende Laufzeit steuert `renew`/`watch`.

## Doctor und Cleanup

Doctor prüft OpenSSL-CRL-Unterstützung und die lokale Serverzuordnung. Derzeit ist keine versionsübergreifend verifizierte Cleanup-Leseschnittstelle integriert: Doctor meldet dies ausdrücklich als Warnung und zeigt die manuell pro Webserver zu prüfenden Werte `le_auto_cleanup=n`, `le_revoke_before_delete=n`. `le_delete_on_site_remove` separat bewerten. Es werden keine Serverkonfigurationen oder Datenbanken geschrieben und keine Session-IDs ausgegeben.

## Separater ACME-Bestand

Ein dediziertes `acme_config_home` ist für neue Installationen sinnvoll. Das Standardverzeichnis bleibt aus Kompatibilitätsgründen erhalten. Keine automatische Migration: Accounts, DNS-Zugangsdaten, vorhandene Zertifikate, Rechte und Renewal-Hooks müssen gemeinsam übertragen/geprüft werden. Ein anderer Pfad ist keine Garantie gegen ISPConfig-Cleanup.

## Entwicklung

```sh
composer install
composer validate --strict
composer lint
composer stan
composer test
```

Tests erzeugen ausschließlich eine temporäre lokale Test-CA, Testschlüssel und CRLs. HTTP/API/TLS-Endpunkte werden simuliert; keine produktive ACME-Ausstellung oder Widerrufe. Die Tests erfordern das OpenSSL-CLI im PATH. Vor Produktiveinsatz zusätzlich einen isolierten Debian-12-/ISPConfig-Abnahmetest durchführen.

## Dashboard-Zertifikat (ab 1.0.3)

Die zusätzliche Serverfunktion `dashboard:renew` wurde aus der bisherigen Installation übernommen. Die Optionen `--force`/`-f` und `--yes`/`-y` bleiben erhalten. Hostname und TLS-Port stammen aus `server` (zum Beispiel HTTPS-Port 8080); ohne expliziten Port wird 443 verwendet.

Die Schlüssel `dashboard_certificate`, `dashboard_key` und `dashboard_reload_command` werden wieder ausgewertet. Standardziele sind `/usr/local/ispconfig/interface/ssl/ispserver.crt` und `ispserver.key`, Standard-Reload ist `systemctl reload apache2`. Der vertrauenswürdige, administrativ konfigurierte Reload-Befehl wird als einzelnes Argument an acme.sh übergeben. Dieser Panel-Befehl installiert direkt per acme.sh und verwendet keine Website-API. Er muss auf dem tatsächlichen Panel-Server ausgeführt werden; `server` darf dabei nicht auf ein entferntes Panel zeigen.

Vor dem Installieren werden ACME-Zertifikat, SAN, Kette, CRL und Schlüssel geprüft. Ein gültiger, ausreichend lang laufender ACME-Bestand wird wiederverwendet. Unbekannter Widerrufsstatus führt ohne explizites `--force` zu keiner Neuausstellung. Nach Installation/Reload werden Zieldateien und das öffentliche Zertifikat mit konfigurierbarem Timeout geprüft. Eine erfolgreiche acme.sh-Rückgabe allein ist kein Installationserfolg. Es gelten dieselben Sperren und Cooldowns wie für Website-Aktionen, mit einer getrennten Dashboard-Kennung. `--force` umgeht diese Sperren nicht.

`dashboard:renew` verändert Panel-Zertifikatsdateien und kann den konfigurierten Dienst neu laden. Es ist kein Diagnosebefehl und wird nicht automatisch von `watch` ausgeführt. Bei einem Fehler erfolgt keine automatische Wiederholung oder Rücksetzung. Bereits bestehende acme.sh-Cronjobs und gespeicherte Reload-Hooks bleiben bestehen.

## VHost-Dateiauswahl (ab 1.0.4)

Bei lesbarer generierter HTTPS-VHost-Konfiguration verwendet die lokale Prüfung deren explizite `SSLCertificateFile`- und `SSLCertificateKeyFile`-Pfade als Paar. Zusätzliche alte `.crt`-/`.key`-Dateien beeinflussen diesen Befund nicht. Die Live-Prüfung bleibt notwendig: Eine Konfigurationsdatei allein beweist nicht, dass Apache sie geladen hat.

Includes, Makros, dynamische Bedingungen, mehrere unterschiedliche Paare oder fehlende Zuordnungen werden konservativ als nicht eindeutig prüfbar gemeldet. Ohne VHost-Datei bleibt die konventionelle Dateisuche nur erlaubt, wenn keine konkurrierenden normalen und `-le`-Dateien vorliegen. ACME-Quellprüfungen bleiben hiervon getrennt. Bei Wiederinstallation wird ausschließlich das validierte Paar verwendet; generierte VHosts werden nicht verändert.
