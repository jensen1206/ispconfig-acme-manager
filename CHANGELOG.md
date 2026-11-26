# Changelog

All notable changes to this project will be documented in this file.

The format follows Keep a Changelog.

## [1.0.2] - 2026-11-26

### Added
- Separate local/public certificate status, chain and CRL validation, serial comparison.
- Bounded installation polling and detection of new ISPConfig error artifacts.
- Explicit local server ownership and opt-in certificate reinstallation.
- Persistent operation locks/cooldowns; no issuance for unknown revocation alone.
- Isolated cryptographic, API, installation and safety regression tests.

### Changed
- API acceptance alone no longer means installation success.
- Removed early Apache reload from issuance workflow.
- Doctor reports CRL support and manual cleanup-setting verification requirements.
- API errors and ACME diagnostics suppress potentially sensitive response content.

## [0.1.0] - 2026-08-04

### Added

- Symfony Console application
- ISPConfig Remote API integration
- Website discovery
- DNS-01 certificate planning
- Preview command
- Issue command (foundation)
- Doctor command
- Certificate domain model
- Process runner
- PHPStan Level Max
- Rector
- PHPUnit
- PHP-CS-Fixer
