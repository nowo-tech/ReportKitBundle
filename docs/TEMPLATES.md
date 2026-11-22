# Templates

## Table of contents

- [PDF report template](#pdf-report-template)
- [Overriding](#overriding)

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
