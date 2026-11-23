# Upgrading

## Table of contents

- [Unreleased (S2)](#unreleased-s2)
- [To 0.2.0](#to-020)
- [To 0.1.0](#to-010)

## Unreleased (S2)

## To 0.2.0

From **0.1.0**:

```bash
composer update nowo-tech/report-kit-bundle
```

Additive. New required packages: `nowo-tech/ui-kit-bundle` ^2.68, `nowo-tech/form-kit-bundle` ^2.24,
`symfony/form`, `symfony/rate-limiter`, `symfony/routing`, `symfony/security-core`, `symfony/event-dispatcher`.

- New keys `web_ui.security.access_checker` (null) and `web_ui.security.allow_unauthenticated` (false).
- **A report type that declares a permission now needs `scope.access_checker`**: without it every build of that type is
  refused and `cache:warmup` fails with the list of those types.
- The report pages are off until `web_ui.enabled: true`. Then register UiKit, FormKit and SecurityBundle, import the
  routes and protect the prefix:

  ```yaml
  # config/routes/nowo_report_kit.yaml
  nowo_report_kit:
      resource: .
      type: nowo_report_kit_ui
  # config/packages/security.yaml
  security:
      access_control:
          - { path: ^/reports, roles: ROLE_USER }
  ```

- No schema change (S2 has no table).

## To 0.1.0

First release. Install it with:

```bash
composer require nowo-tech/report-kit-bundle
```

No release yet. The first tag follows [ROADMAP.md](ROADMAP.md). Every schema change in 1.x is additive and will be
listed here with its DDL.

S1 adds the configuration key `temp_dir` (default null) and validates the template names and `owner.class` (audit
ZE-I4): a value that was accepted by the S0 tree but is not a Twig template name or an existing class now refuses to
build.
