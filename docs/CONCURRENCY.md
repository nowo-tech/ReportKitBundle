# Concurrency (REQ-RUNTIME-003)

## Table of contents

- [Write paths](#write-paths)
- [Planned controls](#planned-controls)

## Write paths

**S1 has no write path**: no table, no DBAL or ORM write, no lock, no counter and no Messenger handler. Rendering is
read-only over the `Report` it receives; its only side effects are files in a private per-call directory removed in
`finally`, and the Dompdf font cache written at `cache:warmup`. REQ-RUNTIME-003 is therefore **— (not applicable)** for
S1.

**S2 has no write path of its own either.** The pages read through the host's SPIs and render; the only shared state
they touch is the rate-limit counter (`limits.views`, `limits.exports`), kept by Symfony RateLimiter in the host's
`limits.cache_pool`. The limiter uses the host's `lock.factory` when it exists; without a lock store two concurrent
requests of the same person can both pass on the last token (the limit may be exceeded by the number of concurrent
requests, never bypassed). No data is written, so no conflict code applies.

## Planned controls

From S3 (contract §5, §9): one transaction per write unit through the bundle's runner, the atomic due-run claim
(conditional `UPDATE … WHERE claim_token IS NULL OR claimed_at < :stale`), the unique `(subscription_id, slot_at)` run
row so a retry or a double claim never sends twice, side effects (mail) after the commit, 409 + `Retry-After` with
`CONFLICT_001` / `CONFLICT_002`, and two-connection race tests on MySQL, MariaDB and PostgreSQL. This page will hold the
write-path table then.
