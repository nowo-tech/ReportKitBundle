# Usage

> **S3 (subscriptions).** Report types, the parameter schema, the four renderers, the closed PDF runner and the brand
> resolver (S1); the scope / access SPIs, the report builder and the report pages with export routes and rate limits
> (S2); scheduled e-mail subscriptions built as the owner at run time, with their tables, schedule dialog, claim, run
> log, mail, auto-pause, Messenger handlers, Scheduler recipe and commands (S3). Contract 221-C, [ROADMAP.md](ROADMAP.md).

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [Report pages (S2)](#report-pages-s2)
- [Scheduled e-mail subscriptions (S3)](#scheduled-e-mail-subscriptions-s3)
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

   `$request->accessible` is never empty for a type that takes contexts on a host with a scope resolver, except when
   `$request->contextFreeGrant` is true: the person has no context and `allowsWithoutContext()` or a
   `ReportAccessCheckEvent::grant()` opened the build on purpose; decide what that build shows (the person's own data,
   instance-wide figures for an operator…). A person with zero contexts is otherwise refused (403, not listed), with or
   without a permission. `accessible === []` without the flag means the type takes no context or the host has none
   (`NoContextScopeResolver`).

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
   resolver every type runs without a context (types that require one are hidden). With a scope resolver, a person it
   gives no context is refused every type with `context: true` (fail closed), permission or not, unless
   `allowsWithoutContext()` answers true for that type (it is asked for permission-less context types too) or a listener
   grants it; the build then has `ReportRequest::$contextFreeGrant`. A type with a permission and no checker is
   refused, and `cache:warmup` fails so the gap shows at deploy time.

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
   | `nowo_report_kit_index` | `GET /reports` | the types the viewer may open (`limits.views`) |
   | `nowo_report_kit_show` | `GET /reports/{type}?period=&context=&…` | the report page (`limits.views`) |
   | `nowo_report_kit_export` | `GET /reports/{type}/export.{csv\|xlsx\|pdf}` | the file, `no-store, private` (`limits.exports`) |

   Unknown type → 404; type not granted → 403; anonymous → your firewall's entry point; over a rate limit → 429 with
   `Retry-After`; a build or render over a limit → 503. Pages and files are `no-store, private`. A context the viewer may not read falls back to "all accessible" and the
   page says so.

3. React to decisions and exports: `ReportKitEvents::REPORT_ACCESS_CHECK` (`ReportAccessCheckEvent`: `grant()` opens
   every scope context, `deny()` refuses) and `ReportKitEvents::REPORT_EXPORTED` (`ReportExportedEvent`: owner, type,
   format, effective context, rows, bytes).

4. Build reports yourself with `ReportBuilderInterface` (`available()`, `prepare()`, `build()`): the same scope, access
   and parameter rules as the pages.

The pages extend UiKit's list and show scaffolds; the filter form uses FormKit's `filter` profile and UiKit's form
theme. Override the templates through `web_ui.templates.*` or the bundle override path ([TEMPLATES.md](TEMPLATES.md)).

## Scheduled e-mail subscriptions (S3)

A person schedules the report page they are looking at (its parameters) by e-mail: daily, weekly on a weekday or
monthly on day 1–28, at an hour in a time zone (default `subscriptions.default_hour`, 07:00, in their own time zone,
Q-O5). PDF is always attached when it is enabled (Q-O7), XLSX / CSV on request; the mail goes **only** to the owner's
current address, in their locale, and every run builds the report **as the owner at that moment**.

1. **Tables.** The bundle maps three tables (`<prefix>report_subscription`, `<prefix>report_run`,
   `<prefix>report_owner_lock`, default prefix `nowo_`) in XML (default) or with attribute twins
   (`doctrine.mapping: attribute`) and prepends the mapping on `doctrine.entity_manager` (standard hosts). Create them
   with your usual tool (`doctrine:migrations:diff`, `doctrine:schema:update --dump-sql`). On MySQL / MariaDB the
   identifier columns get a binary NO PAD collation; set `doctrine.dbal.server_version` with the full version
   (`8.4.0`, `11.4.0-MariaDB`).

2. **Owner directory.** A run needs the person behind the owner: the user object (for your scope resolver and access
   checker), whether they are still active, their address, locale and time zone. The default reads your Doctrine
   user class (`owner.class`, looked up by `owner.id_field`) with the conventional getters `getEmail()`,
   `getLocale()`, `getTimezone()`, `isEnabled()` / `isActive()`, `getDisplayName()`; or plug your own
   `OwnerDirectoryInterface` (`owner.directory`). A user without `isEnabled()` / `isActive()` counts as **active**, so
   hosts with soft delete, bans or anonymisation must expose `isEnabled()` (or plug a directory). The default reads
   through your entity manager, so a user it already manages is not refreshed within one pass of `dispatch-due --sync`
   or one dispatch handler without a bus (Messenger clears the manager between messages); audit ZAD-I5.

   When a person is deleted or anonymised, erase their rows: `SubscriptionsInterface::forgetOwner($owner)` or
   `bin/console nowo:report-kit:forget-owner user <id>` (the UserKit eraser of S4 will call it). Prefer a non-reusable
   `owner.id_field` (UUID / numeric id).

   ```yaml
   nowo_report_kit:
       enabled: true
       owner: { type: user, class: App\Entity\User, id_field: id }
       subscriptions: { enabled: auto, max_per_owner: 10 }     # auto: on with DoctrineBundle and a directory
       schedule: { enabled: true, cron: '5 * * * *', default_timezone: Europe/Madrid }
       mail: { from: 'reports@example.com' }                  # or framework.mailer.headers.From
       links: { base_url: 'https://app.example.com' }         # or framework.router.default_uri (a local host is logged as a warning)
   ```

3. **Running the schedule.** `schedule.enabled` is the master switch of the runs (`ReportSettingsProviderInterface::
   schedulesEnabled()`). Either consume the bundle's Symfony Scheduler schedule (`messenger:consume
   scheduler_nowo_report_kit`, needs `symfony/scheduler` + `dragonmantank/cron-expression`), or call the command from
   your own scheduler:

   ```bash
   bin/console nowo:report-kit:dispatch-due        # claim the due slots, enqueue one RunScheduledReportMessage each
   bin/console nowo:report-kit:dispatch-due --sync # run them in this process (small installs only)
   bin/console nowo:report-kit:prune               # delete run rows older than retention.run_days
   ```

   Route the messages (REQ-MSG-001; scalars only, Serializer-friendly):

   ```yaml
   framework:
       messenger:
           transports:
               async_maintenance:
                   dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                   retry_strategy: { max_retries: 3, delay: 60000, multiplier: 2 }
               failed: 'doctrine://default?queue_name=failed'
           failure_transport: failed
           routing:
               Nowo\ReportKitBundle\Contract\Message\DispatchDueReportsMessage: async_maintenance
               Nowo\ReportKitBundle\Contract\Message\RunScheduledReportMessage: async_maintenance
               Nowo\ReportKitBundle\Contract\Message\PruneReportRunsMessage: async_maintenance
   ```

   | Message | Handler | Retries |
   | --- | --- | --- |
   | `DispatchDueReportsMessage` | claims ≤ `schedule.batch` due rows (atomic), enqueues one run each, re-dispatches the rest after `messenger.max_seconds` | safe: a claim is a conditional UPDATE |
   | `RunScheduledReportMessage` | builds as the owner, renders, mails, records | a failure **before** the mail was handed over is rethrown (the transport retries; after `schedule.max_failures` in a row the subscription pauses with `repeated_failure`); once handed over the slot is never sent again |
   | `PruneReportRunsMessage` | deletes old run rows in batches | safe |

   Do **not** put the `doctrine_transaction` middleware on the bus that handles `RunScheduledReportMessage`: the runner
   refuses to run inside a transaction (the mail would leave before the commit) and the message goes to the failure
   transport. Without Messenger, `dispatch-due` runs the claimed slots itself.

4. **What a run checks, every time.** The subscription is still enabled; the type still exists (else paused
   `type_removed`); the directory has an active user with an address whose owner reference is exactly the
   subscription's (else `owner_inactive`); the mailer is ready (MailKit's readiness when installed; else the run is
   `skipped` with `mail_not_ready` and the schedule is kept, Q-O9); the builder's scope resolver, access checker and
   `ReportAccessCheckEvent` (with `$scheduled = true`) grant the type (else `no_access`; zero contexts on a real scope
   resolver fail closed unless explicitly granted, audit ZY-M1); and a subscribed context is still among the accessible
   ones (else `no_access`: a run never widens to "all accessible"). Files over `mail.max_attachment_bytes` are dropped
   and the mail carries the link only.

5. **The pages.** With `web_ui.enabled`, the report page gets a *Schedule by e-mail* action and the list a
   *Scheduled by e-mail* table with pause / resume / delete. Each opens **one** UiKit remote dialog that loads the
   form on demand (no form per row): the link is the full page without JavaScript, the dialog sends `X-Nowo-Dialog: 1`
   and gets the fragment, an invalid form answers 422 in place, a saved one `204` + `X-Nowo-Redirect`. The schedule
   routes need FormKit's CSRF (`framework.csrf_protection`) and a session.

   | Route | Path | Answer |
   | --- | --- | --- |
   | `nowo_report_kit_schedule` | `GET\|POST /reports/{type}/schedule?<report parameters>` | the schedule form; the parameters are resolved and access-checked again; a context the person cannot read is a form error |
   | `nowo_report_kit_subscription_toggle` | `GET\|POST /reports/subscriptions/{id}/toggle` | pause / resume (own subscription only, 404 otherwise; CSRF per subscription; the version the page showed, 409 `SUBSCRIPTION_CHANGED` when it changed) |
   | `nowo_report_kit_subscription_delete` | `GET\|POST /reports/subscriptions/{id}/delete` | delete (same rules) |

6. **From code.** `SubscriptionsInterface` (`create()`, `ofOwner()`, `get()`, `setEnabled()`, `delete()`) does what
   the pages do; check the owner's access first (the pages build through `ReportBuilderInterface::prepare()`).
   Events after the commit: `ReportKitEvents::SUBSCRIPTION_CHANGED`, `SCHEDULED_REPORT_SENT`,
   `SCHEDULED_REPORT_PAUSED` (the NotifyKit bell arrives with S4), `SCHEDULED_REPORT_FAILED`; ids only.

7. **Your own mailer.** Implement `ReportMailerInterface` (`isReady()`, `send(ScheduledReportMail)`) and set
   `mail.mailer`, or override the template (`mail.template`, variables `mail`, `report`, `brand`). Returning from
   `send()` means "handed over": the run is `sent` and never sent again.

## Using it from a DDD / hexagonal application

| Concern | How a strict DDD host does it |
| --- | --- |
| Domain coupling | Your report types and application layer depend only on `Nowo\ReportKitBundle\Contract\*`: interfaces and `final readonly` value objects (`OwnerRef`, `ContextRef`, `ReportRequest`, `ReportParameters`, `ParameterSchema`, `Report`, `ReportSection`, `RenderedFile`, `PdfOptions`, …). No Doctrine, Symfony component or HTTP type appears in them; the HTTP response is built by `Presentation\Http\RenderedFileResponder`. |
| Report types | Implement `ReportTypeInterface` directly (no base class, no translator needed) and tag the service `nowo_report_kit.type`. |
| Branding | `brand.resolver`: your `ReportBrandResolverInterface` service. |
| Runtime switches | `settings_provider`: your `ReportSettingsProviderInterface` service (formats on / off per call; no hard dependency on InstanceSettings). |
| Owners and contexts | `type + id` value objects, compared exactly (`===`); no relation to your entities. |
| Owner, scope, access | `owner.resolver`, `scope.resolver`, `scope.access_checker`, `web_ui.security.access_checker`: your own services implementing the contract interfaces (`tests/Fixtures/Ddd/LedgerAccess.php` does all four in one class). |
| Persistence | Bundle-owned rows (S3), written through DBAL in the bundle's own transaction on the connection of `doctrine.entity_manager`; the bundle never flushes, clears or resets an EntityManager; events after the commit. With `doctrine.auto_mapping: false`, register `src/Resources/config/doctrine` (type `xml`, prefix `Nowo\ReportKitBundle\Domain`) on your own entity manager and point `doctrine.entity_manager` at it. |
| People | `owner.directory`: your `OwnerDirectoryInterface` (user object, active flag, address, locale, time zone), answered for exactly the owner asked for; `mail.mailer`: your `ReportMailerInterface`. |

`tests/Integration/Host/HostKindsTest.php` runs the same export path on both host kinds; `tests/Integration/Web/ReportPagesTest.php` and `DddHostPagesTest.php` run the pages on both; `tests/Integration/Database/{Standard,Ddd}Host{Subscriptions,Concurrency}Test.php` run the subscriptions, the runs and the race tests on both (XML `Member` on a second entity manager with its own directory for the DDD host).

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
report's locale explicitly). A scheduled run switches to the owner's locale only through `LocaleSwitcher::runWithLocale()`,
restored even when the run fails. Subscription writes are DBAL units opened and closed per call; nothing managed is
kept. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
