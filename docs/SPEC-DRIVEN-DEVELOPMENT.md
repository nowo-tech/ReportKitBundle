# Spec-driven development

This repository follows the Nowo spec-driven workflow:

1. **Design contract** — [CONTRACT.md](CONTRACT.md) (contract 221-C, owner-approved 2026-11-12 with every Q-O answered
   as recommended). It fixes the public ports, persistence, security model, configuration, worker-mode design, tests
   and slices.
2. **Product behaviour** — what **ReportKitBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md),
   [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** enforce
   it in CI.
3. **Baseline** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and the 100 % code inventory
   [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md). Every slice updates both. The
   Spec Kit manual (install, initialise, structure, Cursor usage) is [`SPEC-KIT.md`](SPEC-KIT.md).
4. **Repository REQs** — `BUNDLES_FULL_SPECS_DETAILS.md` (Makefile, CI, demo, docs, security). The bundle reaches 100 %
   of the applicable REQs before 1.0.0 (`bundle-req-audit`), plus the REQ-SEC-004 audit.

## Table of contents

- [User stories (S0)](#user-stories-s0)
- [User stories (S1)](#user-stories-s1)
- [Slices](#slices)

## User stories (S0)

| ID | Story |
| --- | --- |
| US-S0-01 | **As an** integrator, **I want** to install the package and write the final configuration now **so that** later slices only switch features on. |
| US-S0-02 | **As a** maintainer, **I want** every quality gate (tests, coverage 100 %, PHPStan + worker rules, Igor, CS, Rector, demo smoke) from the first PR **so that** no slice lowers the bar. |
| US-S0-03 | **As a** platform maintainer, **I want** a worker-mode test (one kernel, many requests) from S0 **so that** each stateful slice extends it. |

## User stories (S1)

Spec: [`specs/002-s1-model-renderers/spec.md`](../specs/002-s1-model-renderers/spec.md).

| ID | Story |
| --- | --- |
| US-S1-01 | **As a** host developer, **I want** to write a report type once and export it as CSV, XLSX, PDF or JSON **so that** every format shows the same sections. |
| US-S1-02 | **As a** viewer, **I want** a report that asks for a context I cannot read to say it covers all my accessible contexts **so that** I am never told it is about something else (ZE-I3). |
| US-S1-03 | **As a** security reviewer, **I want** the PDF engine closed (no remote, no file, no PHP, no JavaScript) and formulas neutralised in spreadsheets **so that** a report cannot be turned against its reader or the server. |
| US-S1-04 | **As a** billing developer, **I want** the same closed PDF runner for my own templates **so that** there is one audited Dompdf setup (Q-O10). |
| US-S1-05 | **As a** platform maintainer, **I want** a worker export test (500 exports, two owners, two locales) **so that** no temp file, locale or data leaks between requests. |

## Slices

See [ROADMAP.md](ROADMAP.md).
