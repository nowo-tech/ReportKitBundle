# Usage

> **S0 scaffold.** Only the configuration exists today. This page describes the integration paths the slices build
> (contract 221-C §3–§4); each section says from which slice it works.

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## In a standard Symfony application

1. Write a report type (S1): a service implementing `ReportTypeInterface` (autoconfigured with the tag
   `nowo_report_kit.type`) returns its key, labels, `ParameterSchema` and an optional permission, and builds a `Report`
   from a `ReportRequest` with bounded aggregate queries over `$request->accessible` only.
2. Export it (S1): `ReportExporterInterface::export($report, ReportFormat::Csv)`; install `dompdf/dompdf` and / or
   `openspout/openspout` for PDF and XLSX.
3. Turn the pages on (S2): `web_ui.enabled: true` lists the types the viewer may open and renders each report with its
   filter form and Export menu.
4. Scheduled e-mails (S3): `schedule.enabled: true` (Symfony Scheduler recipe) or call the command from your own
   maintenance schedule; both only enqueue.

## Using it from a DDD / hexagonal application

| Concern | How a strict DDD host does it |
| --- | --- |
| Domain coupling | Your report types and application layer depend only on `Nowo\ReportKitBundle\Contract\*`: interfaces and `final readonly` value objects (`OwnerRef`, `ContextRef`, `ReportRequest`, `Report`, `ReportSection`, `SubscriptionView`, …). No Doctrine, Symfony or HTTP type appears in them; responses are built in the bundle's Presentation layer. |
| Owners | Implement `OwnerResolverInterface` and `OwnerDirectoryInterface` (e-mail, locale, time zone, active); subscriptions reference people by `OwnerRef`, never by a Doctrine relation. |
| Scope and access | Implement `ReportScopeResolverInterface` (contexts a viewer may pick) and `ReportAccessCheckerInterface` (contexts that grant a type's permission). |
| Mapping | Set `doctrine.auto_mapping: false` and register `vendor/nowo-tech/report-kit-bundle/src/Resources/config/doctrine` (type `xml`, prefix `Nowo\ReportKitBundle\Domain`) on your own entity manager. |
| Transactions | Subscriptions are written through DBAL in the bundle's own transaction; the bundle never flushes your EntityManager; events are dispatched after commit. |
| Deletion | No FK to your tables: call `SubscriptionsInterface::forgetContext()` when a project is deleted. |
| Branding and mail | `ReportBrandResolverInterface` (brand-contracts) and `ReportMailerInterface` replace the defaults. |
| Configuration | `ReportSettingsProviderInterface` replaces config values (no hard dependency on InstanceSettings). |

## FrankenPHP worker mode

No report, owner or scope is memoised across requests; Dompdf and OpenSpout work in a private per-call temporary
directory removed in `finally`. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
