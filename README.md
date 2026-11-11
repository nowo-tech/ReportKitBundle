# Report Kit Bundle

[![CI](https://github.com/nowo-tech/ReportKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/ReportKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/report-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/report-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

**Reports and scheduled exports for Symfony: report types with parameter schemas, PDF / XLSX / CSV / JSON renderers, server-side SVG charts, on-screen report pages and per-owner scheduled e-mail subscriptions.**

> 🚧 **Status: S0 scaffold, no release yet.** The package is built slice by slice from its design contract
> ([docs/CONTRACT.md](docs/CONTRACT.md), 221-C). S0 ships the bundle class, the strict configuration tree and every
> quality gate; the features below land with the slices in [docs/ROADMAP.md](docs/ROADMAP.md).

> 📋 **Compatible with Symfony 7.4, 8.0 and 8.1** · PHP 8.2+ (Symfony 8.x requires PHP 8.4+)

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is built for **FrankenPHP worker mode with the kernel kept alive and not reset between requests**: no
request state in services, a worker test (one kernel, 2,000 requests) from the first slice.

**Standard Symfony by default, DDD / hexagonal opt-in:** zero configuration in a standard project; public ports are
interfaces and value objects, bundle-owned tables are mapped in XML and referenced by id, the bundle never flushes your
EntityManager.

## Features (planned, see the roadmap)

- **Report model**: `Report` and `ReportSection` (key values with delta, tone and sparkline; tables with optional chart
  kind and presentation style; truncation note), badges, glance line, link.
- **Report type registry** (tagged `ReportTypeInterface`) with a parameter schema (periods, context, typed extras),
  availability per viewer and a configurable menu order.
- **Renderers**: PDF (closed Dompdf: no remote, no JavaScript, no PHP, private chroot), XLSX (OpenSpout streaming), CSV
  (UTF-8 BOM, formula-injection guard), JSON, and server-side SVG charts with light / dark variants. Dompdf and
  OpenSpout are suggested: a format is on only when its engine is installed (Q-O3).
- **Generic PDF runner** `PdfDocumentRendererInterface` for any template (invoices, receipts, mandates).
- **Pages** on UiKit + FormKit: report list, report page (filter form, Export menu, *Schedule by e-mail* dialog).
- **Scheduled subscriptions**: daily / weekly / monthly at an hour in the owner's time zone, atomic claim, idempotent
  run per slot, built **as the owner** with an access re-check, branded mail to the owner only, auto-pause with a reason.
- **Operator page**, UserKit eraser and export, bridges for brand-contracts, MailKit, NotifyKit, AuditKit and
  ApiTokenBundle; optional public API `<prefix>/reports/v1` (off by default).

## Installation

```bash
composer require nowo-tech/report-kit-bundle
```

See [docs/INSTALLATION.md](docs/INSTALLATION.md). Nothing is registered while `nowo_report_kit.enabled` is false.

## Configuration

```yaml
# config/packages/nowo_report_kit.yaml
nowo_report_kit:
    enabled: false
```

Every key of the contract is already accepted (strict tree): [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md) (incl. using it from a DDD / hexagonal application)
- [Roadmap](docs/ROADMAP.md) (slices and open questions)
- [Contract](docs/CONTRACT.md)
- [Security](docs/SECURITY.md)
- [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [Upgrading](docs/UPGRADING.md) · [Changelog](docs/CHANGELOG.md) · [Release](docs/RELEASE.md)
- [Architecture](docs/ARCHITECTURE.md) · [PSR](docs/PSR.md) · [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md) · [Spec Kit](docs/SPEC-KIT.md)
- [Contributing](docs/CONTRIBUTING.md) · [GitHub CI](docs/GITHUB_CI.md) · [Engram](docs/ENGRAM.md)

## Requirements

- PHP >= 8.2, < 8.6
- Symfony 7.4, 8.0 or 8.1

## Demo

`demo/symfony8`: Symfony 8.1, FrankenPHP worker mode, MySQL, on http://localhost:8223 (`make -C demo up`).

## Tests and coverage

```bash
make test               # PHPUnit in Docker
make test-coverage-100  # fails below 100 % of src/
make release-check      # every gate, demo included
```

- PHP: 100 % of `src/` (gate in CI).

## License

MIT. See [LICENSE](LICENSE).

## Author

Created by [Héctor Franco Aceituno](https://github.com/HecFranco) at [Nowo.tech](https://nowo.tech).
