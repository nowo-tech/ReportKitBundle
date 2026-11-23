# Templates

## Table of contents

- [Report pages](#report-pages)
- [Subscription pages and dialog (S3)](#subscription-pages-and-dialog-s3)
- [Scheduled report mail (S3)](#scheduled-report-mail-s3)
- [PDF report template](#pdf-report-template)
- [Overriding](#overriding)

## Report pages

Both pages extend UiKit scaffolds (REQ-UI-003); none of them is a unique page.

| Template | Config key | Variables |
| --- | --- | --- |
| `@NowoReportKit/web/index.html.twig` | `web_ui.templates.index` | `page` (UiKit `ListPage`: title, description, "Open"), `subscriptions` (S3: UiKit `Table` of the viewer's subscriptions or null), `dialog_id` |
| `@NowoReportKit/web/show.html.twig` | `web_ui.templates.show` | `page` (UiKit `ShowPage`, Export and *Schedule by e-mail* actions in the header), `report`, `sections`, `filters` (GET `FormView`), `filters_action`, `sections_template`, `context_fallback`, `dialog_id` (S3: the remote dialog shell, null when subscriptions are off) |
| `@NowoReportKit/web/_sections.html.twig` | `web_ui.templates.sections` | `sections` (one view per section: `kpis` with UiKit `Stat` tiles, `series` with `svg` / `svg_dark` data URIs, legend and totals, `slices`, `table` with a UiKit `Table`), `bundle` |

Blocks of `show.html.twig`: `report_kit_meta`, `report_kit_filters`, `report_kit_sections`, `report_kit_schedule_dialog`;
of `index.html.twig`: `report_kit_subscriptions`. Every value is printed
escaped; charts are `<img>` data URIs of SVG built from numbers and escaped labels (an SVG in `<img>` runs no script),
the dark twin shows under `[data-theme=dark]`. No inline style or script (CSP friendly). `web_ui.layout_template`
replaces UiKit's admin layout.

## Subscription pages and dialog (S3)

The schedule form and the pause / resume / delete confirmations are one URL each, served in two modes (UiKit remote
form dialog): the full page for a normal request and the fragment for `X-Nowo-Dialog: 1`.

| Template | Config key | Variables |
| --- | --- | --- |
| `@NowoReportKit/web/form.html.twig` | — | full page: extends UiKit's form scaffold; `page` (UiKit `FormPage`), `danger`, `dialog` (false); the form errors on top (`data-testid="report-kit-form-errors"`) |
| `@NowoReportKit/web/_dialog_form.html.twig` | `web_ui.templates.schedule_dialog` | fragment: intro, errors (`role="alert"`), fields, actions row through UiKit's `form_actions` partial (Cancel closes the dialog and comes first; in a destructive confirmation it takes the initial focus); `page`, `danger`, `dialog` (true) |

The list and the report page print **one** empty `remote_form_dialog` shell (`id="report-kit-dialog"`) and links that
open it (`data-nowo-ui-dialog-open`, `-src`, `-form`): no form is rendered before a dialog opens.

## Scheduled report mail (S3)

| Template | Config key | Variables |
| --- | --- | --- |
| `@NowoReportKit/email/scheduled_report.html.twig` | `mail.template` | `mail` (`ScheduledReportMail`: recipient name, locale, attachments, `reportUrl`, `subscriptionsUrl`, `attachmentsDropped`, slot), `report` (title, filters, summary, glance), `brand` (`{name, accent}`) |
| `@NowoReportKit/email/scheduled_report.txt.twig` | — | the same, plain text |

Rendered in the owner's locale. Keep it to the title, filters, summary and glance line: the figures travel in the
attachments, or behind the link (which needs a sign-in). Links are already absolute.

## PDF report template

`@NowoReportKit/pdf/report.html.twig` (configuration `formats.pdf.template`). Dompdf renders it: CSS 2.1 tables for
layout, inline styles, no remote resources, no scripts, images only as `data:` URIs.

| Variable | Content |
| --- | --- |
| `report` | the `Report` (title, filters, generatedAt, summary, badges, link, glance) |
| `sections` | one view model per section (`kind`: `kpis`, `series`, `slices`, `table`, `details`, `trace`, `comments`, `tags`) |
| `brand` | `{name, accent, logo}`: brand name, `#rrggbb` accent, logo data URI or null |
| `locale` | the report locale; every bundle text is translated with it (`NowoReportKitBundle` domain) |

Blocks: `styles`, `masthead`, `sections`, `empty_note`. The footer (brand · title, *page x of y*) and the running header
from page 2 are drawn by the PDF runner, not by the template.

## Overriding

Either point `formats.pdf.template` at your own template (its name is validated and must exist at `cache:warmup`), or
override the bundle template in `templates/bundles/NowoReportKitBundle/pdf/report.html.twig`, extending the original:

```twig
{% extends '@!NowoReportKit/pdf/report.html.twig' %}
{% block styles %}{{ parent() }} h1 { font-size: 20pt; }{% endblock %}
```

Keep autoescaping on and embed images as data URIs only: the closed Dompdf setup refuses every other source. Do not
declare `@font-face` in PDF templates (audit ZG-I2): Dompdf would install the font into its shared font directory and
cache for every later render; use the bundled DejaVu families.
