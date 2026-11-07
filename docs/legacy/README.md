# Legacy RightAlly (CodeIgniter 3) — reference only

The legacy platform is **not reused or fixed** (ADR 0009). It stays in maintenance mode until each
existing customer has moved. These notes capture what the new platform must preserve or migrate.

| File                                   | Contents                                                                                     |
| -------------------------------------- | -------------------------------------------------------------------------------------------- |
| [business-rules.md](business-rules.md) | Compensation, revenue share, capping, deduction and ledger rules that become automated tests |
| [schema-mapping.md](schema-mapping.md) | All 146 legacy tables, the new module that owns each concept, and whether its data migrates  |
| [data-migration.md](data-migration.md) | How existing customers move: extract, transform, load, reconcile, parallel run               |
| [lessons.md](lessons.md)               | Legacy security and design problems the new platform must never repeat                       |

Source analysed: legacy repository snapshot and database schema `businessRightallyNew`
(MariaDB 10.11, 146 tables, 0 foreign keys), reviewed in Phase 0.
