# Architecture

The design source of truth is [CONTRACT.md](CONTRACT.md) (contract 221-C). This page summarises the layout the slices
follow.

## Table of contents

- [Layers](#layers)
- [Architecture checks](#architecture-checks)
- [Two host kinds](#two-host-kinds)
- [S0 contents](#s0-contents)
- [S1 contents](#s1-contents)

## Layers

New bundles use layered namespaces (architecture bar rule 3):

| Namespace | Holds |
| --- | --- |
| `Nowo\ReportKitBundle\Contract` | Public ports: interfaces and value objects. No Doctrine, Symfony or HTTP type |
| `Nowo\ReportKitBundle\Domain` | Plain-PHP domain classes, mapped in XML (`src/Resources/config/doctrine/`) |
| `Nowo\ReportKitBundle\Application` | Bundle-implemented services behind the ports |
| `Nowo\ReportKitBundle\Infrastructure` | DBAL stores, Symfony integration (security, Messenger, console) |
| `Nowo\ReportKitBundle\Presentation` | Controllers, forms, Twig (UiKit + FormKit) |
| `Nowo\ReportKitBundle\Bridge` | Optional integrations, active when the other package is installed |

## Architecture checks

[Deptrac](https://github.com/deptrac/deptrac) checks the dependencies between the layers on every CI run (REQ-ARCH-002;
`deptrac.yaml`, `composer deptrac` / `make deptrac`, part of `make release-check`). A violation, a dependency on an
unlisted vendor namespace or a class under `src/` that belongs to no layer fails the build.

| Deptrac layer | Namespaces | May depend on |
| --- | --- | --- |
| `Domain` | `Contract`, `Domain` | itself, `psr/clock`, `psr/log`, `nowo-tech/transaction-contract`, `nowo-tech/brand-contracts`, `symfony/translation-contracts` |
| `Application` | `Application` | `Domain`, the same contract packages and `symfony/event-dispatcher-contracts` |
| `Infrastructure` | `Infrastructure` | `Domain`, `Application`, Symfony, Doctrine, Twig, transaction-doctrine, migrations-kit-contracts, UiKit / FormKit / ControllerKit, Dompdf / OpenSpout |
| `UI` | `Presentation` | `Domain`, `Application`, Symfony, Twig, UiKit / FormKit / ControllerKit |
| `DependencyInjection` | `DependencyInjection`, the bundle class | every layer (composition root) |

Domain and Application never import a Symfony component, Doctrine or Twig: what they need from the framework goes
through a port with an adapter in `Infrastructure` (for example `Domain\IdGeneratorInterface` →
`Infrastructure\Identity\UuidV7IdGenerator`). Two legacy dependencies of the public API are listed in
`skip_violations` until 1.0.0: `ReportKitConflictException` (transaction-doctrine conflict types, REQ-RUNTIME-003
rule 6) and the `?LocaleSwitcher` constructor argument of `ScheduledReportRunner`. The list may only shrink.

## Two host kinds

- **Standard Symfony host** (default, zero config): the bundle's XML mapping is prepended on the configured entity
  manager; the host references bundle rows by id and may read them through Doctrine.
- **DDD / hexagonal host** (opt-in): `doctrine.auto_mapping: false`, the XML mapping registered on the host's own
  entity manager, every host-side extension point implemented as an SPI interface, references by id only.

Both paths run the same functional suite from S1 on.

## S0 contents

`NowoReportKitBundle`, `DependencyInjection\NowoReportKitExtension`, `DependencyInjection\Configuration` (strict tree with every
contract key), `DependencyInjection\ConfigNodes` (shared node definitions), the empty XML mapping folder and the seven
translation catalogues.

## S1 contents

| Layer | Classes |
| --- | --- |
| `Contract` (ports + VOs) | `OwnerRef`, `ContextRef`, `ReportFormat`, `ParameterType`, `ParameterDefinition`, `ParameterSchema`, `ReportParameters`, `ReportRequest`, `ReportWindow`, `Report`, `ReportSection`, `RenderedFile`, `PdfOptions`; ports `ReportTypeInterface` (+ optional `AbstractReportType`), `ReportTypeRegistryInterface`, `ReportParametersResolverInterface`, `ReportExporterInterface`, `PdfDocumentRendererInterface`, `ReportBrandResolverInterface`, `ReportSettingsProviderInterface`; exceptions under `Contract\Exception` |
| `Application` | `ReportTypeRegistry`, `ReportParametersResolver`, `ConfigReportSettingsProvider`, `Brand\DefaultReportBrandResolver`, `Export\{ReportExporter, RenderBudget, RenderLimits, ReportFileName, ReportRendererInterface}`, `Chart\ReportChartSvg`, `Rendering\PdfSectionView` |
| `Infrastructure` | `Rendering\{Csv,Xlsx,Json,Pdf}ReportRenderer`, `Pdf\{ClosedDompdfOptions, DompdfDocumentRenderer, DompdfFontCacheWarmer, DompdfWarnings}`, `Csv\{BuiltIn,ControllerKit}CsvCellGuard`, `Filesystem\PrivateTempDirectory`, `Twig\TemplateCheckWarmer` |
| `Presentation` | `Http\RenderedFileResponder` |
| `DependencyInjection` | `Compiler\RenderingRequirementsPass` (translator required; Twig for the PDF) |

Flow: host controller → `ReportParametersResolverInterface::resolve()` → `ReportTypeInterface::build(ReportRequest)` →
`ReportExporterInterface::export()` (format check through the settings provider, time budget, file name) → renderer →
`RenderedFile` (a `php://temp` stream) → `RenderedFileResponder`.
