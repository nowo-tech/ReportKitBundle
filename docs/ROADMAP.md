# Roadmap

Built slice by slice from [CONTRACT.md](CONTRACT.md) (contract 221-C). Every slice is a pull request on
`nowo-tech/ReportKitBundle`. Under the 2026-11-07 batch-audit rule, S3 (scheduled runs as owner: access re-check and
mail) and S6 (public API) need an **immediate** REQ-SEC-004 audit before a tag that contains them; the rest is audited
in the batch before 1.0.0.

## Table of contents

- [Slices](#slices)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [Open questions for Beacon](#open-questions-for-beacon)

## Slices

| Slice | Version | Content | Audit |
| --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SQLite / MySQL / PostgreSQL job), PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL, Mailpit), strict config tree. **Then the owner registers the package on Packagist** | Batched |
| **S1** model + renderers | 0.1.0 | `Report` / `ReportSection`, registry, parameter schema, CSV / XLSX / PDF / JSON renderers, SVG charts, `PdfDocumentRendererInterface`, brand resolver, limits | Batched |
| **S2** pages | 0.2.0 | Scope resolver + access checker SPIs, report list and page, export routes, rate limits, demo types | Batched |
| **S3** subscriptions + runs | 0.3.0 | Tables + mapping, subscriptions service, schedule dialog, atomic claim, run log, runner as owner, mail, auto-pause, scheduler recipe, commands, events | **Immediate** |
| **S4** operator + GDPR | 0.4.0 | Operator page, AuditKit bridge, UserKit eraser + export, NotifyKit bridge, `forgetContext()` | Batched |
| **S5** docs + i18n | 0.5.0 | Translations ×7, docs (USAGE, CONFIGURATION, UPGRADING, TEMPLATES, FRANKENPHP-WORKER-AUDIT, MIGRATION-FROM-BEACON), schema-check, reference DDL | Batched |
| **S6** public API + 1.0 | 0.6.0 → **1.0.0** | `<prefix>/reports/v1` + OpenAPI (off by default), `bundle-req-audit` 100 %, SEC-004 batch audit S0–S6 | **Immediate** for the API |
| Beacon | after 1.0.0 | Types adapter, scope / access, data copy, route prefixes; then phase 2 billing PDFs | Beacon's own |

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

## Open questions for Beacon

Still open (contract §15, "For Beacon"); the recommendation is what the bundle will assume unless Beacon answers
otherwise.

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Bundle table defaults, Beacon URLs kept through `path_prefix`? | Yes; route names change to `nowo_report_kit_*` |
| Q-B2 | Owner id: user UUID? | Yes |
| Q-B3 | Accept the behaviour changes: atomic claim + idempotent slot, skip when mail is not ready, auto-pause after 3 failures with a bell notice? | Yes |
| Q-B4 | Billing PDFs on `PdfDocumentRendererInterface` in phase 2? | Yes, after the report swap |
| Q-B5 | `scheduled_report` mail template: keep yours (override) until MailKit phase 2, or the bundle default? | Keep yours through `mail.template` |
