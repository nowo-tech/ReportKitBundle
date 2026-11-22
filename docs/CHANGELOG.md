# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
