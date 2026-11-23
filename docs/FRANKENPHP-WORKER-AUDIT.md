# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/report-kit-bundle` (`symfony-bundle`) |
| Audited revision | S1 model + renderers (unreleased, branch `feat/s1-model-renderers`) |
| Audit date | 2026-11-22 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`, 57 service files stateless); `WorkerRequestLoopTest` (one kernel, 2,000 requests, two owners alternating, memory drift < 1 MiB); `WorkerExportLoopTest` (one kernel, no services resetter, 500 alternating PDF / XLSX / CSV exports by two owners in two locales, memory drift < 4 MiB) |
| **Verdict** | ✅ **Viable** — every S1 service is `final readonly` and stateless; engines and temporary files live inside one call |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Rules for the next slices](#rules-for-the-next-slices)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once and serves many requests with the same container. The bundle must work
in the strictest case: **kernel kept alive and NOT reset** (`FRANKENPHP_RESET_KERNEL=false`), with no services
resetter between requests.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Every S1 service is `final readonly`; the registry's type list is built once from the container and never changes |
| `ResetInterface` / `kernel.reset` | n/a | No memo: brand, settings, scope and formats are resolved per call |
| Cross-owner leakage | ✅ | `WorkerRequestLoopTest` (2,000 requests) and `WorkerExportLoopTest`: every export holds only its owner's contexts, never the other owner's |
| Memory growth | ✅ | < 1 MiB over 2,000 requests; < 4 MiB over 500 exports (Dompdf, OpenSpout) |
| Static properties / `static` locals | ✅ | None (the bundle's extension instance is the Symfony boot-time cache, `@igor-ignore` with justification) |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None set by the bundle. Dompdf sets `LC_NUMERIC`, `pcre.jit` and the mbstring encoding during a render and restores them only when it ends normally; the runner and the font warmer take a snapshot before Dompdf and restore it in `finally` (`DompdfPhpState`, audit ZG-L2, tested with a render stopped by the deadline). Dompdf's **global `$_dompdf_warnings` list**, which it never empties, is emptied after every render (`DompdfWarnings::clear()`); its **static image cache** (`Image\Cache`) is cleared in `finally` even when a render fails |
| Translator locale | ✅ | Never changed: every text is translated with the report's locale passed explicitly; `WorkerExportLoopTest` asserts the default locale after each export |
| Doctrine | n/a | No table in S0; later slices write through DBAL in their own transactions and never keep managed entities |
| Resources held open | ✅ | One Dompdf / OpenSpout instance per call; the private directory (chroot, temp dir, OpenSpout temp folder) is removed in `finally`; `WorkerExportLoopTest` asserts the temp root is empty after each export. The rendered file is a `php://temp` stream that PHP frees with the `RenderedFile` |

### Dompdf function-static caches (audit ZG-L3)

Two caches inside Dompdf are function `static` variables the bundle cannot clear: `FontMetrics::getTextWidth()` keeps
the width of every distinct short text measured (≤ 50 bytes, per font and size) and `Helpers::dompdf_getimagesize()`
keeps one entry per image path (random per-call paths). Nothing in them reaches another owner (widths and sizes only),
but they grow with distinct content. `WorkerExportLoopTest` now varies the texts of every export and measured about
**300 bytes per export** (135 KB after 450 exports, PHP 8.2, dompdf 3.1.6), well under its 4 MiB bound.
Mitigation for long-lived workers: recycle them after N requests (FrankenPHP `max_requests` / the worker loop limit)
and run Messenger consumers (S3) with `--limit` / `--memory-limit`.

## Services reviewed

| Class | State | Verdict |
|-------|-------|---------|
| `NowoReportKitBundle` | Extension instance cached at boot | ✅ boot-time only |
| `DependencyInjection\NowoReportKitExtension`, `Configuration`, `ConfigNodes`, `Compiler\RenderingRequirementsPass` | Container build only | ✅ |
| `Application\ReportTypeRegistry` | Ordered type list, built in the constructor from tagged services | ✅ immutable |
| `Application\ReportParametersResolver`, `ConfigReportSettingsProvider`, `Brand\DefaultReportBrandResolver`, `Export\ReportExporter` | Configuration values and injected services only | ✅ |
| `Infrastructure\Rendering\{Csv,Xlsx,Json,Pdf}ReportRenderer`, `Pdf\DompdfDocumentRenderer` | None; engines per call, `RenderBudget` per call | ✅ |
| `Infrastructure\Pdf\DompdfFontCacheWarmer`, `Twig\TemplateCheckWarmer` | Warmup only | ✅ |
| `Presentation\Http\RenderedFileResponder` | None | ✅ |
| `Application\ReportBuilder`, `Scope\NoContextScopeResolver`, `Access\UnconfiguredReportAccessChecker`, `Owner\PropertyOwnerResolver` (S2) | Injected services and configuration only; scope, access and owner are read per call | ✅ |
| `Presentation\Http\ReportController`, `ReportKitGuard`, `ReportRateLimiter`, `Presentation\View\ScreenSections`, `Presentation\Form\ReportFilterType`, `Presentation\Routing\ReportRouteLoader` (S2) | None; the token is read per call, rate-limit counters live in the cache pool | ✅ |
| `Infrastructure\Security\{Configurable,AllowAll}ReportKitAccessChecker`, `Infrastructure\Cache\PermissionCheckerWarmer` (S2) | Configuration only / warmup only | ✅ |
| `Contract\Event\ReportAccessCheckEvent` (S2) | Mutable decision, one instance per build, never a service | ✅ |

`tests/Integration/Web/WorkerPagesLoopTest.php` (S2): one kernel kept alive, 300 alternating list / page / CSV
requests by two viewers in two locales; every answer holds only the viewer's own contexts and memory stays under
4 MiB of growth.

## Rules for the next slices

- No request, user, owner or tenant data in service properties, statics or process caches unless the bundle clears it
  at the request boundary (`kernel.request` / `kernel.terminate` / `kernel.finish_request`, and Messenger
  `WorkerMessageHandledEvent`); memos also implement `ResetInterface` with a test, never relied on alone.
- The bundle never flushes the host's EntityManager; writes use DBAL in an explicit transaction opened and closed in
  the call; a dropped connection is retried once.
- Rate-limit counters live in the cache pool, never in process memory.
- Every stateful path joins `WorkerRequestLoopTest` (N requests, two owners, flat memory).

## Re-audit triggers

Any new service, listener, memo, static, cache or Doctrine access; any change of the worker test.
