# Security

## Table of contents

- [Status](#status)
- [Threat model](#threat-model)
- [Audits](#audits)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Status

S1 (model + renderers, target 0.1.0): services only, still **no route, no table, no listener**. A host reaches the code
by calling the ports (`ReportExporterInterface`, `PdfDocumentRendererInterface`, …) from its own controllers. The
rendering safety below ships in S1; access control arrives with the pages in S2.

### Rendering safety (S1)

- **PDF (audit ZE-I5).** Every Dompdf run goes through one options factory (`Infrastructure\Pdf\ClosedDompdfOptions`):
  remote, JavaScript and PHP off; allowed protocols **`data://` only**, so no `file://`, `http(s)://` or `phar://`
  resource is ever read, not even inside Dompdf's own directory; chroot and temp dir = a private per-call directory
  (mode 0700, random name) removed in `finally`; font metrics cache in `%kernel.cache_dir%/nowo_report_kit/fonts`
  (built at warmup) or the per-call directory; HTML5 parser on. The HTML is refused above `limits.max_pdf_html_bytes`
  before Dompdf runs. `PdfClosedTest` renders a hostile template (remote images, stylesheets, `@import`,
  `@font-face`, `file://` and relative paths to a readable file outside the chroot, `phar://`, `<script type="text/php">`,
  `<?php`, JavaScript) with the `http` / `https` wrappers replaced by a spy: nothing is opened, no marker from the
  outside files reaches the PDF, no PHP runs, no JavaScript is embedded; a positive control proves the probe detects a
  leak against an open Dompdf.
- **Images** only as `data:` URIs: SVG charts built from numbers and escaped labels (colours must be `#rrggbb`), the
  brand logo through brand-contracts' `BrandAssetDataUri` (raster sniffed from the bytes; SVG only from a config
  default asset). Never a file path or a URL.
- **CSV (`REQ-QA-019`).** UTF-8 BOM; text cells starting with `=`, `+`, `-`, `@`, tab or CR get a leading `'`, and a
  trigger after `;`, `,`, tab, `|`, CR or LF gets a space (Excel splits on the system list separator). ControllerKit's
  `CsvSafeWriter` when installed, the bundle's identical guard otherwise; one test table runs against both.
- **XLSX.** Every string is written as a string cell, never a formula (OpenSpout would turn a plain `=…` value into a
  formula); the golden test asserts no `<f>` element in any sheet. Sheet names sanitised.
- **Limits (`REQ-RUNTIME-001`, audit ZG-M1).** `max_rows_per_section` (CSV / XLSX / JSON, at most 50,000, the
  `ReportSection` hard cap). In the PDF every kind is bounded: table rows, key-figure cards and share bars to
  `pdf_max_rows`, series columns to min(366, `pdf_max_rows`), sparkline points to the latest 366, text cells to
  `pdf_max_cell`; each cut prints the truncation note. Time: `render_seconds` is checked between sections and
  **inside Dompdf** at every page reflow and page render (`begin_page_reflow` / `begin_page_render` callbacks,
  `ReportLimitExceededException`); the HTML parsing and style cascade before the first page are bounded by
  `max_pdf_html_bytes`, checked before Dompdf runs.
- **Responses.** `RenderedFileResponder`: `Cache-Control: no-store, private`, `Content-Disposition: attachment` from
  `HeaderUtils::makeDisposition()` with an ASCII fallback, `X-Content-Type-Options: nosniff`; file names slugged to
  `[a-z0-9-]`.
- **Scope wording (audit ZE-I3).** When a requested context is not among the accessible ones, the parameters fall back
  to "all accessible" with `contextUnavailable` set, and `AbstractReportType` writes the filters line ("All accessible
  contexts (the requested context is not available)"), the file name (`…-all-…`) and the brand context from the
  **effective** scope, never the requested one. Same answer for unknown, unreadable and look-alike (`Project-A` vs
  `project-a`) contexts.
- **Report links** (`Report::$link`) are local paths or `https://` URLs only.

## Threat model

From contract §6. Each threat names the control that answers it and the slice that ships it.

| Threat | Control | Slice |
| --- | --- | --- |
| A viewer reports on contexts they may not read | Scope resolver + access checker narrow `ReportRequest::$accessible`; an unknown context falls back to "all accessible", never to an error that reveals existence; types run bounded aggregate queries over the accessible contexts only | S2 |
| A scheduled run leaks data after the owner lost access | Built as the owner at run time with the access re-check; otherwise paused with a reason | S3 |
| Mail to the wrong person | Owner's current e-mail only, from the directory; never an address stored on the subscription; absolute links from `default_uri` | S3 |
| Another person's subscription | Lookup by id **and** owner (404 otherwise), CSRF on every POST | S3 |
| PDF server-side request forgery / code execution | Closed Dompdf: no remote, no JavaScript, no PHP, private per-call chroot removed in `finally`, images only as data URIs | S1 |
| CSV / XLSX formula injection | Leading `=`, `+`, `-`, `@`, tab, CR prefixed with `'`; XLSX strings never written as formulas | S1 |
| Resource exhaustion | Row, cell, render-time and HTML-size limits; export and view rate limits in the cache pool | S1, S2 |
| Report content on the operator page | Operator sees owner, type, schedule and status only; never report content | S4 |
| Collation (audit LQ) | Owner ids in `utf8mb4_bin`, PHP `===` re-check on subscriptions, export and erasure | S3 |
| Worker-mode bleed | No report, owner or scope memo across requests; temp files per call; N-request test | S1–S3 |

## Audits

S3 (scheduled runs: access re-check and mail) and S6 (public API) need an **immediate REQ-SEC-004 audit** before a tag
that contains them; the other slices are audited in the batch before 1.0.0, together with the `bundle-req-audit` at
100 %.

## Release security checklist (12.4.1)

Run before every tag (`docs/RELEASE.md`); each slice updates the status column.

| Item | Status (S1, unreleased) |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` present | ✅ |
| `.env` files ignored; no secrets in the repository | ✅ (demo uses placeholder values) |
| Safe recipe / config defaults | ✅ (`enabled: false`; no route in S1; PDF / XLSX refused without their engine; JSON off) |
| Input validation / output escaping | ✅ (value objects validate on construction; raw parameters resolved against the type schema; Twig auto-escape in the PDF template; escaped SVG labels; CSV formula guard; XLSX string cells) |
| `composer audit` clean | ✅ (CI) |
| No secrets / personal data in logs | ✅ (no logging in S1) |
| Cryptography | — (none) |
| Permissions / exposure | — (no route in S1; scope resolver and access checker from S2) |
| Limits / DoS | ✅ (row, cell, render-time and HTML-size limits; rate limits from S2) |
| REQ-SEC-004 AI audit recorded | Pending (before the first tag, see [Audits](#audits)) |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md): report privately to hectorfranco@nowo.tech.
