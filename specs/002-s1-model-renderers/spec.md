# Feature Specification: S1 model + renderers

**Feature Branch**: `feat/s1-model-renderers`
**Created**: 2026-11-22
**Status**: Implemented (target first tag 0.1.0)
**Input**: Contract 221-C §4, §6.2, §8, §11, §13 (S1 row) and §14; owner Q-O answers (all as recommended, 2026-11-12);
audit ZE items I3, I4, I5; house bar (`BUNDLES_FIX_RULES.md`: architecture bar, worker mode, concurrency, new bundles).

Deviations from the contract text: [`docs/ROADMAP.md`](../../docs/ROADMAP.md#s1-notes-contract-deviations).

## Requirements

| ID | Requirement |
| --- | --- |
| FR-MODEL-001 | Header texts and context labels cleaned (ZG-L5); `Report` and `ReportSection` keep scalar cells only (int, finite float, UTF-8 string, null), validate chart kinds, styles, tones, badges, links and locales, and cap a table at 50,000 rows. |
| FR-MODEL-002 | `OwnerRef` and `ContextRef` refuse invalid keys and identifiers (no trimming) and compare exactly. |
| FR-PARAM-001 | `ParameterSchema` / `ParameterDefinition` describe periods, context and typed extras; invalid schemas are refused. |
| FR-PARAM-002 | `ReportParametersResolverInterface` keeps valid raw values and falls back to defaults; a requested context that is not accessible (unknown, unreadable or look-alike) gives "all accessible" with `contextUnavailable` (ZE-I3); a required context must be picked. |
| FR-PARAM-003 | `ReportRequest` accepts only an effective context among the accessible ones and gives the UTC day window. |
| FR-TYPES-001 | The registry orders tagged types by `types.order` then key, hides `types.disabled`, refuses duplicates and invalid keys. |
| FR-TYPES-002 | `AbstractReportType` writes the title, the filters line and the file name base from the effective scope, in the request locale. |
| FR-RENDER-CSV | CSV: BOM, header rows, one block per section, truncation note, formula guard (ControllerKit or built-in, one shared table), row limit; golden bytes in two locales. |
| FR-RENDER-XLSX | XLSX: About sheet plus one sheet per section, bold headers, numbers as numbers, strings never formulas, safe sheet names, row limit, private directory removed in `finally`; golden sheets. |
| FR-RENDER-JSON | JSON: the report model with the row limit; golden file. |
| FR-RENDER-PDF | PDF: brand header, cards and share bars cut to `pdf_max_rows`, SVG series cut to min(366, `pdf_max_rows`), sparklines to 366 points, tables cut to `pdf_max_rows` / `pdf_max_cell` (ZG-M1), empty-section note, footer *page x of y*, running header; text extraction holds title, filters and page numbers. |
| FR-PDF-001 | `PdfDocumentRendererInterface` (`render()` for a template, `renderHtml()` for ready HTML): deadline inside Dompdf at every page (ZG-M1), PHP state restored on failure (ZG-L2), closed Dompdf (remote, JavaScript, PHP off; `data://` only; private chroot removed in `finally`; HTML size guard; Dompdf static cache and warning list emptied) — ZE-I5. |
| FR-CHART-001 | SVG charts are valid XML from numbers and escaped labels; colours `#rrggbb` only; light and dark variants. |
| FR-BRAND-001 | Default brand resolver: scoped provider for contexts of `brand.context_type` (ZG-L4), else provider, else neutral; logo only as a data URI. |
| FR-EXPORT-001 | The exporter checks the format against the settings provider per call, applies the time budget and names the file `<prefix>-<base>.<ext>` slugged. |
| FR-HTTP-001 | `RenderedFileResponder` answers a private, `no-store`, `nosniff` attachment with a safe `Content-Disposition`. |
| FR-CONFIG-003 | `enabled: true` registers the S1 services; `temp_dir`; engines and ControllerKit detected; template names and `owner.class` validated, PDF template checked at warmup (ZE-I4); translator required, Twig for the PDF. |
| FR-I18N-002 | Report texts in the seven locales with key parity. |
| FR-HOSTS-001 | The same export path runs on a standard host and a DDD host (port implemented directly, own brand resolver and settings provider). |
| FR-WORKER-002 | One kept-alive kernel without the services resetter serves 500 alternating PDF / XLSX / CSV exports by two owners in two locales: no other owner's context, no temporary file left, translator locale untouched, memory drift < 4 MiB. |

## Success criteria

- PHPUnit green with 100 % line coverage of `src/`; PHPStan level 8 with the FrankenPHP worker rulesets, CS, Rector,
  Igor, PHPDoc and translation parity clean.
