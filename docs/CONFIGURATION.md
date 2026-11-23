# Configuration

Root key: `nowo_report_kit`. The tree is **strict** (REQ-SF-006): unknown keys and invalid values are refused at
container build, and the message names the path and the allowed values. Every key of contract §7 is accepted from S0;
the column *Used from* says which slice reads it. **`enabled: false` (the default) registers no service** (only the route loader, which then loads no route); `enabled: true`
registers the S1 services (report model, type registry, parameter resolver, renderers, closed PDF runner, brand
resolver, exporter, download responder).

## Table of contents

- [Full reference](#full-reference)
- [Format engines](#format-engines)
- [Container parameters](#container-parameters)
- [Validation rules](#validation-rules)

## Full reference

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
        pdf:  { enabled: auto, paper: A4, template: '@NowoReportKit/pdf/report.html.twig' }   # paper: A4 | A3 | letter | legal
        json: { enabled: false }                  # API only
    filename_prefix: 'report'                     # Beacon: 'beacon-report'
    temp_dir: null                                # parent of the private per-call render directories; null = system temp dir
    brand: { resolver: null, context_type: project }   # context_type: whose id is the brand scope; null = every type
    limits:
        cache_pool: cache.rate_limiter
        exports: { limit: 20, interval: '10 minutes' }
        views: { limit: 60, interval: '1 minute' }
        max_rows_per_section: 50000               # 1 to 50,000 (the ReportSection hard cap)
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
        security: { access_roles: ['ROLE_USER'], access_checker: null, allow_unauthenticated: false }
    admin_ui:
        enabled: false
        path_prefix: '/admin/report-subscriptions'
        security: { access_roles: ['ROLE_ADMIN'], access_checker: null, allow_unauthenticated: false }
    api: { enabled: false, prefix: '/api' }       # <prefix>/reports/v1 (Q-O8), container parameter %nowo_report_kit.api_prefix%
    settings_provider: null
```

| Key | Used from |
| --- | --- |
| `enabled`, `types`, `formats`, `filename_prefix`, `temp_dir`, `brand`, `limits` (`max_rows_per_section`, `pdf_max_rows`, `pdf_max_cell`, `render_seconds`, `max_pdf_html_bytes`), `bridges.controller_kit`, `settings_provider`, `schedule.enabled` (read by the settings provider) | S1 |
| `scope`, `web_ui`, `limits.cache_pool` / `exports` / `views` | S2 |
| `doctrine`, `owner`, `subscriptions`, `schedule`, `mail`, `links`, `retention`, `messenger` | S3 |
| `admin_ui`, the other `bridges` | S4 |
| `api` | S6 |

## Report pages security (REQ-UI-002)

| Key | Default | Purpose |
| --- | --- | --- |
| `web_ui.security.access_roles` | `['ROLE_USER']` | the signed-in user needs one of them to open the pages |
| `web_ui.security.access_checker` | `null` | your `ReportKitAccessCheckerInterface` service instead of the role check |
| `web_ui.security.allow_unauthenticated` | `false` | **demo / dev only**: open the pages without SecurityBundle or a signed-in user (one shared `guest:anonymous` owner) |
| `scope.resolver` | `null` | your `ReportScopeResolverInterface`; null = no context |
| `scope.access_checker` | `null` | your `ReportAccessCheckerInterface`; required as soon as a type declares a permission |
| `owner.resolver` | `null` | your `OwnerResolverInterface`; null = `owner.type` + the user's `owner.id_field` (`get<Field>()`, `<field>()` or a public property) |
| `limits.views` / `limits.exports` | 60 / minute, 20 / 10 minutes | per owner, in `limits.cache_pool` (sliding window; the host's `lock.factory` when it exists) |

With `web_ui.enabled: true` the container refuses to build without UiKit, FormKit, Twig, the form component and, unless
`allow_unauthenticated` is true, SecurityBundle. Add a firewall rule for `web_ui.path_prefix` too
([USAGE.md](USAGE.md#report-pages-s2)).

## Format engines

Dompdf (`dompdf/dompdf ^3.1`) and OpenSpout (`openspout/openspout`) are suggested, not required (Q-O3). `auto` turns the
format on when its engine is installed; `true` without the engine refuses to build the container:

```
"nowo_report_kit.formats.pdf.enabled" is true but Dompdf\Dompdf is not installed: run "composer require dompdf/dompdf" or set it to "auto" (Q-O3).
```

The PDF also needs Twig (`symfony/twig-bundle`): without it `auto` turns the PDF off and `true` refuses to build. The
bundle needs a translator (`framework.translator`, or the identity translator): without one the container refuses to
build. **The closed PDF runner `PdfDocumentRendererInterface` is registered whenever Dompdf and Twig are present**, even
with `formats.pdf.enabled: false` (Q-O10: invoices and other documents use it).

`bridges.controller_kit` (`auto` by default) picks ControllerKit's `CsvSafeWriter` as the CSV formula guard when
`nowo-tech/controller-kit-bundle` is installed, else the bundle's own guard (same rules, one shared test table, Q-O4);
`true` without the package refuses to build.

`brand.context_type` (default `project`): brand-contracts scopes are ids only, so the default resolver scopes only
contexts of this type; other types get the instance brand (audit ZG-L4: `team:42` never takes the brand of
`project:42`). `null` scopes every type by id, for hosts whose context ids never collide across types; a host with
several branded context types sets `brand.resolver`.

`settings_provider`: a service implementing `ReportSettingsProviderInterface` (rule 8) replaces the configuration values
at runtime (`enabled()`, `formatsEnabled()`, `schedulesEnabled()`); it is read on every export, never memoised.

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_report_kit.enabled` | `enabled` |
| `nowo_report_kit.api_prefix` | `api.prefix` |
| `nowo_report_kit.table_prefix` | `doctrine.table_prefix` |
| `nowo_report_kit.config` | the processed tree |
| `nowo_report_kit.formats` | (only when enabled) the formats switched on and installed, e.g. `['csv', 'xlsx', 'pdf']` |

## Validation rules

- `filename_prefix`: lower-case letters, digits, `-` and `_` (starts with a letter or digit, at most 64).
- `subscriptions.frequencies`: `daily`, `weekly`, `monthly` (at least one); hours 0 to 23.
- `schedule.default_timezone`: a PHP time zone identifier; `schedule.cron`: a five-field cron expression.
- `links.base_url`: null or an absolute `http(s)://` URL without a trailing slash.
- Tri-state switches (`formats.xlsx|pdf.enabled`, `mail.enabled`, `bridges.*`): `true`, `false` or `"auto"`.
- Paths: absolute, no trailing slash. Rate-limit intervals: `<n> second|minute|hour|day[s]`.
- Template names (`formats.pdf.template`, `mail.template`, `web_ui.layout_template`, `web_ui.templates.*`, audit ZE-I4):
  `@Namespace/path/name.html.twig` or `path/name.html.twig` (`@!` allowed), letters, digits, `_`, `-`, `.` and `/`,
  ending in `.twig`, no `..` and no `//`. The templates of the features that are on must exist: a missing
  `formats.pdf.template` stops `cache:warmup` with the configuration path in the message.
- `owner.class`: null or an existing class (audit ZE-I4).
- `temp_dir`: null or a non-empty path; the directory must be writable at runtime (a clear error otherwise).
