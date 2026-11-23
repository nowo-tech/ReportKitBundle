# Upgrading

## Table of contents

- [Unreleased (S3, to 0.3.0)](#unreleased-s3-to-030)
- [To 0.2.0](#to-020)
- [To 0.1.0](#to-010)

## Unreleased (S3, to 0.3.0)

From **0.2.0**:

```bash
composer update nowo-tech/report-kit-bundle
```

New required packages: `doctrine/dbal` ^3.9 || ^4, `doctrine/orm` ^3.3 (mapping only), `doctrine/persistence`,
`nowo-tech/transaction-contract` ^1.0, `nowo-tech/transaction-doctrine` ^1.9, `nowo-tech/migrations-kit-contracts` ^1.0,
`psr/log`, `symfony/console`, `symfony/uid`. Suggested: `symfony/mailer`, `symfony/messenger`, `symfony/scheduler` +
`dragonmantank/cron-expression`, `nowo-tech/mail-kit-bundle`, `nowo-tech/migrations-kit-bundle`.

- **`subscriptions.enabled` is now `true | false | "auto"` (default `auto`).** `auto` turns the subscriptions on only
  when DoctrineBundle is registered **and** an owner directory is configured (`owner.directory`, or `owner.class` for
  the Doctrine directory). A 0.2 host with neither keeps working unchanged. `true` without DoctrineBundle or a
  directory now refuses to build (it was a no-op in 0.2). Set `false` to keep the subscriptions off.
- New keys: `owner.directory` was already accepted, now used; `mail.mailer` (null), `mail.from` (null),
  `concurrency.attempts` (3), `concurrency.lock_timeout_ms` (5000), `conflict_response.status` (409),
  `conflict_response.retry_after` (1). The container parameter `nowo_report_kit.subscriptions` says whether they are on.
- **Schema (additive, three new tables)** when the subscriptions are on. Create them with your migration tool (the
  mapping is prepended on `doctrine.entity_manager` on standard hosts): `<prefix>report_subscription`,
  `<prefix>report_run` (unique `(subscription_id, slot_at)`), `<prefix>report_owner_lock`. On MySQL / MariaDB the
  identifier columns use `utf8mb4_0900_bin` / `utf8mb4_nopad_bin` (set `doctrine.dbal.server_version` with the full
  version); nothing to convert, the tables are new. A DDD host with `doctrine.auto_mapping: false` registers
  `src/Resources/config/doctrine` (type `xml`, prefix `Nowo\ReportKitBundle\Domain`) on its entity manager.
- With `web_ui.enabled` and the subscriptions on, the pages need FormKit's CSRF forms (`framework.csrf_protection`) and
  a session; three new routes (`nowo_report_kit_schedule`, `nowo_report_kit_subscription_toggle`,
  `nowo_report_kit_subscription_delete`) under the same prefix: your firewall rule for the prefix covers them.
- `ReportController` now receives the subscriptions service (internal class); `web_ui.templates.index` and `.show`
  overrides get the new variables `subscriptions` (UiKit `Table` or null) and `dialog_id`.
- Turn the runs on with `schedule.enabled: true` and either consume `scheduler_nowo_report_kit` or call
  `nowo:report-kit:dispatch-due` from your scheduler ([USAGE.md](USAGE.md#scheduled-e-mail-subscriptions-s3)). Do not
  wrap `RunScheduledReportMessage` in the `doctrine_transaction` middleware.

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
