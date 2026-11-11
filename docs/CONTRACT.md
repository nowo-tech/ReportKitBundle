> **Owner decision 2026-11-12:** extraction approved; every Q-O question below is answered with its
> recommendation. The Q-B questions (Beacon) stay open: see [ROADMAP.md](ROADMAP.md#open-questions-for-beacon).
> This copy is the design contract of the bundle; the code follows it slice by slice (S0 = scaffold only).

# Contract 221-C: reports and scheduled exports (ReportKitBundle). Contract v0.1 for symfony-beacon and the matrix apps

Status 2026-11-11: **v0.1, design only, awaiting the owner (Q-O) and Beacon (Q-B).** No code, no repository, no PR,
no tag. The owner approved the extraction on 2026-11-11 (Beacon request
`docs/ops/BUNDLES-REQUESTS/2026-11-11-reaudit-extraction-candidates.md`, item 8). Sibling contracts in this folder:
`API-TOKEN-CONTRACT.md` (221-A) and `NOTIFY-KIT-CONTRACT.md` (221-B). Q-O11 and Q-O12 of 221-A (mapping rule, shared
owner VO) also apply here.

Read for this contract (2026-11-11):

| Package / source | Version read | What was read |
| --- | --- | --- |
| symfony-beacon (read-only) | `master` `c7414f65f` | `src/Analytics/Report/**` (`Report`, `ReportSection`, `ReportFormat`, `ReportTypeRegistry`, `Type/{ReportType,AbstractReportType}`, `ReportExporter`, `ReportFilterResolver`, `{Pdf,Xlsx,Csv}ReportRenderer`, `ReportChartSvg`, `Schedule/{ReportFrequency,ReportSchedule,ScheduledReportRunner}`), `Analytics/Entity/ReportSubscription.php`, `Repository/ReportSubscriptionRepository.php`, `Controller/{Report,ReportSubscription,AdminReportSubscription,IssueReport}Controller.php`, `MessageHandler/RunScheduledReportHandler.php`, `Command/DispatchDueReportsCommand.php`, `Identity/PersonalData/Erasure/ReportSubscriptionsEraser.php`, `Billing/{Invoice/InvoicePdf,Receipt/BillingDocumentPdf}.php`, `templates/{report/pdf,dashboard/reports/*,admin/report_subscriptions/*}.html.twig`, `config/packages/{messenger,rate_limiter}.yaml`, `Shared/Scheduler/MaintenanceSchedule.php` |
| brand-contracts | v1.1.0 | `BrandProviderInterface`, `ScopedBrandProviderInterface` (`getBrandFor(BrandScope)`), `BrandAssetReaderInterface`, `BrandAssetDataUri`, `BrandColors` |
| MailKitBundle | v1.6.0 | `Brand/BrandedEmailHelper`, `Readiness/OutboundMailReadinessInterface`, `Log/SentMailErasureInterface` |
| ControllerKitBundle | as installed in Beacon | `Http/CsvStreamedResponse` (UTF-8 BOM, formula-injection guard) |
| UserKitBundle | v1.14.1 | `PersonalData/*` |
| UiKitBundle / FormKitBundle | v2.47.0 / installed | `ListPage`, `SettingsPage`, `_dialog`, filter profile |
| Rules | 2026-11-11 | `BUNDLES_FIX_RULES.md` (bar 1–8, worker mode, LQ, API paths, new-bundle bar), `BUNDLES_FULL_SPECS_DETAILS.md` (`REQ-RUNTIME-001` timeouts, `REQ-ORM-*`, `REQ-UI-*`), app REQs `REQ-QA-019` (CSV formula injection), `REQ-MESSENGER-004/006/007/008/009`, `REQ-DATA-001…004`, `REQ-AUTH-011` in `OTHER_FULL_SPECS_DETAILS.md` |

Facts that shaped the design:

1. **The generic part is a pipeline over a plain data model.** `Report` (title, filters line, sections, file name)
   and `ReportSection` (key figures with deltas and sparklines, tables, series, slices, styles) know nothing about
   Beacon; the renderers (PDF 187, XLSX 97, CSV 48, SVG charts 153) only read them. ≈ 970 lines are generic; the
   ten `Type/*Report.php` and the `*Queries.php` are the product and stay.
2. `ReportType::build(User $user, SummaryFilters $filters)` ties every type to Beacon's user and filter classes. The
   bundle needs a neutral request VO and a host SPI for "which contexts (projects) may this person report on".
3. **Scheduled runs are built as the owner, at run time** (current access; FR-021) and re-check the area permission
   (audit ACC-REPORT-SCHED-AREA). An owner who is disabled, anonymised or lost access gets nothing and the
   subscription pauses. Mail goes to the owner only, in the owner's locale, with links from `DEFAULT_URI`.
4. **Gaps found while reading** (the bundle fixes them, Beacon inherits the fixes):
   - The due-run claim is `SELECT` then `flush()` without a lock or conditional update: two overlapping scheduler
     ticks can claim the same row and send twice.
   - The run handler records `failed` and rethrows for a Messenger retry, but the mail may already have been sent:
     a retry can send the same report twice (no idempotency per slot).
   - `XlsxReportRenderer::bytes()` unlinks its temporary file outside a `finally`: an exception leaks a file per
     failed render in a long-lived worker.
   - Dompdf's chroot is the shared `sys_get_temp_dir()`; a private per-call directory is tighter.
   - A temporarily unready mailer (`mailReady` false) **pauses** every due subscription, which then needs a manual
     resume by each owner.
   - The send hour is fixed at 07:00 UTC for everyone; the file name prefix is `beacon-report`.
5. The same closed-Dompdf setup is copied three times (reports, invoices, billing documents). One shared
   `PdfDocumentRenderer` removes two copies.

---

## 1. Scope

**In (generic, moves out of Beacon):**

- **Report model:** `Report`, `ReportSection` (key values with delta, tone and sparkline; tables with optional
  chart kind `series` / `slices` and presentation style `details` / `trace` / `comments` / `tags`; truncation note),
  badges, glance line, link.
- **Report type registry** (tagged `ReportTypeInterface`), parameters schema (period choices, context choice, extra
  typed parameters), availability per viewer, menu order from config.
- **Renderers:** PDF (Dompdf, closed: no remote, no JavaScript, no PHP, private chroot, A4, brand header, footer with
  page numbers, running header), XLSX (OpenSpout streaming, one sheet per section, Excel-safe sheet names), CSV (one
  file, UTF-8 BOM, formula-injection guard, `REQ-QA-019`), JSON (for the optional API), and server-side SVG charts
  (stacked columns, sparklines, share bars) with light / dark variants.
- **Generic PDF document runner** (`PdfDocumentRendererInterface`) that any host template can use (Beacon invoices,
  receipts, SEPA mandates).
- **On-screen pages:** report list (types the viewer may open, own subscriptions), report page (same sections as the
  files, filter form, Export menu, *Schedule by e-mail* dialog), on UiKit + FormKit.
- **Subscriptions and scheduled runs:** frequency (daily, weekly on a weekday, monthly on day 1–28), hour and time
  zone, formats, pause / resume / delete, at most N per owner, atomic claim, idempotent run per slot, build as owner
  with access re-check, branded mail to the owner only, auto-pause with a reason, run history.
- **Operator page:** every subscription (owner, type, schedule, last run, status) with pause / resume, audited, never
  report content.
- **Personal data:** UserKit eraser and export provider for subscriptions and run history.
- **Bridges:** brand-contracts (logo, colours, name per context), MailKit (branded mail, readiness), NotifyKit
  (221-B: "your report is paused"), AuditKit, ApiTokenBundle (optional public API auth).

**Stays in Beacon (product-specific):** the ten report types (`ExecutiveSummaryReport`, `ProjectHealthReport`,
`ErrorTrendsReport`, `ResolutionReport`, `ReleasesReport`, `PerformanceReport`, `UptimeReport`, `TasksReport`,
`OperationsReport`, `WorkloadReport`), their query classes (`ReportQueries` 272, `TaskReportQueries` 290,
`UptimeReportQueries` 120), `IssueReportFactory` (176) and `SummaryReportFactory` (242), `AnalyticsAreaAccess` (the
area permission map), the billing templates and their data, the demo seeder.

**Non-goals (v1):** no report designer or query builder (types are code), no ad-hoc SQL, no external recipients for
scheduled mail (Q-O2), no dashboards / live charts, no storage of generated files (every file is built on demand;
Q-O6 covers async large exports).

## 2. Package

| Item | Proposal |
| --- | --- |
| Composer | **`nowo-tech/report-kit-bundle`** |
| GitHub | `nowo-tech/ReportKitBundle`, public, MIT; the owner creates it and registers it on Packagist after S0 is on `main` |
| Namespace / class | `Nowo\ReportKitBundle` / `NowoReportKitBundle`; config root `nowo_report_kit`; Twig namespace and translation domain `NowoReportKitBundle` |
| PHP / Symfony | `>=8.2 <8.6`; `^7.4 \|\| ^8.0`; demo Symfony 8.1 + FrankenPHP worker + MySQL + Mailpit |
| Runtime deps | `symfony/{http-foundation,http-kernel,routing,config,dependency-injection,event-dispatcher,security-core,translation,uid,clock,rate-limiter}`, `doctrine/dbal ^3.9 \|\| ^4`, `doctrine/orm ^3.3` (mapping only), `psr/log`, `twig/twig`, `nowo-tech/ui-kit-bundle`, `nowo-tech/form-kit-bundle`, `nowo-tech/brand-contracts ^1.1` |
| Format engines | `dompdf/dompdf ^3.1` (PDF) and `openspout/openspout` (XLSX, Beacon runs 5.13): **suggested**, enabled per format; the container refuses `formats.pdf.enabled: true` without Dompdf (Q-O3) |
| Suggest | `symfony/messenger` + `symfony/scheduler` (scheduled runs), `symfony/mailer` + `nowo-tech/mail-kit-bundle`, `nowo-tech/controller-kit-bundle` (CSV streaming, Q-O4), `nowo-tech/notify-kit-bundle`, `nowo-tech/user-kit-bundle`, `nowo-tech/audit-kit-bundle`, `nowo-tech/api-token-bundle` |
| First tag | 1.0.0 after S6, the REQ audit (100 %) and the SEC-004 audit |

## 3. Shape

```
viewer ── GET /reports/{type}?period=&context= ──▶ ReportController
             │  ReportScopeResolver (host SPI: contexts the viewer may report on) → ReportRequest
             │  ReportAccessChecker (host SPI: permission per type) → 403 / proceed
             ▼
      ReportTypeInterface::build(ReportRequest) ──▶ Report (sections)   ← host code (bounded aggregate queries)
             │
             ├─ screen: SectionViewModel (same as PDF) → UiKit page
             └─ export: ReportExporter → Csv | Xlsx | Pdf | Json renderer → private, no-store response

scheduler (enqueue only) ─▶ DispatchDueReportsMessage ─▶ atomic claim (conditional UPDATE per row)
             ─▶ RunScheduledReportMessage(subscriptionId, slotAt) ─▶ run log (unique per slot) ─▶ build AS OWNER (current access)
             ─▶ PDF (+ XLSX / CSV) ─▶ branded mail to the owner only ─▶ ScheduledReportSentEvent / auto-pause with reason
```

The bundle owns the model, registry, renderers, charts, pages, subscriptions, scheduling, mail, run log, operator
page and personal data. The host owns the report types and their queries, which contexts a person may report on, the
per-type permission, the people directory and the brand.

## 4. Public ports (interfaces and value objects)

Layered namespaces; contracts in `Nowo\ReportKitBundle\Contract\*`. No Doctrine, Symfony or HTTP type in a port, VO
or event (rule 3); HTTP responses are built in `Presentation`.

### 4.1 Value objects

```php
final readonly class OwnerRef   { public function __construct(public string $type, public string $id) {} public function key(): string; public function equals(self $o): bool; } // as 221-A
final readonly class ContextRef { public function __construct(public string $type, public string $id) {} public function key(): string; }                               // as 221-B
final readonly class SubscriptionId { public function __construct(public string $value) {} }  // UUIDv7

enum ReportFormat: string { case Csv = 'csv'; case Xlsx = 'xlsx'; case Pdf = 'pdf'; case Json = 'json'; public function contentType(): string; }
enum Frequency: string { case Daily = 'daily'; case Weekly = 'weekly'; case Monthly = 'monthly'; }

final readonly class ParameterSchema   // what a type accepts; drives the filter form and validation
{
    /** @param list<int> $periods allowed day windows, e.g. [7, 30, 90]; @param array<string, ParameterDefinition> $extra typed extras (choice|int|bool|date) */
    public function __construct(public array $periods, public int $defaultPeriod, public bool $context = true, public bool $contextRequired = false, public array $extra = []) {}
}

final readonly class ReportParameters { public function __construct(public int $periodDays, public ?ContextRef $context, public array $extra = []) {} }

final readonly class ReportRequest    // what a type receives
{
    /** @param list<ContextRef> $accessible contexts the owner may report on for THIS type, already narrowed (never empty when building) */
    public function __construct(
        public OwnerRef $owner, public object $user, public ReportParameters $parameters, public array $accessible,
        public string $locale, public string $timezone, public \DateTimeImmutable $now, public bool $scheduled,
    ) {}
    /** UTC day window [from, to) for the period, plus the previous window and the day keys (Beacon's AbstractReportType::window()). */
    public function window(): ReportWindow;
}

final readonly class Report          // Beacon's class, same shape, plus the brand context
{
    /** @param list<ReportSection> $sections */
    public function __construct(public string $title, public string $filters, public \DateTimeImmutable $generatedAt, public array $sections, public string $filename,
        public ?string $summary = null, public array $badges = [], public ?string $link = null, public ?string $glance = null, public ?ContextRef $brandContext = null) {}
}

final readonly class ReportSection   // Beacon's class: keyValues(...) and table(...) factories, CHART_* and STYLE_* constants, scalar cells only
{ /* unchanged API; adds a cap: rows ≤ limits.max_rows_per_section (default 50,000), otherwise truncatedAt is set */ }

final readonly class SubscriptionView
{
    public function __construct(public SubscriptionId $id, public OwnerRef $owner, public string $type, public ReportParameters $parameters,
        public Frequency $frequency, public int $weekday, public int $monthDay, public int $hour, public string $timezone, public array $formats,
        public bool $enabled, public ?string $pauseReason, public \DateTimeImmutable $nextRunAt, public ?\DateTimeImmutable $lastRunAt, public ?string $lastStatus) {}
}

enum PauseReason: string { case Owner = 'owner'; case Operator = 'operator'; case OwnerInactive = 'owner_inactive'; case NoAccess = 'no_access'; case TypeRemoved = 'type_removed'; case RepeatedFailure = 'repeated_failure'; }
```

### 4.2 Application services (bundle-implemented)

```php
interface ReportTypeRegistryInterface { /** @return array<string, ReportTypeInterface> in configured order */ public function all(): array; public function get(string $key): ?ReportTypeInterface; }

interface ReportBuilderInterface
{
    /** Resolves parameters against the type schema and the scope resolver, checks access, builds. Throws ReportAccessDenied / UnknownReportType. */
    public function build(string $type, OwnerRef $owner, object $user, array $rawParameters, bool $scheduled = false): Report;
}

interface ReportExporterInterface
{
    /** Bytes or a stream; the Presentation layer wraps it in a private, no-store response. */
    public function export(Report $report, ReportFormat $format): RenderedFile;  // {filename, contentType, stream|path, size}
}

interface PdfDocumentRendererInterface
{
    /** Closed Dompdf run of any Twig template (reports, invoices). Private temp dir per call, removed in finally. */
    public function render(string $template, array $context, PdfOptions $options = new PdfOptions()): string;
}

interface SubscriptionsInterface
{
    public function create(OwnerRef $owner, string $type, ReportParameters $parameters, Schedule $schedule, array $formats): SubscriptionView; // ≤ max_per_owner
    public function ofOwner(OwnerRef $owner): array;
    public function setEnabled(OwnerRef $owner, SubscriptionId $id, bool $enabled): bool;  // false = not the owner's (→ 404)
    public function delete(OwnerRef $owner, SubscriptionId $id): bool;
    public function setEnabledAsOperator(SubscriptionId $id, bool $enabled, OwnerRef $operator): bool;  // audited
    public function page(?string $after, int $limit): SubscriptionPage;                                  // operator, keyset
    public function forgetContext(ContextRef $context): int;                                             // host calls it when a project is deleted
}
```

### 4.3 Host SPIs

| Interface | Signature | Default |
| --- | --- | --- |
| `ReportTypeInterface` (tagged `nowo_report_kit.type`) | `key()`, `labelKey()`, `descriptionKey()`, `icon()`, `parameters(): ParameterSchema`, `permission(): ?string`, `build(ReportRequest): Report` | None (the demo ships two example types). `AbstractReportType` gives Beacon's helpers: `window()`, `report()`, translated headers, `hours()` |
| `ReportScopeResolverInterface` | `accessibleContexts(OwnerRef $owner, object $user, string $type): list<ContextChoice{ref, label}>` | `NoContextScopeResolver`: types run without context (personal / instance-wide); a type with `contextRequired` is hidden |
| `ReportAccessCheckerInterface` | `allows(OwnerRef $owner, object $user, string $type, list<ContextRef> $contexts): list<ContextRef>` (the contexts that grant the type's permission) | When a type declares a permission and no checker is configured, the container does not build. Without permissions: `IS_AUTHENTICATED` only |
| `OwnerResolverInterface` / `OwnerDirectoryInterface` | `ownerOf(user)`, `userOf(OwnerRef)`; `email()`, `locale()`, `timezone()`, `isActive()`, `displayName()` | Doctrine resolver + UserKit adapter when installed (as 221-A / 221-B) |
| `ReportBrandResolverInterface` | `brandFor(?ContextRef $context): Brand` (brand-contracts) | `ScopedBrandProviderInterface::getBrandFor()` when the host has one (brand per project, spec 220), else `BrandProviderInterface::getBrand()`, else a neutral built-in brand |
| `ReportMailerInterface` | `send(ScheduledReportMail $mail): void` | Symfony Mailer with MailKit's `BrandedEmailHelper` when installed; template overridable |
| `ReportSettingsProviderInterface` (rule 8) | `enabled()`, `formatsEnabled()`, `schedulesEnabled()` | Config values; InstanceSettings bridge optional |

## 5. Persistence

### 5.1 Tables

Plain domain classes with XML mapping by default and attribute-mapped twins (`doctrine.mapping: xml | attribute`,
221-A §5.1.1, Q-O11 of 221-A). DBAL writes in the bundle's own transactions, never a host flush (rule 6); prefix
`doctrine.table_prefix` (default `nowo_`, `REQ-ORM-002`); id-like columns binary (LQ rule) with a PHP `===` re-check
on every owner-scoped read and erase.

**`<p>report_subscription`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `string(36)` bin, PK | UUIDv7 (Beacon's existing `uuid` becomes the id in the migration) |
| `owner_type`, `owner_id` | `string(32)` bin, `string(128)` bin | `OwnerRef` |
| `report_type` | `string(64)` ascii_bin | |
| `parameters` | `json` | `{period_days, context: {type, id} \| null, extra: {...}}`, validated against the type schema on write **and** at run time |
| `frequency` | `string(16)` | `daily \| weekly \| monthly` |
| `weekday`, `month_day`, `hour` | `smallint` | 1–7, 1–28, 0–23 |
| `timezone` | `string(64)` | IANA; default from the owner directory, else `schedule.default_timezone` |
| `formats` | `json` | subset of the enabled formats; PDF always included (Q-O7) |
| `enabled` | `bool` | |
| `pause_reason` | `string(32)` NULL | `PauseReason` |
| `next_run_at` | `datetime_immutable` UTC | |
| `claim_token`, `claimed_at` | `string(36)` NULL, NULL | atomic claim (§9) |
| `last_run_at`, `last_status`, `last_error_code` | NULL | status `sent \| skipped \| failed \| paused`; error code only, never a message |
| `consecutive_failures` | `smallint` | auto-pause after `schedule.max_failures` (3) |
| `created_at`, `updated_at` | `datetime_immutable` | |

Indexes: `(enabled, next_run_at)` (due scan), `(owner_type, owner_id, created_at)`.

**`<p>report_run`** (idempotency and history, retention `retention.run_days`, default 90): `id`, `subscription_id`
(bin), `owner_type`, `owner_id`, `slot_at` (the scheduled slot), `status`, `formats`, `bytes` (attachment size),
`duration_ms`, `error_code`, `created_at`. **Unique `(subscription_id, slot_at)`**: a Messenger retry or a double
claim of the same slot can never send twice.

No generated file is stored.

### 5.2 ID mode and deletions

Owners and contexts are `type + id` strings: no relation to the host's user or project classes, no FK, no cascade.
The UserKit eraser removes an owner's rows; `SubscriptionsInterface::forgetContext()` removes subscriptions of a
deleted context (a run for a vanished context also pauses with `no_access`).

### 5.3 Schema and migrations (`REQ-ORM-001` rule 5)

No migration classes (house style); reference DDL for MySQL 8 / MariaDB 11, PostgreSQL 16 and SQLite in
`docs/INSTALLATION.md`, generated by a test; `nowo:report-kit:schema-check` compares the live schema with the
mapping; additive 1.x changes in `UPGRADING.md`; the demo keeps its own migrations.

## 6. Security model

### 6.1 Access

- **Viewer path:** the scope resolver gives the contexts the viewer may report on; an unknown or unreadable context
  in the query falls back to "all accessible" (Beacon FR-012), never to an error that reveals existence. The access
  checker narrows to the contexts that grant the type's permission; none → 403 (a type is listed only when allowed).
- **Scheduled path:** built **as the owner at run time**, never with a stored token or the operator's rights. The
  owner must be active, the type must exist, the subscribed context (if any) must still be accessible and must grant
  the type's permission; otherwise the run is `paused` with the reason, and NotifyKit (if installed) tells the owner.
- **Subscriptions:** looked up by id **and** owner (another person's id → 404); CSRF on every POST (FormKit
  CSRF-only forms with a per-subscription token id, as Beacon); at most `subscriptions.max_per_owner` (10).
- **Operator page:** `admin_ui.security.access_roles` (default `['ROLE_ADMIN']`); shows owner display name, type,
  schedule, last run and status; pause / resume audited; **never** shows or sends report content.
- Report types must run **bounded aggregate queries** over `ReportRequest::$accessible` only; the docs and the
  example types say so, and the request VO offers no way to widen the contexts.

### 6.2 Rendering safety

- **PDF:** Dompdf with `isRemoteEnabled=false`, `isJavascriptEnabled=false`, `isPhpEnabled=false`, chroot to a
  **private per-call temp directory** (removed in `finally`), font cache built at `cache:warmup` into
  `%kernel.cache_dir%/nowo_report_kit/fonts` (read-only at runtime), A4 and Letter. All text goes through Twig
  autoescape; images only as data URIs from `BrandAssetDataUri` (no file or URL); SVG charts are generated from
  numbers and escaped labels only.
- **Limits** (`REQ-RUNTIME-001`): PDF table rows ≤ 120 and cells ≤ 180 chars (Beacon's values; CSV / XLSX keep
  everything), `limits.max_rows_per_section` (50,000) for CSV / XLSX, a render time budget (`limits.render_seconds`,
  default 25 s, checked between sections) and an HTML size guard before Dompdf (`limits.max_pdf_html_bytes`, 5 MB).
- **CSV:** UTF-8 BOM; every text cell starting with `=`, `+`, `-`, `@`, tab or CR is prefixed with `'`
  (`REQ-QA-019`), through ControllerKit's `CsvStreamedResponse` when installed or the bundle's guard (same tests).
- **XLSX:** OpenSpout writes strings as string cells (never formulas); sheet names sanitised (`[]:*?/\`, 31 chars,
  unique case-insensitively); temporary file in the private directory, removed in `finally`.
- **Responses:** `Cache-Control: no-store, private`; `Content-Disposition` built with
  `HeaderUtils::makeDisposition()` and an ASCII fallback; file names `<filename_prefix>-<type>-<context-slug>-<date>`
  slugged to `[a-z0-9-]`.

### 6.3 Mail

- Sent **only to the owner's current e-mail** from the directory; never to an address stored on the subscription.
- Links from the router's `default_uri` / `links.base_url`, never a request Host (`REQ-AUTH-011`).
- Owner's locale through `LocaleSwitcher::runWithLocale()` (restored even on exception).
- Attachments above `mail.max_attachment_bytes` (default 8 MB) are dropped and the mail carries the link only (the
  page needs a sign-in).
- An unready mailer (`OutboundMailReadinessInterface` false) **skips** the run (`skipped`, `mail_not_ready`) and keeps
  the schedule, instead of pausing every subscription (Beacon change, Q-O9).

### 6.4 Rate limits

| Limit | Default | Where |
| --- | --- | --- |
| Exports per user | 20 / 10 minutes (`limits.exports`, Beacon's `report_export`) | Export routes and the API |
| Screen renders per user | 60 / minute (`limits.views`) | Report page |
| Subscriptions per owner | 10 (`subscriptions.max_per_owner`) | Create |
| Due runs per tick | 200 (`schedule.batch`) | Claim |

Limiter storage is the host's `cache.rate_limiter` pool; nothing in process memory.

### 6.5 Events (ids only, after commit)

| Event (`ReportKitEvents::*`) | Payload | When |
| --- | --- | --- |
| `ReportExportedEvent` | `owner`, `type`, `format`, `context` (`?ContextRef`), `rows` (total), `bytes` | After an export response is built (usage, audit) |
| `ReportSubscriptionChangedEvent` | `subscriptionId`, `owner`, `change` (`created\|enabled\|paused\|deleted`), `actor` (`?OwnerRef`), `reason` | After commit |
| `ScheduledReportSentEvent` | `subscriptionId`, `owner`, `slotAt`, `formats`, `bytes` | After the mail was handed to the mailer and the run row committed |
| `ScheduledReportPausedEvent` | `subscriptionId`, `owner`, `reason` | After commit; the NotifyKit bridge creates a bell row (type `report_paused`) |
| `ScheduledReportFailedEvent` | `subscriptionId`, `slotAt`, `errorCode`, `consecutiveFailures` | After commit |

No event carries report content, extra parameter values or e-mail addresses.

### 6.6 Audit

AuditKit bridge: operator pause / resume (`report_kit.subscription_operator_toggle`), subscription create / delete by
the owner (`report_kit.subscription_changed`). Exports are counted (event) but not audited row by row unless the host
listens.

## 7. Configuration

```yaml
nowo_report_kit:
    enabled: false
    doctrine: { entity_manager: default, table_prefix: 'nowo_', auto_mapping: true, mapping: xml }
    owner: { type: user, class: null, id_field: id, resolver: null, directory: null }
    types: { order: [], disabled: [] }            # menu order; keys of tagged types to hide
    scope: { resolver: null, access_checker: null }
    formats:
        csv:  { enabled: true }
        xlsx: { enabled: auto }                   # auto = OpenSpout installed
        pdf:  { enabled: auto, paper: A4, template: '@NowoReportKit/pdf/report.html.twig' }
        json: { enabled: false }                  # API only
    filename_prefix: 'report'                     # Beacon: 'beacon-report'
    brand: { resolver: null }
    limits:
        cache_pool: cache.rate_limiter
        exports: { limit: 20, interval: '10 minutes' }
        views: { limit: 60, interval: '1 minute' }
        max_rows_per_section: 50000
        pdf_max_rows: 120
        pdf_max_cell: 180
        render_seconds: 25
        max_pdf_html_bytes: 5242880
    subscriptions: { enabled: true, max_per_owner: 10, frequencies: [daily, weekly, monthly], default_hour: 7, allow_hour_choice: true }
    schedule: { enabled: false, cron: '5 * * * *', default_timezone: UTC, batch: 200, max_failures: 3, stale_claim_minutes: 30 }
    mail: { enabled: auto, template: '@NowoReportKit/email/scheduled_report.html.twig', max_attachment_bytes: 8388608 }
    links: { base_url: null }                     # null = router default_uri
    retention: { run_days: 90, batch_size: 500 }
    messenger: { max_seconds: 50 }
    bridges: { user_kit: auto, notify_kit: auto, audit_kit: auto, mail_kit: auto, controller_kit: auto, api_token: auto, instance_settings: false }
    web_ui:
        enabled: false
        path_prefix: '/reports'
        layout_template: null
        templates: { index: null, show: null, sections: null, schedule_dialog: null }
        security: { access_roles: ['ROLE_USER'] }
    admin_ui:
        enabled: false
        path_prefix: '/admin/report-subscriptions'
        security: { access_roles: ['ROLE_ADMIN'], access_checker: null, allow_unauthenticated: false }
    api: { enabled: false, prefix: '/api' }       # §7.1, Q-O8
    settings_provider: null
```

Strict tree (`REQ-SF-006`) with messages that name the path; `enabled: false` registers nothing.

### 7.1 HTTP endpoints

**Internal UI** (bundle pages; host UI prefix; not versioned, rule 5):

| Method / path (`web_ui.path_prefix`) | Answer |
| --- | --- |
| `GET /reports` | Report types available to the viewer + own subscriptions (pause / resume / delete) |
| `GET /reports/{type}?period=&context=&…` | Report page: sections, filter form (FormKit `filter` profile), Export menu, schedule dialog |
| `GET /reports/{type}/export.{format}` | File (`csv\|xlsx\|pdf`), rate-limited, `no-store` |
| `POST /reports/{type}/schedule` | Create a subscription from the current parameters (CSRF) |
| `POST /reports/subscriptions/{id}/toggle` · `/delete` | Own subscription only (CSRF, 404 otherwise) |
| `GET /admin/report-subscriptions` · `POST …/{id}/toggle` | Operator page |

**Public API** (optional, `api.enabled: false`; canonical `<prefix>/reports/v1/…`, container parameter
`%nowo_report_kit.api_prefix%`, OpenAPI `docs/openapi/reports-v1.yaml` with a route-comparison test; Bearer through
ApiTokenBundle module `reports` (level `read`) or the host firewall; stateless; refusals `{"err", "ECODE"}`):

| Method / path | Answer |
| --- | --- |
| `GET <prefix>/reports/v1/types` | Types available to the token owner, with their parameter schema |
| `GET <prefix>/reports/v1/types/{type}?period=&context=&format=json\|csv\|xlsx\|pdf` | The report (JSON model or file), same access and limits as the UI |

Subscriptions are not managed through the API in 1.0. No unversioned aliases (new paths).

## 8. UI (UiKit + FormKit, `REQ-UI-001/002/003`, `REQ-TWIG-005`)

- **Report list:** UiKit `ListPage` with cards (icon, title, description) per available type and a *Scheduled by
  e-mail* table (type, parameters line, schedule, next run, last status chip, pause reason, actions).
- **Report page:** title, badges, glance line, filters line; sections rendered from the same view model as the PDF
  (key-figure cards with deltas, tones and sparklines; series and slices as inline SVG with light / dark variants;
  tables with `details` / `trace` / `comments` / `tags` styles); filter form (period, context, extras); Export menu
  (enabled formats); *Schedule by e-mail* dialog (`_remote_form_dialog`: frequency, weekday / month day, hour, time
  zone, extra formats).
- **PDF template:** Beacon's `templates/report/pdf.html.twig` (256 lines) generalised: brand logo (data URI), name and
  accent colour from `Brand`, cards, charts, tables, running header from page 2, footer with brand · title and
  *page x of y*. Overridable block by block.
- **Operator page:** keyset table, filter by type and status, pause / resume (CSRF, audited).
- Seven locales with key parity (`REQ-I18N-002`), domain `NowoReportKitBundle`; type titles from the host's domain
  (`title_domain` per type). Every template overridable (`REQ-TWIG-001`), `docs/TEMPLATES.md`;
  `PageContractTestCase` for list, page and operator page. No own TypeScript in 1.0 (UiKit controllers only).

## 9. Messenger and scheduled work

| Message | Handler work |
| --- | --- |
| `DispatchDueReportsMessage` | Keyset scan of ≤ `schedule.batch` due rows; per row an atomic claim `UPDATE … SET claim_token = :t, claimed_at = :now, next_run_at = :next WHERE id = :id AND enabled = 1 AND next_run_at <= :now AND (claim_token IS NULL OR claimed_at < :stale)`; dispatches one `RunScheduledReportMessage` per row it won |
| `RunScheduledReportMessage` (subscriptionId, slotAt, claimToken) | Inserts the `report_run` row for `(subscription, slot)` (unique: a duplicate stops here), builds as owner, renders, mails, records `sent` / `skipped` / `paused` / `failed`, clears the claim. A failure **before** the mail is handed over is retried by Messenger; after hand-over the run is `sent` and never resent |
| `PruneReportRunsMessage` (cursor) | Deletes run rows older than `retention.run_days`, batches of 500 |

- Scheduler jobs only enqueue (`REQ-MESSENGER-008`): recipe `nowo_report_kit.schedule` (disabled by default) or the
  commands `nowo:report-kit:dispatch-due` / `nowo:report-kit:prune` for hosts with their own schedule (Beacon's
  `MaintenanceSchedule` line `5 * * * * app:reports:dispatch-due`).
- Mail goes through the host's platform mail transport (`REQ-MESSENGER-006`); run messages through its maintenance
  transport (Beacon `async_maintenance`).
- Handlers stop after `messenger.max_seconds` and re-dispatch the rest (`REQ-MESSENGER-007/009`); one PDF render is
  bounded by `limits.render_seconds`.
- Without Messenger, `dispatch-due` runs the claimed rows synchronously (small installs; documented as not for
  production with many subscriptions).
- Slot arithmetic is Beacon's `ReportSchedule::next()` extended with `hour` and `timezone` (computed in local time,
  stored in UTC; a skipped local hour runs at the next valid minute, a repeated hour runs once).

## 10. Personal data (`REQ-DATA-001…004`)

- **Eraser** (`getKey(): 'report_kit'`, keyed by owner id, before the identity eraser, `REQ-DATA-002`): deletes the
  owner's subscriptions and run rows (DBAL, inside the erasure transaction, no flush). Beacon's priority 70 is kept.
- **Export provider** (`getSectionKey(): 'report_subscriptions'`): subscriptions (type, parameters with the context
  label, schedule, formats, status, dates) and the run history (slot, status, formats, size). Report content is
  never stored, so never exported.
- Export ↔ erasure parity test over every bundle table (`REQ-DATA-004`).
- Operator actions keep the operator's id in AuditKit only.

## 11. Worker-mode design (FrankenPHP, kernel reset false)

- Services `final readonly` and stateless; the type registry is compiled into the container.
- One Dompdf / OpenSpout instance **per call**, never kept on a service; temp files in a private per-call directory
  deleted in `finally` (fixes Beacon's XLSX leak); no static caches; the font cache is built at warmup.
- `LocaleSwitcher` and the translator locale are restored in `finally` (a scheduled run in German never leaves the
  worker in German for the next request).
- No managed entities; DBAL transactions per call; reconnect-safe; claims carry a token so a crashed worker's claim
  expires (`stale_claim_minutes`).
- Rate limiters and claims in shared storage only.
- **Tests:** one kernel with `disableReboot()`: 500 alternating PDF / XLSX / CSV exports by two owners in two
  locales; assert the temp directory is empty after each call, the locale is back to default, owner A never sees
  context B, memory drift ≤ 4 MiB (Dompdf); a Messenger worker loop of 200 scheduled runs with an exception every
  10th run (no temp file and no locale left behind). Igor and `phpstan-frankenphp` clean; README states worker
  support (`REQ-DEMO-008`, `REQ-DOCS-017`).

## 12. Beacon migration (how Beacon swaps its code)

### 12.1 Data

One migration copies `report_subscription` into `nowo_report_subscription` (or Beacon's prefix, Q-B1):
`uuid → id`, `owner → owner_type user + owner_id <user uuid>`, `project → parameters.context project:<id>`,
`period_days → parameters.period_days`, `frequency / weekday / month_day` unchanged, `hour = 7`, `timezone = UTC`
(no behaviour change; owners can change it later), `attach_xlsx → formats [pdf, xlsx] | [pdf]`, `enabled`,
`next_run_at`, `last_run_at`, `last_status`, `created_at`; paused rows get `pause_reason = owner_inactive` when the
owner is disabled, else `no_access` (best effort). The old table is dropped one release later.

### 12.2 Code swap

- **Report types:** the ten `Type/*Report.php` implement the bundle's `ReportTypeInterface` (extending its
  `AbstractReportType`): `build(User $user, SummaryFilters $filters)` → `build(ReportRequest $request)`;
  `SummaryFilters` is rebuilt from `$request->parameters` and `$request->accessible` by a ~30-line Beacon adapter, so
  the query classes do not change. Keys and translation keys unchanged.
- **Scope and access:** Beacon implements `ReportScopeResolverInterface` with `AccessibleProjectsProvider` +
  `PermissionScope::projects(..., 'reports.view')` and `ReportAccessCheckerInterface` with `AnalyticsAreaAccess` +
  `PermissionCatalog::AREA_PERMISSIONS` (≈ 60 lines together, replacing `ReportFilterResolver` and the run-time
  check in `ScheduledReportRunner`).
- **Brand:** the renderers' use of `SiteAppearanceProvider` is replaced by Beacon's brand-contracts provider
  (candidate #10 direction).
- **Mail:** Beacon keeps its `scheduled_report` template (`EmailTemplateCatalog`) through `mail.template` until
  MailKit phase 2 (Q-B5).
- **Billing PDFs:** `InvoicePdf` and `BillingDocumentPdf` call `PdfDocumentRendererInterface::render()` instead of
  their own Dompdf setup (phase 2, Q-B4).
- **Issue report:** `IssueReportController` (65) keeps its route and uses the bundle's exporter.
- **Routes:** Beacon keeps its URLs through `web_ui.path_prefix: /dashboard/reports` and
  `admin_ui.path_prefix: /developer/report-subscriptions`; route names change (`nowo_report_kit_*`) and Beacon's
  menus and tests are updated.
- **Scheduler:** `app:reports:dispatch-due` → `nowo:report-kit:dispatch-due` in `MaintenanceSchedule`.

### 12.3 What Beacon deletes (line counts from `wc -l`, 2026-11-11)

| Path | Lines |
| --- | --- |
| `Analytics/Report/{Report,ReportSection,ReportFormat,ReportTypeRegistry,ReportExporter,ReportFilterResolver}.php`, `Type/{ReportType,AbstractReportType}.php` | 398 |
| `Analytics/Report/{Pdf,Xlsx,Csv}ReportRenderer.php`, `ReportChartSvg.php` | 485 |
| `Analytics/Report/Schedule/*` (frequency, schedule, runner) | 164 |
| `Analytics/Entity/ReportSubscription.php`, `Repository/ReportSubscriptionRepository.php`, `Form/ReportSubscriptionType.php` | 364 |
| `Analytics/Controller/{Report,ReportSubscription,AdminReportSubscription}Controller.php` | 317 |
| `Analytics/MessageHandler/RunScheduledReportHandler.php`, `Command/DispatchDueReportsCommand.php`, `Message/RunScheduledReportMessage.php` | 107 |
| `Identity/PersonalData/Erasure/ReportSubscriptionsEraser.php` | 45 |
| `templates/report/pdf.html.twig`, `templates/dashboard/reports/*`, `templates/admin/report_subscriptions/index.html.twig` | 570 |
| **Total** | **≈ 2,450** |
| Phase 2 (Q-B4): Dompdf setup in `Billing/Invoice/InvoicePdf.php` and `Billing/Receipt/BillingDocumentPdf.php` | ≈ 30 |

Beacon keeps (adapted): the ten types, `ReportQueries`, `TaskReportQueries`, `UptimeReportQueries`,
`IssueReportFactory`, `SummaryReportFactory`, `AnalyticsAreaAccess`, the billing templates,
`ReportSubscriptionDemoSeeder` (moved to the bundle API), plus ≈ 120 lines of adapters (scope, access,
`SummaryFilters` bridge).

## 13. Milestones

Every slice is a PR on `nowo-tech/ReportKitBundle`. Under the batch-audit rule, S3 (scheduled runs as owner: access
re-check and mail) and S6 (public API) need an **immediate** SEC-004 audit before a tag that contains them; the rest
is audited in the batch before 1.0.0.

| Slice | Version | Content | Done when | Audit |
| --- | --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SF 7.4 / 8.0 / 8.1 × MySQL / PostgreSQL / SQLite), PHPStan + `phpstan-frankenphp`, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL, Mailpit), strict config tree | `make release-check` green; scaffold REQ checklist 100 %; **owner registers on Packagist** | Batched |
| **S1** model + renderers | 0.1.0 | `Report` / `ReportSection`, registry, parameter schema, CSV / XLSX / PDF / JSON renderers, SVG charts, `PdfDocumentRendererInterface`, brand resolver, limits | Golden-file tests (CSV bytes, XLSX sheets, PDF text extraction), formula-injection and Dompdf-closed tests, worker export test | Batched |
| **S2** pages | 0.2.0 | Scope resolver + access checker SPIs, report list and page, export routes, rate limits, demo types | Page contract, Playwright e2e (filter, export each format), 403 paths | Batched |
| **S3** subscriptions + runs | 0.3.0 | Tables + mapping, subscriptions service, schedule dialog, atomic claim, run log, runner as owner, mail, auto-pause, scheduler recipe, commands, events | Double-claim and retry tests, owner-lost-access tests, DST tests, Mailpit e2e | **Immediate** (access re-check, mail) |
| **S4** operator + GDPR | 0.4.0 | Operator page, AuditKit bridge, UserKit eraser + export, NotifyKit bridge, `forgetContext()` | Parity test, audit rows | Batched |
| **S5** docs + i18n | 0.5.0 | Translations ×7, docs (USAGE, CONFIGURATION, UPGRADING, TEMPLATES, FRANKENPHP-WORKER-AUDIT, MIGRATION-FROM-BEACON), schema-check, reference DDL | Docs review | Batched |
| **S6** public API + 1.0 | 0.6.0 → **1.0.0** | Optional `<prefix>/reports/v1` + OpenAPI (Q-O8), `bundle-req-audit` 100 %, SEC-004 batch audit S0–S6 | REQ audit 100 %, audits Pass, owner OK | **Immediate** for the API |
| Beacon | after 1.0.0 | §12 (types adapter, scope / access, data copy, route prefixes), then phase 2 billing PDFs | Beacon's report e2e green on the bundle | Beacon's own |

## 14. Test strategy (coverage gate ≥ 99 % PHP)

- **Model:** factories keep scalars only; truncation; caps.
- **Renderers:** CSV golden bytes (BOM, title rows, blank lines, truncation note, formula guard for `=`, `+`, `-`,
  `@`, tab, CR); XLSX sheet names (forbidden characters, 31 chars, duplicates), numbers stay numbers, strings never
  formulas; PDF: Dompdf options asserted closed, a remote `<img src="http://…">` in a template is not fetched (HTTP
  client spy), text extraction contains title, filters and page numbers; SVG charts valid XML with escaped labels.
- **Access:** unknown context falls back to all accessible; a type without the permission is hidden and its routes
  answer 403; subscription of another owner → 404; CSRF missing → 403.
- **Schedule:** Beacon's `ReportSchedule` cases plus hour / time zone and DST (spring forward, fall back); month days
  29–31 refused.
- **Runs:** two concurrent claims → one run; Messenger retry after a pre-mail failure → one mail; retry after
  hand-over → no second mail; owner disabled / anonymised / lost access / type removed → paused with the reason and a
  NotifyKit row; mailer not ready → skipped, schedule kept; three failures → paused `repeated_failure`.
- **Collation (MySQL CI service):** `usera` vs `UserA` subscriptions, export and erasure never cross; PHP `===`
  re-check covered by a store double returning a wrong-case row.
- **Mail:** owner address only, owner locale, absolute links from `default_uri`, attachment cap → link only.
- **Transactions:** no flush on the host EM (spy); events only after commit.
- **GDPR:** export ↔ erasure parity.
- **Both host kinds:** standard fixture (attribute `User`, ORM resolver) and DDD fixture (XML `User` on a second EM,
  custom resolver, scope resolver and access checker) run the same functional suite.
- **Worker:** §11. **Demo:** two example types (e.g. "Signups per day", "Orders by status") on demo data, Playwright
  e2e with WebP screenshots (`REQ-DEMO-013`) of list, report page, export menu and schedule dialog; Mailpit check of
  the scheduled mail.

## 15. Questions

### For the owner (Q-O)

| # | Question | Recommendation | If not |
| --- | --- | --- | --- |
| Q-O1 | A new bundle `nowo-tech/report-kit-bundle` (repo `ReportKitBundle`)? | **Yes.** No existing bundle owns reports; the PDF runner and charts are reusable by billing and other apps | Put the renderers in ControllerKit and the subscriptions in each app |
| Q-O2 | Owner-only scheduled mail (Beacon's rule: built as the owner, sent to the owner), with no extra recipients in 1.0? | **Yes.** Extra recipients would get data the access check never saw for them; revisit with per-recipient builds if an app asks | Allow extra recipients who are users of the app (each gets a build as themselves) |
| Q-O3 | Dompdf and OpenSpout as **suggested** dependencies (a format is enabled only when its engine is installed), or hard requirements? | **Suggested.** CSV-only hosts stay light; the container refuses a format whose engine is missing | Require both (simpler docs, heavier installs) |
| Q-O4 | CSV streaming: use ControllerKitBundle's `CsvStreamedResponse` (formula guard already tested) or embed a guard? | **ControllerKit when installed, built-in guard as fallback**, sharing one test table; fits the "standardise on own bundles" goal without a hard dependency | Hard dependency on ControllerKit |
| Q-O5 | Scheduled hour and time zone per subscription (Beacon: 07:00 UTC fixed)? | **Yes**, default 07:00 in the owner's time zone; migrated Beacon rows keep 07:00 UTC | Keep a fixed instance hour (config) |
| Q-O6 | Large exports: synchronous only in 1.0 (bounded by limits and rate limits), or an async "export ready" flow (Messenger + NotifyKit bell + signed download, Beacon's billing-export pattern)? | **Synchronous in 1.0**; async export in 1.1 with StorageKit for the file and NotifyKit for the notice | Async in 1.0 (adds storage and signed URLs to the first audit) |
| Q-O7 | PDF always attached to scheduled mails, XLSX and CSV optional? | **Yes** (Beacon behaviour plus CSV) | Let the owner choose any subset, including link-only |
| Q-O8 | Public API `<prefix>/reports/v1` in 1.0 (off by default), authenticated by ApiTokenBundle module `reports`? | **Yes, in S6, off by default**: sms-bridge (usage per sender) and Core v2 integrations want JSON | Leave it for 1.x |
| Q-O9 | Unready mailer: skip the run and keep the schedule (new), instead of pausing every subscription (Beacon)? | **Skip and keep**; pausing hundreds of subscriptions for a transient SMTP problem forces every owner to resume by hand | Keep Beacon's pause |
| Q-O10 | Make the generic `PdfDocumentRendererInterface` (closed Dompdf for any template) public API of this bundle, so Beacon billing and other apps use one audited Dompdf setup? | **Yes**; it is ~60 lines and removes copies | Keep it internal to reports |

### For Beacon (Q-B)

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Table and route names: bundle table defaults, Beacon URLs kept through `path_prefix`? | Yes; route names change to `nowo_report_kit_*` |
| Q-B2 | Owner id: user UUID (as 221-A / 221-B)? | Yes |
| Q-B3 | Accept the behaviour changes: atomic claim + idempotent slot (no double mails), skip instead of pause when mail is not ready, auto-pause after 3 failures with a bell notice? | Yes; they close the gaps in "Facts" 4 |
| Q-B4 | Billing PDFs on the bundle's `PdfDocumentRendererInterface` in phase 2? | Yes, after the report swap |
| Q-B5 | The `scheduled_report` e-mail template: keep yours (override) until MailKit phase 2, or adopt the bundle's default? | Keep yours through `mail.template` until MailKit's catalogue exists |
