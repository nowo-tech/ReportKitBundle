# Installation

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Enable the bundle](#enable-the-bundle)
- [Database](#database)
- [Next steps](#next-steps)

## Requirements

- PHP 8.2 to 8.5 (Symfony 8.x needs PHP 8.4+).
- Symfony 7.4, 8.0 or 8.1 (CI covers 7.4 and 8.1).
- The package is built slice by slice (S1 today: model and renderers): see [ROADMAP.md](ROADMAP.md) before you depend on it.
- Optional engines (Q-O3): `dompdf/dompdf ^3.1` for PDF, `openspout/openspout ^4.28 || ^5.0` for XLSX; CSV needs nothing.
- A translator (`framework.translator`) and, for the PDF, `symfony/twig-bundle`.
- Twig Extra (REQ-TWIG-004): the package requires `twig/extra-bundle` and `twig/string-extra`; enable
  `Twig\Extra\TwigExtraBundle\TwigExtraBundle` (Flex does it) in every app that renders `@NowoReportKit/...` templates.

## Install

```bash
composer require nowo-tech/report-kit-bundle
```

With Symfony Flex the recipe registers the bundle and copies `config/packages/nowo_report_kit.yaml` with
`enabled: false`. Without Flex, register it yourself:

```php
// config/bundles.php
return [
    // ...
    Nowo\ReportKitBundle\NowoReportKitBundle::class => ['all' => true],
];
```

## Enable the bundle

No service is registered while `nowo_report_kit.enabled` is false (the default), except the route loader
`nowo_report_kit_ui`, which then loads no route (the Flex recipe imports it in `config/routes/nowo_report_kit.yaml`).
With `enabled: true` the S1 and S2 services are registered, and the report pages with `web_ui.enabled: true` (see
[USAGE.md](USAGE.md#report-pages-s2)); in both cases the bundle validates its configuration and publishes these
container parameters:

| Parameter | Value |
| --- | --- |
| `%nowo_report_kit.enabled%` | `enabled` |
| `%nowo_report_kit.api_prefix%` | `api.prefix` (default `/api`): use it in `security.yaml` patterns so they follow one setting |
| `%nowo_report_kit.table_prefix%` | `doctrine.table_prefix` (default `nowo_`) |
| `%nowo_report_kit.config%` | the processed configuration |

See [CONFIGURATION.md](CONFIGURATION.md) for every key.

## Database

The bundle owns its tables and maps them in XML by default (plain domain classes, referenced by id). It ships **no
Doctrine migration classes**: standard hosts run `php bin/console doctrine:migrations:diff` after an upgrade that adds
or changes a table (the reference DDL per database and `nowo:report-kit:schema-check` come with S5).

S3 adds three tables while the subscriptions are on (`subscriptions.enabled`, `auto` = with DoctrineBundle and an owner
directory): `<prefix>report_subscription`, `<prefix>report_run` (unique `(subscription_id, slot_at)`) and
`<prefix>report_owner_lock`. Identifier columns (ids, owner type and id, type key, claim token) use a binary NO PAD
collation on MySQL (`utf8mb4_0900_bin`) / MariaDB (`utf8mb4_nopad_bin`), so ids compare exactly; set
`doctrine.dbal.server_version` with the full version (`8.4.0`, `11.4.0-MariaDB`). PostgreSQL and SQLite compare exactly
by default. With MigrationsKitBundle installed, `nowo:migrations:check-key-collations` asserts them (REQ-SEC-006).

DDD hosts set `doctrine.auto_mapping: false` and register the bundle's XML mapping on their own entity manager (see
[USAGE.md](USAGE.md#using-it-from-a-ddd--hexagonal-application)).

## Next steps

- [CONFIGURATION.md](CONFIGURATION.md): the full tree.
- [USAGE.md](USAGE.md): the integration paths (standard and DDD).
- [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): worker mode with the kernel kept alive.
