# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **S3 subscriptions and scheduled runs** (contract 221-C §4.1–4.3, §5, §6.1, §6.3–6.5, §9, §11; target 0.3.0):
  - Value objects and ports: `SubscriptionId`, `Frequency`, `Schedule` (daily / weekly / monthly 1–28, hour, IANA time
    zone; `next()` computes slots in local time and returns UTC: a skipped local hour runs after the gap, a repeated
    one runs once), `PauseReason`, `RunStatus`, `SubscriptionView`, `SubscriptionsInterface` (`create()`, `ofOwner()`,
    `get()`, `setEnabled()`, `delete()`), `OwnerDirectoryInterface`, `ReportMailerInterface`, `ScheduledReportMail`,
    `MailAttachment`, the messages `DispatchDueReportsMessage`, `RunScheduledReportMessage`, `PruneReportRunsMessage`,
    the events `ReportSubscriptionChangedEvent`, `ScheduledReportSentEvent`, `ScheduledReportPausedEvent`,
    `ScheduledReportFailedEvent`, the exceptions `SubscriptionLimitException`, `SubscriptionChangedException`,
    `ReportKitConflictException`.
  - Tables `<prefix>report_subscription`, `<prefix>report_run` (unique `(subscription_id, slot_at)`) and
    `<prefix>report_owner_lock`: plain domain classes with XML mapping (default) and attribute twins
    (`doctrine.mapping: attribute`), prepended on `doctrine.entity_manager`; written through DBAL only.
  - Scheduled runs **as the owner at run time**: the directory, the type, the scope resolver, the access checker and
    `ReportAccessCheckEvent` are asked again on every attempt; owner inactive / lost access / lost context / type removed
    pause with the reason; an unready mailer skips and keeps the schedule (Q-O9); `schedule.max_failures` failures in a
    row pause with `repeated_failure`. PDF always attached when enabled (Q-O7), XLSX / CSV on request, files over
    `mail.max_attachment_bytes` replaced by the link.
  - Atomic claim (`DueReportsDispatcher`), idempotent run per slot with a lease and a `sending` mark before the
    hand-over (never two mails per slot), run log, retention (`retention.run_days`).
  - Messenger handlers (`#[AsMessageHandler]`) for the three messages, the Symfony Scheduler schedule
    `nowo_report_kit` (`schedule.enabled` + `symfony/scheduler`), the commands `nowo:report-kit:dispatch-due` (`--sync`)
    and `nowo:report-kit:prune`.
  - Default owner directory over the Doctrine user class (`owner.class`; `getEmail()`, `getLocale()`, `getTimezone()`,
    `isEnabled()` / `isActive()`, `getDisplayName()`), default mailer on Symfony Mailer with the templates
    `@NowoReportKit/email/scheduled_report.{html,txt}.twig` (owner's locale, absolute links from `links.base_url` /
    `default_uri`), MailKit readiness bridge.
  - Pages: *Schedule by e-mail* on the report page and a *Scheduled by e-mail* table on the list, with pause / resume /
    delete; each opens one lazy UiKit remote dialog (fragment on `X-Nowo-Dialog`, 422 in place, 204 +
    `X-Nowo-Redirect`), full pages without JavaScript; FormKit CSRF forms (403 on a bad token), the version the page
    showed posted back (409 `SUBSCRIPTION_CHANGED`). Templates `@NowoReportKit/web/{form,_dialog_form}.html.twig`.
  - REQ-RUNTIME-003: one transaction per unit through transaction-doctrine (replay, PostgreSQL `lock_timeout`), the
    per-owner lock row for `max_per_owner`, row locks and optimistic versions, `ReportKitConflictListener` (409 / 503 +
    `X-Conflict-Code: CONFLICT_001|CONFLICT_002`), events after the commit of a host transaction
    (`AfterCommitMiddleware`), two-connection race tests on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16
    ([CONCURRENCY.md](CONCURRENCY.md)).
  - REQ-SEC-006: binary NO PAD collation of the identifier columns (`utf8mb4_0900_bin` / `utf8mb4_nopad_bin`), `===`
    re-checks of every owner- and id-keyed read, `ReportKitExactKeyColumns` (tag `nowo_migrations_kit.exact_key_columns`).
  - Configuration: `mail.mailer`, `mail.from`, `concurrency.*`, `conflict_response.*`; container parameter
    `nowo_report_kit.subscriptions`. Translations in the seven locales.
  - Demo: Doctrine on MySQL, a people directory, the schedule dialog, a "run the due reports now" demo button and the
    Mailpit check in the Playwright e2e.

