# Security

## Table of contents

- [Status](#status)
- [Threat model](#threat-model)
- [Audits](#audits)
- [Reporting](#reporting)

## Status

S0 scaffold: no runtime code, no route, no table. Nothing is reachable whatever the configuration. The configuration
already refuses a PDF or XLSX format forced on without its engine (Q-O3).

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

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md): report privately to hectorfranco@nowo.tech.
