# Upgrading

## Table of contents

- [Unreleased (S1)](#unreleased-s1)
- [To 0.1.0](#to-010)

## Unreleased (S1)

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
