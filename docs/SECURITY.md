# Security

## Table of contents

- [Status](#status)
- [Threat model](#threat-model)
- [Audits](#audits)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Status

S3 (subscriptions, target 0.3.0, **immediate REQ-SEC-004 audit**): three bundle tables, scheduled runs built as the
owner, mail to the owner only, the schedule dialog and the subscription pages. The rendering safety below ships since
S1, the page access since S2, the scheduled path since S3.

### Scheduled runs and subscriptions (S3)

- **Built as the owner, at run time** (contract §6.1). A run never stores or reuses an access decision, a token or an
  operator's rights. Every attempt reads the directory again (active user, current address; the user's owner reference
  must equal the subscription's exactly, REQ-SEC-006 rule 4), then builds through the same `ReportBuilderInterface` as
  the pages: scope resolver, access checker and `ReportAccessCheckEvent` (`$scheduled = true`) decide again.
  **Zero contexts fail closed like ZY-M1**: a person a real scope resolver gives no context is refused a context type
  unless `allowsWithoutContext()` or an explicit `grant()` opens it. A subscribed context that is no longer accessible
  **pauses** the subscription (`no_access`); a run never falls back to "all accessible" (the page does, and says so).
  Removed type → `type_removed`; inactive / missing / mismatched owner or no valid address → `owner_inactive`.
- **Mail to the owner only** (§6.3, Q-O2): the directory's address at run time, never a stored one; one recipient, no
  Cc / Bcc; the owner's locale through `LocaleSwitcher::runWithLocale()` (restored on failure); links built from
  `links.base_url` / the router's `default_uri` on a fresh generator, never from a request Host (REQ-AUTH-011);
  attachments over `mail.max_attachment_bytes` are dropped and the mail carries the link, which needs a sign-in; the
  mail body carries the title, filters line, summary and glance only. The template escapes every value.
- **No double mail.** The run row `(subscription_id, slot_at)` is unique and carries the lease token of the attempt
  that holds it; only that attempt may move it to `sending` or close it, the row goes to `sending` before the mailer is
  called and a `sending` row is never taken again (at most once per slot, audit ZAD-L1). A pause or delete committed
  while the report is built stops the mail (the subscription is locked at the hand-over, audit ZAD-I1).
- **Erasure and owner ids (audit ZAD-L2).** `SubscriptionsInterface::forgetOwner()` (or `bin/console
  nowo:report-kit:forget-owner <type> <id>`) deletes the owner's subscriptions, run rows and lock row (exact match).
  Until the UserKit eraser of S4 calls it, **call it when you delete or anonymise a person**. Use a **non-reusable**
  `owner.id_field` (UUID, numeric id): with a reusable one (e-mail, username) a new account that takes the value would
  otherwise see and receive the old owner's subscriptions (built with the new account's access, but showing the old
  owner's stored filters). An unready mailer skips and keeps the
  schedule (Q-O9).
- **Subscriptions are the owner's only.** Lookups by id **and** owner, re-checked with `===` in PHP after SQL (another
  owner's id, `UserA` vs `usera`, `alice ` → 404); CSRF on every POST (FormKit; token id per subscription and action,
  a missing or wrong token answers 403); the version the page showed is posted back (409 when the row changed);
  `subscriptions.max_per_owner` under a per-owner lock row. The demo guest (`allow_unauthenticated`) cannot own
  subscriptions (403). The schedule form re-resolves and re-checks the report parameters; a context the person cannot
  read is a form error, never stored as "all accessible".
- **Identifier columns (REQ-SEC-006).** `id`, `owner_type`, `owner_id`, `report_type`, `subscription_id`,
  `claim_token` and the lock keys get a binary NO PAD collation on MySQL (`utf8mb4_0900_bin`) / MariaDB
  (`utf8mb4_nopad_bin`), `utf8mb4_bin` only as the MySQL 5.7 / partial `server_version` fallback (logged); declared to
  MigrationsKit through `ExactKeyColumnsInterface` (tag `nowo_migrations_kit.exact_key_columns`). Value objects refuse
  ids with edge white space, control or invisible characters; subscription ids are lower-case UUIDs (`/D`-anchored).
- **Logs** carry ids, slots, codes and exception classes only (never an address, parameter value or report content);
  events carry ids, never content or addresses.
- **Remote dialog.** The `X-Nowo-Dialog` header only selects the fragment template and the 204 answer: guard, rate
  limit, CSRF and owner checks run for both modes; both modes vary on the header and are `no-store, private`.

### Report pages access (S2)

- **Two layers (REQ-UI-002).** The host's firewall on `web_ui.path_prefix` and the bundle's guard on every action:
  signed in (else the firewall's entry point), `web_ui.security.access_roles` or the host's
  `ReportKitAccessCheckerInterface` (else 403), an owner reference (else 403). Without SecurityBundle the container
  refuses to build the pages. **`allow_unauthenticated: true` is for demos only**: it opens the pages to one shared
  `guest:anonymous` owner.
- **Scope on every parameter.** `period` must be one of the type's periods (else its default); `context` must be one
  of the contexts the scope resolver returned **and** the access checker granted (exact `type:id` comparison; else "all
  accessible", flagged in the filters line and file name, never an error that tells whether it exists); extras go
  through their `ParameterDefinition` (choice list, int bounds, bool, `Y-m-d`); unknown keys are dropped. The export
  links are rebuilt from the resolved parameters, never copied from the request. The checker's answer is intersected
  with the scope, and a `ReportAccessCheckEvent` grant opens the scope, never more. A type that is not granted is not
  listed and its routes answer 403; an unknown type 404.
- **Zero contexts fail closed (audit ZY-M1).** With a real scope resolver (not `NoContextScopeResolver`), a person it
  gives no context for a type with `context: true` is refused that type (not listed, 403 `NO_CONTEXT` on the page and
  every export), even when the type declares no permission. Only `allowsWithoutContext()` or an explicit listener
  `grant()` opens it, and that build carries `ReportRequest::$contextFreeGrant = true`, so a type never mistakes "this
  person has no project" for "this host has no projects" and returns every project's figures.
- **Limits.** `limits.views` (list and report page) and `limits.exports` per owner (429 + `Retry-After`), counted
  before the build, so refused attempts count too; at most 5,000 scope contexts per build (the list hides such a type,
  the page answers 503); a type's own limit exception answers 503; the page shows the PDF bounds (`pdf_max_rows`,
  `pdf_max_cell`), the files keep `max_rows_per_section`; `render_seconds` and `max_pdf_html_bytes` answer 503.
- **Output.** Every page value is escaped by Twig; charts are SVG images built from numbers and escaped labels; no
  inline script or style; the HTML pages are `no-store, private` like the files. Exports keep the S1 guards (CSV formula prefix, XLSX string cells, closed Dompdf) and the
  `no-store, private` attachment headers.


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
| Resource exhaustion | Row, cell, render-time and HTML-size limits; export and view rate limits in the cache pool; at most 5,000 scope contexts per build | S1, S2 |
| Report pages open to anyone | Firewall rule + bundle guard (roles or host checker); no build without SecurityBundle unless the demo-only `allow_unauthenticated` | S2 |
| Report content on the operator page | Operator sees owner, type, schedule and status only; never report content | S4 |
| Collation (audit LQ, REQ-SEC-006) | Identifier columns binary NO PAD, PHP `===` re-check on every owner- and id-keyed read; export and erasure in S4 | S3 |
| Double or replayed mail | Unique run row per slot, `sending` mark before the hand-over, atomic claim, idempotent handlers | S3 |
| A mail sent before the data is committed | Runner refuses to run inside a transaction; events after commit | S3 |
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
| REQ-SEC-004 AI audit recorded | S1 (ZE, ZG), S2 (ZY); S3 pending: immediate audit before 0.3.0 |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md): report privately to hectorfranco@nowo.tech.
