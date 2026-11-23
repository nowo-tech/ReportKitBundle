# Doctrine XML mapping

The bundle-owned tables are mapped here in XML (contract §5: plain domain classes, referenced by id; the bundle
prepends this mapping on `doctrine.entity_manager` while `doctrine.auto_mapping` is true). The attribute twins in
`src/Infrastructure/Doctrine/Entity` map the same schema (`doctrine.mapping: attribute`).

| File | Table (default prefix `nowo_`) |
| --- | --- |
| `Subscription.orm.xml` | `nowo_report_subscription` |
| `ReportRun.orm.xml` | `nowo_report_run` (unique `(subscription_id, slot_at)`) |
| `SubscriptionOwnerLock.orm.xml` | `nowo_report_owner_lock` (per-owner create lock, REQ-RUNTIME-003) |

The table prefix and the binary NO PAD collation of the identifier columns (MySQL / MariaDB) are applied by
`Nowo\ReportKitBundle\Infrastructure\Doctrine\ReportKitMetadataListener`, on every entity manager. A DDD host with
`doctrine.auto_mapping: false` registers this directory on its own entity manager (prefix
`Nowo\ReportKitBundle\Domain`, type `xml`) and points `doctrine.entity_manager` at it.
