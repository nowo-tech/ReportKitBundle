# Configuration

Root key: `nowo_report_kit`. The tree is **strict** (REQ-SF-006): unknown keys and invalid values are refused at
container build, and the message names the path and the allowed values. Every key of contract §7 is accepted from S0;
the column *Used from* says which slice reads it. **S0 registers nothing**, whatever the values.

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
    api: { enabled: false, prefix: '/api' }       # <prefix>/reports/v1 (Q-O8), container parameter %nowo_report_kit.api_prefix%
    settings_provider: null
```

| Key | Used from |
| --- | --- |
| `enabled`, `types`, `formats`, `filename_prefix`, `brand`, `limits` | S1 |
| `scope`, `web_ui` | S2 |
| `doctrine`, `owner`, `subscriptions`, `schedule`, `mail`, `links`, `retention`, `messenger` | S3 |
| `admin_ui`, `bridges` | S4 |
| `settings_provider` | S5 |
| `api` | S6 |

## Format engines

Dompdf (`dompdf/dompdf ^3.1`) and OpenSpout (`openspout/openspout`) are suggested, not required (Q-O3). `auto` turns the
format on when its engine is installed; `true` without the engine refuses to build the container, already in S0:

```
"nowo_report_kit.formats.pdf.enabled" is true but Dompdf\Dompdf is not installed: run "composer require dompdf/dompdf" or set it to "auto" (Q-O3).
```

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_report_kit.enabled` | `enabled` |
| `nowo_report_kit.api_prefix` | `api.prefix` |
| `nowo_report_kit.table_prefix` | `doctrine.table_prefix` |
| `nowo_report_kit.config` | the processed tree |

## Validation rules

- `filename_prefix`: lower-case letters, digits, `-` and `_` (starts with a letter or digit, at most 64).
- `subscriptions.frequencies`: `daily`, `weekly`, `monthly` (at least one); hours 0 to 23.
- `schedule.default_timezone`: a PHP time zone identifier; `schedule.cron`: a five-field cron expression.
- `links.base_url`: null or an absolute `http(s)://` URL without a trailing slash.
- Tri-state switches (`formats.xlsx|pdf.enabled`, `mail.enabled`, `bridges.*`): `true`, `false` or `"auto"`.
- Paths: absolute, no trailing slash. Rate-limit intervals: `<n> second|minute|hour|day[s]`.
