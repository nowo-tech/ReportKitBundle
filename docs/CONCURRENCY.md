# Concurrency (REQ-RUNTIME-003)

## Table of contents

- [Write paths](#write-paths)
- [Units of work](#units-of-work)
- [Conflict answers](#conflict-answers)
- [Scheduled runs](#scheduled-runs)
- [Tests](#tests)
- [Configuration](#configuration)

## Write paths

S1 and S2 have no write path of their own (rendering and pages only; the rate-limit counters live in the host's
`limits.cache_pool`). S3 adds three bundle-owned tables, written **only through DBAL** on the connection of
`doctrine.entity_manager` (never an EntityManager flush, clear or reset, REQ-ARCH-001 rule 6):

| Write | Where | Control | Conflict |
| --- | --- | --- | --- |
| Create a subscription | `SubscriptionsInterface::create()` | one transaction: `UPDATE` (or first `INSERT`) of the owner's row in `<prefix>report_owner_lock`, then count, then `INSERT`; concurrent creates of one owner run one after the other, so `subscriptions.max_per_owner` holds on every database and isolation level | lock wait / deadlock → replayed, then `CONFLICT_001` |
| Pause / resume / delete | `setEnabled()`, `delete()` | one transaction: the row `SELECT … FOR UPDATE` (by primary key), the version the owner saw compared, then `UPDATE … WHERE id = ? AND version = ?` (`version = version + 1`) | stale version → `SubscriptionChangedException` (409 `SUBSCRIPTION_CHANGED`, no `X-Conflict-Code`); lock → `CONFLICT_001` |
| Claim a due slot | `DueReportsDispatcher` | one conditional `UPDATE … SET claim_token, claimed_at, next_run_at WHERE id = ? AND enabled AND next_run_at = :slot AND (claim_token IS NULL OR claimed_at < :stale)` per row: exactly one dispatcher wins | the loser matches nothing |
| Take a run slot | `ScheduledReportRunner` | `INSERT` into `<prefix>report_run` with the unique key `(subscription_id, slot_at)` (inside a savepoint); a duplicate only takes over a row left `failed` or with a lease older than `schedule.stale_claim_minutes` (one conditional `UPDATE`) | duplicate → the run stops (`duplicate`) |
| Hand over | runner | the run row moves `running → sending` (conditional `UPDATE … WHERE status = 'running'`) **before** the mailer is called; a `sending` row is never taken again | — |
| Record the outcome | runner | one transaction: the subscription `SELECT … FOR UPDATE`, failure count / pause / last status computed from the locked row, the claim cleared only when it is still this run's token, the run row updated | lock → the message is retried (the slot is `sending`: no second mail) |
| Retention | `RunRetention` | `retention.batch_size` ids per short transaction (keyset by id) | first batch → `CONFLICT_001`; later batch → `CONFLICT_002` (earlier batches stay deleted) |

## Units of work

- One transaction per write unit through transaction-doctrine's `DoctrineTransactionRunner` on the connection
  (savepoint-aware repair after a nested failure, rule 3). On PostgreSQL a transaction the store opens gets
  `SET LOCAL lock_timeout` (`concurrency.lock_timeout_ms`, rule 9).
- Lock conflicts (deadlock, lock wait timeout, serialization failure) are replayed up to `concurrency.attempts` times
  when the store opened the transaction; inside a host transaction nothing is replayed.
- **Joining a host transaction:** a call made while the host holds a transaction on the same connection joins it
  (nested level). Its events wait for the host's outermost commit (DBAL middleware `AfterCommitMiddleware`) and are
  dropped on rollback; held work never survives a request / message boundary (worker mode).
- Events (`ReportSubscriptionChangedEvent`, `ScheduledReport{Sent,Paused,Failed}Event`) are dispatched **after the
  commit**, never inside the unit.
- No HTTP call, mail or file I/O under a row lock: the report is built and rendered before the `sending` mark, the
  mail is handed over between two short transactions, and the outcome transaction does nothing but SQL.

## Conflict answers

`ReportKitConflictListener` (kernel.exception) turns a `ReportKitConflictException` that reaches the kernel into the
policy's HTTP answer (rule 6): **409** by default (`conflict_response.status: 503` per host) with
`X-Conflict-Code: CONFLICT_001` and `Retry-After` (`conflict_response.retry_after`) when nothing was committed, or
`CONFLICT_002` without `Retry-After` when part of the call (or anything earlier in the same request, through
transaction-doctrine's request watermark) was committed. Unique violations, lost connections and other errors are not
mapped. A stale optimistic version is a **domain** conflict: 409 with the body / header code `SUBSCRIPTION_CHANGED`
and a fresh form, without `X-Conflict-Code`.

## Scheduled runs

- **Idempotent per slot** (rule 7): a Messenger redelivery, a double claim or a stale re-claim of the same slot finds
  the run row and stops; the mail is an external side effect done after the `sending` commit and never repeated
  (at most once: a crash between the `sending` commit and the hand-over loses that slot, it never doubles it).
- **Retries:** a failure before the hand-over is recorded (`failed`, lease released, `consecutive_failures + 1`) and
  rethrown, so the transport's retry strategy runs the same message again; at `schedule.max_failures` in a row the
  subscription pauses with `repeated_failure` and nothing is rethrown.
- **Never inside a host transaction:** the runner refuses to run while the connection is in a transaction (Messenger's
  `doctrine_transaction` middleware): the mail would leave before the commit. The handler marks that
  `UnrecoverableMessageHandlingException`.
- Owners and access are re-resolved on every attempt (rule 7: no entity from an earlier attempt).

## Tests

- **Unit (SQLite in memory):** the store's replay, `CONFLICT_001` / `CONFLICT_002`, PostgreSQL `lock_timeout`, the
  owner lock row; subscriptions, claims, runs, retention.
- **Two connections on a real database** (`tests/Integration/Database/ConcurrencyTestCase.php`, both host kinds; CI
  job *Databases*: SQLite file, MySQL 8.4, MariaDB 11.4, PostgreSQL 16): a second PHP process
  (`tests/Fixtures/hold-lock.php`) holds row locks while the bundle runs — two dispatchers claim one row once; a slot
  another worker is starting is not run twice; the cap holds under a concurrent create; a lock wait timeout answers
  `CONFLICT_001` and leaves the connection usable; a timeout in a later retention batch answers `CONFLICT_002`; a pause
  on a stale version is refused.

## Configuration

```yaml
nowo_report_kit:
    concurrency:
        attempts: 3            # tries of a unit the store opened, on lock conflicts
        lock_timeout_ms: 5000  # PostgreSQL lock_timeout of a transaction the store opens (0 = server default)
    conflict_response:
        status: 409            # or 503
        retry_after: 1         # seconds, CONFLICT_001 only
    schedule:
        stale_claim_minutes: 30  # a claim / run lease older than this may be taken over
```
