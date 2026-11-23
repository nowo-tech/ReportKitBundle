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
- [S2 notes (contract deviations)](#s2-notes-contract-deviations)
- [S3 notes (contract deviations)](#s3-notes-contract-deviations)
- [Open questions for Beacon](#open-questions-for-beacon)

## Slices

| Slice | Version | Content | Audit | Status (2026-11-23) |
| --- | --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SQLite / MySQL / PostgreSQL job), PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL, Mailpit), strict config tree. **Then the owner registers the package on Packagist** | Batched | Released (on `main`, packaged in v0.1.0) |
| **S1** model + renderers | 0.1.0 | `Report` / `ReportSection`, registry, parameter schema, CSV / XLSX / PDF / JSON renderers, SVG charts, `PdfDocumentRendererInterface`, brand resolver, limits | Batched | Released (v0.1.0, 2026-11-22, audit ZE + ZG) |
| **S2** pages | 0.2.0 | Scope resolver + access checker SPIs, report list and page, export routes, rate limits, demo types | Batched | Released (v0.2.0, 2026-11-23, audit ZY) |
| **S3** subscriptions + runs | 0.3.0 | Tables + mapping, subscriptions service, schedule dialog, atomic claim, run log, runner as owner, mail, auto-pause, scheduler recipe, commands, events | **Immediate** | Built (PR `feat/s3-subscriptions`, not tagged; immediate SEC-004 audit pending) |
| **S4** operator + GDPR | 0.4.0 | Operator page, AuditKit bridge, UserKit eraser + export, NotifyKit bridge, `forgetContext()` | Batched | Not started |
| **S5** docs + i18n | 0.5.0 | Translations ×7, docs (USAGE, CONFIGURATION, UPGRADING, TEMPLATES, FRANKENPHP-WORKER-AUDIT, MIGRATION-FROM-BEACON), schema-check, reference DDL | Batched | Not started |
| **S6** public API + 1.0 | 0.6.0 → **1.0.0** | `<prefix>/reports/v1` + OpenAPI (off by default), `bundle-req-audit` 100 %, SEC-004 batch audit S0–S6 | **Immediate** for the API | Not started |
| Beacon | after 1.0.0 | Types adapter, scope / access, data copy, route prefixes; then phase 2 billing PDFs | Beacon's own | Waiting for 1.0.0 (renderHtml for billing PDFs is already in 0.1.0) |

## Road to 1.0.0

State on 2026-11-23: tags `v0.1.0` (S1 model and renderers, 2026-11-22) and `v0.2.0` (S2 pages, 2026-11-23, audit
ZY). S3 is built on `feat/s3-subscriptions` (PR open, not tagged); it needs the **immediate** REQ-SEC-004 audit before
0.3.0. S1 contract deviations 1 to 7 and the S1 decisions (PDF runner registered whenever Dompdf and Twig are
installed, no XLSX `quotePrefix`) were accepted by the owner on 2026-11-22.

What is left:

- **S3** (subscriptions and runs) is built on `feat/s3-subscriptions`; its contract deviations are in
  [S3 notes](#s3-notes-contract-deviations); immediate SEC-004 audit next.
- **Slices S4 to S6** (not started): operator page and GDPR, docs
  and i18n, public API. S3 and the S6 API need an **immediate** REQ-SEC-004 audit before
  their tags; the other slices are audited in the batch.
- **REQ gaps:** the last grade (2026-11-22) was done at S0 (`f5fc912`, no tag): 107 ok / 0 warn / 0 fail / 1 unknown
  (REQ-SEC-004) / 34 n/a. The unknown is covered by the appendices below; the checklist row still says "regrade needed"
  and a regrade at `v0.1.0` is due. Final `bundle-req-audit` at 100 % of the applicable REQs after S6.
- **REQ-SEC-004:** appendix ZE (S0) and appendix ZG (0.1.0, S1, HOLD then GO after the re-check; Info items ZG-I2, I3, I5,
  I9 to I11 open for later slices and docs); appendix ZY (S2, 0.2.0). S3 needs its immediate audit; S4 to S6 are not built.
- **Open Info items to carry:** ZG-I10 (document that a caller deadline should not exceed the request budget) and ZG-I11
  (sort share-bar slices by value) are docs notes.
- **Owner decisions:** none open. Q-O1 to Q-O10 answered 2026-11-12; Beacon Q-B1 to Q-B5 answered as recommended.
  Dompdf worker leak notice to Beacon is the coordinator's task, not a bundle decision.
- **Final gate:** `bundle-req-audit` at 100 %, then the REQ-SEC-004 batch audit over S0 to S6, then `1.0.0`.

Distance to 1.0.0: large (S3 built and waiting for its immediate audit, three slices not started).

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

## S2 notes (contract deviations)

Additive changes to the contract text, kept for review before 0.2.0:

| Item | Contract | S2 | Why |
| --- | --- | --- | --- |
| `ReportAccessCheckerInterface::allowsWithoutContext()` | `allows()` only | added | `allows()` returns contexts; a type without context (or a person without any) needs a yes / no for the type's permission |
| `ReportBuilderInterface::available()`, `prepare()`, `PreparedReport` | `build()` only | added | the list page needs the openable types; the report page needs the context choices and resolved parameters for the filter form and export links |
| `build()` / `prepare()` `$locale`, `$timezone` | not listed | added (last, optional) | `ReportRequest` needs them; null = `en` / `schedule.default_timezone` (the owner directory arrives with S3) |
| `OwnerResolverInterface::ownerOf()` returns `?OwnerRef` | `ownerOf(user)` | nullable | a user of another class has no owner: the pages answer 403 |
| `ReportAccessCheckEvent` (grant / deny / default) | not listed | added | the house access pattern (per-record decisions through events, owner 2026-11-19); a grant never exceeds the scope resolver's contexts |
| `ReportKitAccessCheckerInterface`, `web_ui.security.{access_checker,allow_unauthenticated}` | `access_roles` only | added | REQ-UI-002 canonical keys and area checker |
| Permission without checker | "the container does not build" | builds refused + `cache:warmup` fails | the container cannot call `permission()` on the types at compile time |
| `ReportScopeResolverInterface::MAX_CONTEXTS` (5,000) | not listed | added | bounds the `IN (…)` list a type receives; more refuses the build (503 on the pages) instead of truncating the scope |
| `ContextChoice` | `{ref, label}` | as written; label cleaned and 1–200 characters | same cleaning as every report text |
| Screen render bounds | not listed | the PDF bounds (`pdf_max_rows`, `pdf_max_cell`) | the page uses the PDF view model; the full data is in CSV / XLSX |
| Export route errors | not listed | limit over → 503, unknown format → 404, rate limit → 429 | |
| Empty scope on a real scope resolver, `ReportRequest::$contextFreeGrant` | §4.1 "never empty when building"; §4.3 "only asked for types with a permission" | fail closed: a type with `context: true` is refused (403 `NO_CONTEXT`, not listed) to a person with zero contexts, permission or not, unless `allowsWithoutContext()` (now also asked for permission-less context types) or a listener's `grant()` opens it; that build carries `contextFreeGrant: true` | audit ZY-M1: a type could not tell "the host has no contexts" from "this person has none" and a new user saw every context's figures |
| Report pages answer | not listed | the list counts under `limits.views`; HTML pages `no-store, private`; a type's limit or argument exception → 503; a scope over 5,000 contexts hides the type from the list | audit ZY-L2, ZY-L3, ZY-I3 |
| `ParameterSchema::RESERVED` | `period`, `context`, `format` | adds `type` | audit ZY-I1: the export link's route `type` would drop an extra of that name |

## S3 notes (contract deviations)

Additive changes to the contract text, kept for review before 0.3.0:

| Item | Contract | S3 | Why |
| --- | --- | --- | --- |
| `SubscriptionsInterface` | `create`, `ofOwner`, `setEnabled`, `delete`, `setEnabledAsOperator`, `page`, `forgetContext` | `create`, `ofOwner`, `get`, `setEnabled`, `delete` (with an optional `$expectedVersion`); the operator methods and `forgetContext()` in S4 | the pages need one subscription by id and owner; the optimistic version answers REQ-RUNTIME-003 for owner pauses that race a run; operator page and GDPR are S4 |
| `SubscriptionView::$version`, `$lastErrorCode`, `$consecutiveFailures`, `$createdAt` | not listed | added (last, optional) | the pages post the version back; the list shows why a run failed or skipped |
| `report_subscription.version`, `report_run.attempts`, `lease_at`, `report_owner_lock` table | not listed | added | optimistic version (stale pause / delete → 409); a run lease that a Messenger retry or a stale worker can take over exactly once; the per-owner lock row that makes `max_per_owner` hold under concurrent creates (same pattern as ApiTokenBundle) |
| Run row statuses | `sent \| skipped \| failed \| paused` | plus the transient `running` and `sending` | `sending` is written before the mailer is called, so a redelivery after the hand-over never sends twice |
| `subscriptions.enabled` | `true` | `true \| false \| "auto"`, default `auto` (on with DoctrineBundle and an owner directory) | a 0.2 host without Doctrine or a directory keeps building; `true` without them refuses |
| `mail.mailer`, `mail.from`, `concurrency.*`, `conflict_response.*` | not listed | new keys | own mailer; sender of the default mailer; REQ-RUNTIME-003 replay and conflict answer (same keys as ApiTokenBundle) |
| `ReportMailerInterface::isReady()` | `send()` only | added | the readiness check of Q-O9 belongs to the mailer port (MailKit's readiness when installed) |
| `OwnerDirectoryInterface` default | Doctrine resolver + UserKit adapter | Doctrine directory over `owner.class` with conventional getters; the UserKit adapter comes with the S4 bridges | no UserKit dependency in S3 |
| MailKit `BrandedEmailHelper` | default mailer brands through it | not used in S3: the bundle template uses the report's brand (name, accent); MailKit readiness is used | Beacon keeps its own template through `mail.template` until MailKit phase 2 (Q-B5) |
| NotifyKit bell on pause | §6.1 | `ScheduledReportPausedEvent` dispatched; the NotifyKit bridge is S4 | bridges are S4 |
| Schedule and subscription routes | `POST` only | `GET` + `POST` on the same path | lazy dialogs (owner rule): the opener is a link to the page, the dialog loads the fragment on open; no form per row |
| A requested context the person cannot read | the page falls back to "all accessible" | the schedule form refuses it (error before and after the POST); a run whose subscribed context is gone pauses `no_access` | a subscription must never silently widen its scope |
| Runner inside a transaction | not listed | refused (`LogicException`, unrecoverable for Messenger) | Messenger's `doctrine_transaction` middleware would send the mail before the commit (REQ-RUNTIME-003 rule 7) |
| Ambiguous local hour (fall back) | "a repeated hour runs once" | runs once, at the **first** occurrence | PHP resolves the wall time to the second occurrence; the bundle picks the earlier instant |
| `schema-check` command, reference DDL | §5.3 | still S5 | as planned in the slices table |

## Open questions for Beacon

Answered by Beacon as recommended (Beacon D-20261111-04, 2026-11-12); kept for reference (contract §15, "For Beacon").

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Bundle table defaults, Beacon URLs kept through `path_prefix`? | Yes; route names change to `nowo_report_kit_*` |
| Q-B2 | Owner id: user UUID? | Yes |
| Q-B3 | Accept the behaviour changes: atomic claim + idempotent slot, skip when mail is not ready, auto-pause after 3 failures with a bell notice? | Yes |
| Q-B4 | Billing PDFs on `PdfDocumentRendererInterface` in phase 2? | Yes, after the report swap |
| Q-B5 | `scheduled_report` mail template: keep yours (override) until MailKit phase 2, or the bundle default? | Keep yours through `mail.template` |
