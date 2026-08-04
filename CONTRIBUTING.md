# Contributing

Thank you for your interest in contributing.

## Reporting Bugs

Please open a GitHub Issue and include:

- ISPConfig version
- PHP version
- Operating system
- acme.sh version
- Error output
- Steps to reproduce

## Feature Requests

Please open an Issue before implementing larger features.

## Pull Requests

Please ensure that the following commands complete successfully before opening a PR:

```bash
composer fix
composer lint
composer stan
composer test
```

All new code should:

- follow PSR-12
- pass PHPStan Level Max
- include appropriate typing
- avoid direct database modifications
- use the official ISPConfig Remote API