### Changed

- `subscriptions.enabled` accepts `true | false | "auto"` and defaults to `auto` (on with DoctrineBundle and an owner
  directory); `true` without them refuses to build.
- New required packages: `doctrine/dbal`, `doctrine/orm` (mapping only), `doctrine/persistence`,
  `nowo-tech/transaction-contract`, `nowo-tech/transaction-doctrine`, `nowo-tech/migrations-kit-contracts`, `psr/log`,
  `symfony/console`, `symfony/uid`.

### Security

- Scheduled runs never reuse an access decision: zero contexts on a real scope resolver fail closed at run time as on
  the pages (audit ZY-M1), a subscribed context that is no longer accessible pauses instead of widening, the mail goes
  only to the directory's current address of exactly that owner, and the runner refuses to run inside a transaction
  (the mail would leave before the commit). Immediate REQ-SEC-004 audit due before 0.3.0.

## [0.2.0] - 2026-11-23

### Added

- **S2 pages** (contract 221-C §4.2–4.3, §6.1, §6.4, §7.1, §8; target 0.2.0):
  - Host SPIs: `ReportScopeResolverInterface` (contexts a person may report on, as `ContextChoice {ref, label}`; at
    most 5,000 per build), `ReportAccessCheckerInterface` (`allows()` per context, `allowsWithoutContext()` for
    personal / instance-wide types), `OwnerResolverInterface` (user → `OwnerRef`). Defaults: `NoContextScopeResolver`,
    `UnconfiguredReportAccessChecker` (grants nothing; a type with a permission and no checker fails `cache:warmup`),
    `PropertyOwnerResolver` (`owner.type` + `owner.id_field`).
  - `ReportBuilderInterface` (`available()`, `prepare()`, `build()`): scope resolver → access checker (answer
    intersected with the scope, exact keys) → `ReportAccessCheckEvent` (listeners grant within the scope or deny) →
    parameters → type. `PreparedReport`, `UnknownReportTypeException`, `ReportAccessDeniedException` (with a reason).
  - Report pages on UiKit + FormKit (`web_ui.enabled`, routes through the `nowo_report_kit_ui` loader under
    `web_ui.path_prefix`): the list of the types the viewer may open, the report page (sections from the PDF view
    model with the same bounds: key figures, SVG series with a dark twin, shares as progress meters, tables; GET filter
    form on the FormKit `filter` profile; Export menu) and `GET {prefix}/{type}/export.{csv|xlsx|pdf}`.
  - REQ-UI-002 access: `web_ui.security.access_roles` (default `ROLE_USER`), `access_checker`
    (`ReportKitAccessCheckerInterface`), `allow_unauthenticated` (demo only); the container refuses to build the pages
    without SecurityBundle, UiKit or FormKit.
  - Rate limits `limits.views` (report page) and `limits.exports` (export routes) per owner in `limits.cache_pool`
    (429 + `Retry-After`).
  - `ReportExportedEvent` (owner, type, format, effective context, rows, bytes) after every export response;
    `ReportKitEvents`.
  - Templates `@NowoReportKit/web/{index,show,_sections}.html.twig` (overridable, `web_ui.templates.*`); translations
    in the seven locales.
  - Demo: two report types ("Signups per day", "Orders by status" with a permission), demo scope and access checker,
    sign-in as alice / bob, Playwright e2e (filters, export in each format, 403, sign-in redirect) and screenshots.

### Changed

