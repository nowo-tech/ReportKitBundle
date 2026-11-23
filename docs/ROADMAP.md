# Roadmap

Built slice by slice from [CONTRACT.md](CONTRACT.md) (contract 221-C). Every slice is a pull request on
`nowo-tech/ReportKitBundle`. Under the 2026-11-07 batch-audit rule, S3 (scheduled runs as owner: access re-check and
mail) and S6 (public API) need an **immediate** REQ-SEC-004 audit before a tag that contains them; the rest is audited
in the batch before 1.0.0.

## Table of contents

- [Slices](#slices)
- [Road to 1.0.0](#road-to-100)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [S1 notes (contract deviations)](#s1-notes-contract-deviations)
- [Open questions for Beacon](#open-questions-for-beacon)

## Slices

| Slice | Version | Content | Audit | Status (2026-11-23) |
| --- | --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SQLite / MySQL / PostgreSQL job), PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL, Mailpit), strict config tree. **Then the owner registers the package on Packagist** | Batched | Released (on `main`, packaged in v0.1.0) |
| **S1** model + renderers | 0.1.0 | `Report` / `ReportSection`, registry, parameter schema, CSV / XLSX / PDF / JSON renderers, SVG charts, `PdfDocumentRendererInterface`, brand resolver, limits | Batched | Released (v0.1.0, 2026-11-22, audit ZE + ZG) |
| **S2** pages | 0.2.0 | Scope resolver + access checker SPIs, report list and page, export routes, rate limits, demo types | Batched | Not started |
| **S3** subscriptions + runs | 0.3.0 | Tables + mapping, subscriptions service, schedule dialog, atomic claim, run log, runner as owner, mail, auto-pause, scheduler recipe, commands, events | **Immediate** | Not started |
| **S4** operator + GDPR | 0.4.0 | Operator page, AuditKit bridge, UserKit eraser + export, NotifyKit bridge, `forgetContext()` | Batched | Not started |
| **S5** docs + i18n | 0.5.0 | Translations ×7, docs (USAGE, CONFIGURATION, UPGRADING, TEMPLATES, FRANKENPHP-WORKER-AUDIT, MIGRATION-FROM-BEACON), schema-check, reference DDL | Batched | Not started |
| **S6** public API + 1.0 | 0.6.0 → **1.0.0** | `<prefix>/reports/v1` + OpenAPI (off by default), `bundle-req-audit` 100 %, SEC-004 batch audit S0–S6 | **Immediate** for the API | Not started |
| Beacon | after 1.0.0 | Types adapter, scope / access, data copy, route prefixes; then phase 2 billing PDFs | Beacon's own | Waiting for 1.0.0 (renderHtml for billing PDFs is already in 0.1.0) |

## Road to 1.0.0

State on 2026-11-23: tag `v0.1.0` (S1 model and renderers, 2026-11-22). `main` equals the tag plus nothing runtime.
No open pull request. S1 contract deviations 1 to 7 and the S1 decisions (PDF runner registered whenever Dompdf and
Twig are installed, no XLSX `quotePrefix`) were accepted by the owner on 2026-11-22.

What is left:

- **Slices S2 to S6** (none started): pages and export routes, subscriptions and runs (owner-scoped scheduled mail),
  operator page and GDPR, docs and i18n, public API. S3 and the S6 API need an **immediate** REQ-SEC-004 audit before
  their tags; the other slices are audited in the batch.
- **REQ gaps:** the last grade (2026-11-22) was done at S0 (`f5fc912`, no tag): 107 ok / 0 warn / 0 fail / 1 unknown
  (REQ-SEC-004) / 34 n/a. The unknown is covered by the appendices below; the checklist row still says "regrade needed"
  and a regrade at `v0.1.0` is due. Final `bundle-req-audit` at 100 % of the applicable REQs after S6.
- **REQ-SEC-004:** appendix ZE (S0) and appendix ZG (0.1.0, S1, HOLD then GO after the re-check; Info items ZG-I2, I3, I5,
  I9 to I11 open for later slices and docs). Nothing covers S2 to S6 yet.
- **Open Info items to carry:** ZG-I10 (document that a caller deadline should not exceed the request budget) and ZG-I11
  (sort share-bar slices by value) are docs notes.
- **Owner decisions:** none open. Q-O1 to Q-O10 answered 2026-11-12; Beacon Q-B1 to Q-B5 answered as recommended.
  Dompdf worker leak notice to Beacon is the coordinator's task, not a bundle decision.
- **Final gate:** `bundle-req-audit` at 100 %, then the REQ-SEC-004 batch audit over S0 to S6, then `1.0.0`.

Distance to 1.0.0: large (six of seven slices not started).

## Owner decisions (2026-11-12)

Every owner question of the contract is answered with its recommendation:

| # | Decision |
| --- | --- |
| Q-O1 | A new bundle `nowo-tech/report-kit-bundle` |
| Q-O2 | Scheduled mail to the owner only (built as the owner); no extra recipients in 1.0 |
| Q-O3 | Dompdf and OpenSpout suggested; a format whose engine is missing refuses to build when forced on |
| Q-O4 | CSV through ControllerKit's `CsvStreamedResponse` when installed, built-in guard as fallback (one test table) |
| Q-O5 | Hour and time zone per subscription, default 07:00 in the owner's time zone; migrated Beacon rows keep 07:00 UTC |
| Q-O6 | Synchronous exports in 1.0; async "export ready" flow in 1.1 (StorageKit + NotifyKit) |
| Q-O7 | PDF always attached to scheduled mails, XLSX and CSV optional |
| Q-O8 | Public API `<prefix>/reports/v1` in S6, off by default, authenticated by ApiTokenBundle module `reports` |
| Q-O9 | Unready mailer: skip the run and keep the schedule |
| Q-O10 | `PdfDocumentRendererInterface` is public API of this bundle |
| 221-A Q-O11 / Q-O12 | XML mapping by default + attribute twin; `OwnerRef` copied in this bundle |

## S1 notes (contract deviations)

Additive changes to the contract text, accepted by the owner on 2026-11-22 and shipped in 0.1.0:

| Item | Contract | S1 | Why |
| --- | --- | --- | --- |
| `ReportTypeInterface::translationDomain()` | not listed | added | §8 says type titles come from the host's domain (`title_domain` per type); the page and the PDF need it |
| `Report::$locale` | not listed | added (last, optional) | the renderers translate the bundle's texts with the report locale explicitly, so a scheduled run never switches the worker's translator |
| `ReportRequest::$contextLabel` | not listed | added (last, optional) | filters line and file name name the context by its label (`ContextChoice::label`, S2) |
| `ReportParameters::$contextUnavailable` | not listed | added | audit ZE-I3: the effective scope is "all accessible" and the files say so |
| `ReportParametersResolverInterface` | builder resolves parameters (§4.2) | separate port in S1 | the builder (`ReportBuilderInterface`) needs the scope resolver and access checker SPIs, which are S2 |
| `PdfDocumentRendererInterface::renderHtml()`, `PdfOptions::$deadline` | not listed | added | ready host HTML (Beacon billing PDFs, Q-B4); the render deadline enforced inside Dompdf (audit ZG-M1) |
| `brand.context_type` | not listed | new key (default `project`) | brand scopes are ids only (audit ZG-L4) |
| `ReportExporterInterface::formats()` | not listed | added | the Export menu (S2) and the API (S6) list the formats that are on right now |
| `RenderedFile` | `{filename, contentType, stream\|path, size}` | `php://temp` stream only | no path for a caller to clean up (worker mode) |
| `ReportBrandResolverInterface` returns `Brand` | as written | as written | the logo goes in through the host's optional `BrandAssetReaderInterface` as a data URI |
| `temp_dir` | not listed | new key (default null = system temp dir) | the private per-call directories need a configurable parent (read-only root file systems) |
| `ReportExportedEvent` | §6.5 | not in S1 | the event carries the owner and context, which the exporter does not know; dispatched by the export routes in S2 |
| Permission without checker refuses to build | §4.3 | S2 | with the access checker SPI |
| Rate limits `limits.exports` / `views` | §6.4 | S2 | they guard routes |
| `ReportSection` cap | `limits.max_rows_per_section` | hard cap 50,000 in the VO + configured cap in every renderer | a VO cannot read configuration |

## Open questions for Beacon

Answered by Beacon as recommended (Beacon D-20261111-04, 2026-11-12); kept for reference (contract §15, "For Beacon").

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Bundle table defaults, Beacon URLs kept through `path_prefix`? | Yes; route names change to `nowo_report_kit_*` |
| Q-B2 | Owner id: user UUID? | Yes |
| Q-B3 | Accept the behaviour changes: atomic claim + idempotent slot, skip when mail is not ready, auto-pause after 3 failures with a bell notice? | Yes |
| Q-B4 | Billing PDFs on `PdfDocumentRendererInterface` in phase 2? | Yes, after the report swap |
| Q-B5 | `scheduled_report` mail template: keep yours (override) until MailKit phase 2, or the bundle default? | Keep yours through `mail.template` |
