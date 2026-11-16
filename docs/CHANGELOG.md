# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
