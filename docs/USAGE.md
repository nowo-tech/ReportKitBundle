# Usage

> **S2 (pages).** Report types, the parameter schema, the four renderers, the closed PDF runner and the brand resolver
> (S1), plus the scope / access SPIs, the report builder and the report pages with export routes and rate limits (S2).
> Subscriptions come in S3 (contract 221-C, [ROADMAP.md](ROADMAP.md)).

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [Report pages (S2)](#report-pages-s2)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [The closed PDF runner for other documents](#the-closed-pdf-runner-for-other-documents)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## In a standard Symfony application

1. Turn the bundle on and install the engines you want (Q-O3):

   ```bash
   composer require dompdf/dompdf openspout/openspout   # PDF and XLSX; CSV needs nothing
   ```

   ```yaml
   nowo_report_kit:
       enabled: true
   ```

2. Write a report type: extend the optional `AbstractReportType` (or implement `ReportTypeInterface`); autoconfiguration
   tags it `nowo_report_kit.type`. Query **only** the contexts in `$request->accessible` (or the effective
   `$request->parameters->context`), with bounded aggregate queries.

   ```php
   use Nowo\ReportKitBundle\Contract\{AbstractReportType, ParameterSchema, Report, ReportRequest, ReportSection};

   final class OrdersReport extends AbstractReportType
   {
       public function __construct(TranslatorInterface $translator, private readonly OrderQueries $orders)
       {
           parent::__construct($translator);
       }

       public function key(): string { return 'orders'; }

       public function parameters(): ParameterSchema { return new ParameterSchema([7, 30, 90], 30); }

       public function build(ReportRequest $request): Report
       {
           $window = $this->window($request);
           $ids    = $request->parameters->context === null
               ? array_map(static fn ($c) => $c->id, $request->accessible)
               : [$request->parameters->context->id];

           return $this->report($request, [
               ReportSection::table('Orders per day', ['Day', 'Web', 'Shop'], $this->orders->perDay($ids, $window->from, $window->to), chart: ReportSection::CHART_SERIES),
           ]);
       }
   }
   ```

   `report()` writes the translated title (`report_type.orders.title` in your domain), the filters line and the file
   name base `orders-<context|all>-<date>` from the **effective** scope: when the requested context was not accessible
   it says "All accessible contexts (the requested context is not available)" and the file says `all` (audit ZE-I3).

3. Resolve the raw parameters and export from your controller:

   ```php
   $type       = $registry->get('orders') ?? throw $this->createNotFoundException();
   $accessible = [new ContextRef('project', '42'), new ContextRef('project', '43')];   // your access rules (S2: SPI)
   $parameters = $parametersResolver->resolve($type->parameters(), $request->query->all(), $accessible);
   $report     = $type->build(new ReportRequest(new OwnerRef('user', $user->getId()), $user, $parameters, $accessible, $request->getLocale(), 'Europe/Madrid', $clock->now()));

   return $responder->respond($exporter->export($report, ReportFormat::Pdf));   // private, no-store attachment
   ```

   `ReportExporterInterface::formats()` lists what can be exported right now. A format that is off throws
   `FormatNotEnabledException`; a render over `limits.render_seconds` (checked between sections and inside Dompdf at
   every page) or `max_pdf_html_bytes` throws
   `ReportLimitExceededException` (nothing is returned, every temporary file is already gone).

4. Branding: with brand-contracts' `BrandProviderInterface` in the container the files carry its name, accent colour and
   mark (as a data URI, through `BrandAssetReaderInterface` when you have one); a `ScopedBrandProviderInterface` gives
   each context its own brand. Without a provider the brand is neutral ("Reports").

## Report pages (S2)

1. Tell the bundle which contexts a person may report on and, for types with a `permission()`, which of them grant it:

   ```php
   use Nowo\ReportKitBundle\Contract\{ContextChoice, ContextRef, OwnerRef, ReportAccessCheckerInterface, ReportScopeResolverInterface};

   final class ProjectScope implements ReportScopeResolverInterface
   {
       public function accessibleContexts(OwnerRef $owner, object $user, string $type): array
       {
           return array_map(static fn (Project $p) => new ContextChoice(new ContextRef('project', (string) $p->getId()), $p->getName()), $this->projects->readableBy($user));
       }
   }

   final class ProjectPermissions implements ReportAccessCheckerInterface
   {
       public function allows(OwnerRef $owner, object $user, string $type, array $contexts): array
       {
           return array_values(array_filter($contexts, fn (ContextRef $c) => $this->acl->can($user, 'reports.' . $type, $c->id)));
       }

       public function allowsWithoutContext(OwnerRef $owner, object $user, string $type): bool
       {
           return false;   // instance-wide reports: your own rule
       }
   }
   ```

   ```yaml
   nowo_report_kit:
       enabled: true
       owner: { type: user, id_field: id }          # or owner.resolver: your OwnerResolverInterface
       scope: { resolver: App\Report\ProjectScope, access_checker: App\Report\ProjectPermissions }
       web_ui:
           enabled: true
           path_prefix: /reports
           security: { access_roles: [ROLE_USER] }
   ```

   The checker's answer is intersected with the scope (exact keys): it can narrow, never widen. Without a scope
   resolver every type runs without a context (types that require one are hidden). A type with a permission and no
   checker is refused, and `cache:warmup` fails so the gap shows at deploy time.

2. Import the routes and protect the prefix (REQ-UI-002, both layers):

   ```yaml
   # config/routes/nowo_report_kit.yaml
   nowo_report_kit:
       resource: .
       type: nowo_report_kit_ui
   # config/packages/security.yaml
   security:
       access_control:
           - { path: ^/reports, roles: ROLE_USER }
   ```

   | Route | Path | Answer |
   | --- | --- | --- |
   | `nowo_report_kit_index` | `GET /reports` | the types the viewer may open |
   | `nowo_report_kit_show` | `GET /reports/{type}?period=&context=&…` | the report page (`limits.views`) |
   | `nowo_report_kit_export` | `GET /reports/{type}/export.{csv\|xlsx\|pdf}` | the file, `no-store, private` (`limits.exports`) |

   Unknown type → 404; type not granted → 403; anonymous → your firewall's entry point; over a rate limit → 429 with
   `Retry-After`; a render over a limit → 503. A context the viewer may not read falls back to "all accessible" and the
   page says so.

3. React to decisions and exports: `ReportKitEvents::REPORT_ACCESS_CHECK` (`ReportAccessCheckEvent`: `grant()` opens
   every scope context, `deny()` refuses) and `ReportKitEvents::REPORT_EXPORTED` (`ReportExportedEvent`: owner, type,
   format, effective context, rows, bytes).

4. Build reports yourself with `ReportBuilderInterface` (`available()`, `prepare()`, `build()`): the same scope, access
   and parameter rules as the pages.

The pages extend UiKit's list and show scaffolds; the filter form uses FormKit's `filter` profile and UiKit's form
theme. Override the templates through `web_ui.templates.*` or the bundle override path ([TEMPLATES.md](TEMPLATES.md)).

## Using it from a DDD / hexagonal application

| Concern | How a strict DDD host does it |
| --- | --- |
| Domain coupling | Your report types and application layer depend only on `Nowo\ReportKitBundle\Contract\*`: interfaces and `final readonly` value objects (`OwnerRef`, `ContextRef`, `ReportRequest`, `ReportParameters`, `ParameterSchema`, `Report`, `ReportSection`, `RenderedFile`, `PdfOptions`, …). No Doctrine, Symfony component or HTTP type appears in them; the HTTP response is built by `Presentation\Http\RenderedFileResponder`. |
| Report types | Implement `ReportTypeInterface` directly (no base class, no translator needed) and tag the service `nowo_report_kit.type`. |
| Branding | `brand.resolver`: your `ReportBrandResolverInterface` service. |
| Runtime switches | `settings_provider`: your `ReportSettingsProviderInterface` service (formats on / off per call; no hard dependency on InstanceSettings). |
| Owners and contexts | `type + id` value objects, compared exactly (`===`); no relation to your entities. |
| Owner, scope, access | `owner.resolver`, `scope.resolver`, `scope.access_checker`, `web_ui.security.access_checker`: your own services implementing the contract interfaces (`tests/Fixtures/Ddd/LedgerAccess.php` does all four in one class). |
| Persistence | None in S1 / S2. From S3 subscriptions are bundle-owned rows written through DBAL in the bundle's own transaction; the bundle never flushes your EntityManager; events after commit. |

`tests/Integration/Host/HostKindsTest.php` runs the same export path on both host kinds; `tests/Integration/Web/ReportPagesTest.php` and `DddHostPagesTest.php` run the pages on both.

## The closed PDF runner for other documents

`PdfDocumentRendererInterface` (Q-O10) renders **any** Twig template (`render()`) or HTML you already rendered
(`renderHtml()`) with the same closed Dompdf setup (only `data:` URIs load, no remote, no PHP, no JavaScript,
private per-call chroot), the same `max_pdf_html_bytes` guard and a deadline (`PdfOptions::$deadline`, default now +
`limits.render_seconds`, enforced at every page). Paper (`A4`, `A3`, `letter`, `legal`) and orientation
(`landscape: true`) come from `PdfOptions`:

```php
$bytes = $pdf->render('billing/invoice.html.twig', ['invoice' => $invoice], new PdfOptions(
    paper: 'A4',
    footerLeft: 'Acme · Invoice 2026-0042',
    footerRight: 'Page {PAGE_NUM} of {PAGE_COUNT}',
    runningHeader: 'Invoice 2026-0042',
));
```

```php
$bytes = $pdf->renderHtml($invoiceHtml, new PdfOptions(paper: 'letter', landscape: true));
```

Embed images as data URIs (`data:image/png;base64,…`, e.g. `BrandAssetDataUri::fromBytes()`); a file path or a URL is
never read. Do not declare `@font-face` in PDF templates (see [TEMPLATES.md](TEMPLATES.md)).

## FrankenPHP worker mode

No report, owner, scope or brand is memoised across requests; every Dompdf / OpenSpout instance and every temporary
file lives inside one call (private directory removed in `finally`); Dompdf's static image cache and its global
warning list are emptied after each render; the translator's locale is never changed (texts are translated with the
report's locale explicitly). See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