- `nowo-tech/ui-kit-bundle` ^2.68, `nowo-tech/form-kit-bundle` ^2.24, `symfony/form`, `symfony/rate-limiter`,
  `symfony/routing`, `symfony/security-core` and `symfony/event-dispatcher` are now required (contract §2).
- The report list counts under `limits.views` and hides a type whose scope exceeds 5,000 contexts instead of failing;
  a type's `ReportLimitExceededException` / `InvalidReportArgumentException` during the page or export build answers
  503 instead of 500; report HTML pages send `Cache-Control: no-store, private` (audit ZY-L2, ZY-L3, ZY-I3).
- `ParameterSchema::RESERVED` adds `type` (the export route's parameter; audit ZY-I1).
- The builder intersects contexts by key sets (`isset`) instead of nested `in_array` (audit ZY-L1).

### Removed

- Unused dev dependencies `symfony/browser-kit`, `symfony/css-selector`, `symfony/dom-crawler`.

### Security

- **Zero contexts fail closed** (audit ZY-M1): with a real scope resolver, a person who has no context for a type with
  `context: true` is refused that type (not listed; 403 `NO_CONTEXT` on the page and every export), with or without a
  permission. Only `ReportAccessCheckerInterface::allowsWithoutContext()` (now also asked for permission-less context
  types) or an explicit `ReportAccessCheckEvent::grant()` opens it, and that build carries the new
  `ReportRequest::$contextFreeGrant` flag so the type can decide what a context-free build shows.

## [0.1.0] - 2026-11-22

### Added

- **S1 model + renderers** (contract 221-C §4, §6.2, §8, §11; target 0.1.0), registered only when
  `nowo_report_kit.enabled` is true:
  - Report model: `Report`, `ReportSection` (key values with delta, tone and sparkline; tables with chart kind and
    presentation style; scalar cells only; hard cap 50,000 rows), `OwnerRef`, `ContextRef`, `ReportFormat`.
  - Parameter schema: `ParameterSchema`, `ParameterDefinition` (choice / int / bool / date), `ReportParameters`,
    `ReportRequest` (with the UTC `ReportWindow`) and `ReportParametersResolverInterface`; an unknown, unreadable or
    look-alike context falls back to "all accessible" and is flagged, and `AbstractReportType` writes the filters line
    and the file name from the effective scope (audit ZE-I3).
  - Type registry (tagged / autoconfigured `ReportTypeInterface`, `types.order`, `types.disabled`, duplicate and invalid
    keys refused) and the optional `AbstractReportType` base.
  - Renderers: CSV (UTF-8 BOM, formula-injection guard through ControllerKit's `CsvSafeWriter` when installed or the
    bundle's identical guard, Q-O4), XLSX (OpenSpout 4.28+ / 5.x, strings never formulas, Excel-safe sheet names), JSON,
    PDF (generalised Beacon template with brand header, cards, SVG charts, tables, footer *page x of y* and running
    header), server-side SVG charts (stacked columns, sparklines, light / dark).
  - `PdfDocumentRendererInterface` (Q-O10): closed Dompdf for any template — remote, JavaScript and PHP off, only
    `data://` allowed, private per-call chroot removed in `finally`, HTML size guard, font cache built at warmup
    (audit ZE-I5).
  - `ReportBrandResolverInterface` with the brand-contracts default (scoped provider, provider, neutral brand);
    `ReportSettingsProviderInterface` (rule 8); `ReportExporterInterface` with the render time budget;
    `RenderedFileResponder` (`no-store, private`, safe `Content-Disposition`).
  - Limits: `max_rows_per_section` (at most 50,000), `pdf_max_rows` (table rows, cards and share bars), series columns
    (min(366, `pdf_max_rows`)), sparkline points (366), `pdf_max_cell`, `render_seconds` (between sections and inside
    Dompdf at every page through its callbacks), `max_pdf_html_bytes`; every PDF cut prints the truncation note.
  - `PdfDocumentRendererInterface::renderHtml()` for HTML the host already rendered (Beacon invoices and receipts) and
    `PdfOptions::$deadline`.
  - Report titles, filters lines, summaries, glance lines, badge labels and context labels are cleaned (valid UTF-8, no
    control characters).
  - `brand.context_type` (default `project`): only contexts of that type are brand scopes.
  - `conflict` with `nowo-tech/controller-kit-bundle <2.3.1` (its CSV guard lacks the separator rule).
  - Configuration: new key `temp_dir`; template names and `owner.class` validated, and the PDF template must exist at
    `cache:warmup` (audit ZE-I4); a translator is required, Twig for the PDF.
  - Translations of the report texts in the seven locales; `docs/TEMPLATES.md`, `docs/CONCURRENCY.md`.
  - Tests: golden files (CSV bytes in two locales, XLSX sheets, JSON), PDF text extraction, Dompdf-closed tests with a
    spy on the `http` / `https` wrappers and a positive control, the shared formula-guard table, both host kinds, and
    `WorkerExportLoopTest` (500 alternating PDF / XLSX / CSV exports, two owners, two locales, no temp file left,
    translator locale untouched, memory drift < 4 MiB).

  - Worker mode: Dompdf's global warning list and static image cache are emptied after every render, and the
    `LC_NUMERIC` / `pcre.jit` / mbstring encoding values Dompdf changes are restored even when a render fails, so a
    long-lived worker neither grows nor keeps paths to removed files or a changed locale (Beacon's renderer has the same
    exposure).

### Changed

- Demo: runs in UiKit's demo platform shell (`nowo-tech/ui-kit-bundle` ^2.60); the demo's own header is gone and the pages use the shell page headers and section cards. Demo only, no bundle change.
- Demo (`demo/symfony8`) brought up to the house demo rules: dev stack with `symfony/web-profiler-bundle`, `symfony/debug-bundle`, Twig Inspector and Hot Reload (dev/test only, Caddy `mercure` + `hot_reload`) (REQ-DEMO-001); pages rendered on UiKit's admin layout instead of a hand-written CDN shell (REQ-DEMO-014); Flex pin `extra.symfony.require` `8.1.*` (REQ-DEMO-012); Playwright e2e suite (`demo/symfony8/e2e`, home page renders, no console errors), `make test-e2e` / `make demo-screenshots`, a `Demo e2e` CI workflow and an overview WebP screenshot in the README (REQ-DEMO-013).

### Documentation

- README: standard badge line (Packagist Downloads, GitHub stars), "Found this useful?" line, canonical `## Documentation` list with `### Additional documentation`, Tests and coverage with TS/JS and Python N/A (REQ-DOCS-002/004/009/019, REQ-TEST-007). `docs/CONTRACT.md` has a table of contents (REQ-DOCS-005), `docs/SPEC-DRIVEN-DEVELOPMENT.md` links the Spec Kit manual (REQ-SPECKIT-002) and `docs/SECURITY.md` carries the release security checklist (REQ-SEC-002).

### Added

- S0 scaffold (contract 221-C, [CONTRACT.md](CONTRACT.md)): the bundle class `NowoReportKitBundle`, the strict configuration
  tree `nowo_report_kit` with every key of the contract (`enabled: false` by default; nothing is registered in S0) and the
  container parameters `nowo_report_kit.enabled`, `nowo_report_kit.api_prefix`, `nowo_report_kit.table_prefix` and
  `nowo_report_kit.config`.
- Quality gates: PHPUnit (100 % coverage gate), PHPStan level 8 with the FrankenPHP worker rulesets, PHP-CS-Fixer,
  Rector, Igor, CI matrix (PHP 8.2 to 8.5, Symfony 7.4 and 8.1), a SQLite / MySQL / PostgreSQL job, a worker test (one
  kernel kept alive, 2,000 requests) and the FrankenPHP demo smoke test.
- Docs set, Spec Kit baseline, Flex recipe, demo skeleton (Symfony 8.1, FrankenPHP worker mode, MySQL).
